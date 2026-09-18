<?php

namespace App\Services\Loops;

use App\Events\LoopMessageCreated;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\User;
use App\Services\Ai\AiConversationContextBuilder;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Services\Dossiers\DossierInsightsService;
use App\Support\Ai\AiConversationTrace;
use App\Support\Ai\AiExecutionPath;
use App\Support\Ai\AiTurnIdempotency;
use App\Support\Ai\AiTurnLock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TASK-1595 — « Consulter les Dossiers » repond par le MEME moteur que
 * « Posez une question a ce Dossier ».
 *
 * ## Ce que cette classe est
 *
 * Un ADAPTATEUR, et rien d'autre. Elle ne cherche pas, ne compose aucun
 * prompt, n'appelle aucun provider et ne decide d'aucune source : tout cela
 * appartient a `DossierInsightsService`, moteur documentaire canonique du
 * produit. Elle fait trois choses, dans cet ordre :
 *
 *   1. resoudre le Dossier RACINE de la Boucle, en lecture seule ;
 *   2. lui passer la question ;
 *   3. publier la reponse dans le fil, avec le contrat de bulle inchange.
 *
 * ## Le perimetre est le Dossier racine, et c'est une DECISION
 *
 * Le chemin precedent (`LoopKnowledgeAnswerService`) interrogeait, via
 * `DossierAccessScope`, l'union « Dossier racine ∪ Dossiers partages a la
 * Boucle ∪ leurs descendants ». Ce service ne retient que la racine.
 *
 * Ce n'est pas une simplification d'implementation qui se serait trouvee en
 * chemin : c'est l'hypothese produit assumee — une Boucle, un Dossier utile
 * au RAG. Les Dossiers partages et les sous-Dossiers CESSENT d'etre
 * interroges par cette action. Le mecanisme reste entier pour les autres
 * chemins qui l'utilisent (mode « IA + Dossiers », Shell) : rien n'est
 * supprime, seul CE chemin cesse d'y passer.
 *
 * ## Lecture seule, jamais de creation
 *
 * `LoopRootDocumentService::ensureRootDossier()` est l'autorite d'ECRITURE du
 * Dossier racine, et elle cree quand il manque. L'appeler ici ferait naitre un
 * Dossier au premier message documentaire d'une Boucle qui n'en a pas —
 * une ecriture declenchee par une lecture. On requete donc, et une absence se
 * dit : aucune source, aucun appel provider, aucune depense.
 *
 * ## Ce qui n'a pas change
 *
 * Le verrou (`AiTurnLock`, reentrant avec celui du composeur), la garde
 * d'appartenance active, l'idempotence du declencheur, le contrat de metadata
 * de la bulle — `ai_mode`, `action`, `ai_interaction_id` — et la regle
 * « rien n'a coute, rien n'est publie ».
 */
class LoopDossierAnswerService
{
    public function __construct(
        private readonly DossierInsightsService $insights,
        private readonly AiConversationContextBuilder $conversationContext,
    ) {}

    /**
     * Repond a une question documentaire posee depuis une Boucle.
     *
     * `$publish` a false : le tour est REELLEMENT execute (recherche,
     * generation, trace, cout) et aucune bulle n'est publiee. C'est le mode de
     * l'Inspector et du Lab, qui observent le chemin produit sans ecrire dans
     * le fil de personne.
     *
     * @throws RuntimeException question vide, non-membre, tour deja repondu,
     *                          refus economique ou panne provider
     */
    public function answer(
        Loop $loop,
        User $requester,
        string $question,
        ?LoopMessage $inThreadTrigger = null,
        bool $publish = true,
    ): KnowledgeAnswer {
        $question = trim($question);

        if ($question === '') {
            throw new RuntimeException(__('loops.knowledge_question_required'));
        }

        $this->assertCanRequest($loop, $requester);

        // Le REJEU d'un tour deja repondu (TASK-1311) : la table fait foi, pas
        // un cache. Meme garde, meme message qu'avant la bascule.
        AiTurnIdempotency::assertNotAnswered($inThreadTrigger);

        // Le verrou englobe l'acte economique ENTIER — recherche, generation,
        // trace, publication —, exactement comme le chemin precedent. Il est
        // reentrant : le composeur l'a deja pris avant de publier le message
        // humain, cette prise-ci le reprend sans echouer sur elle-meme, et
        // c'est elle qui protege les appels qui ne passent pas par l'UI.
        return AiTurnLock::run(
            $loop,
            $requester,
            fn (): KnowledgeAnswer => $this->answerUnderLock($loop, $requester, $question, $inThreadTrigger, $publish),
        );
    }

    private function answerUnderLock(
        Loop $loop,
        User $requester,
        string $question,
        ?LoopMessage $inThreadTrigger,
        bool $publish,
    ): KnowledgeAnswer {
        $organization = $loop->organization;

        if ($organization === null) {
            throw new RuntimeException(__('loops.cross_organization'));
        }

        $dossier = $this->rootDossier($loop);

        if ($dossier === null) {
            // Une Boucle sans Dossier racine n'a rien a consulter. C'est une
            // REPONSE, pas une panne : aucun appel, aucune interaction, aucune
            // bulle — le message humain reste dans le fil et son auteur seul
            // est prevenu, comme pour un zero source.
            return new KnowledgeAnswer(
                answer: __('loops.knowledge_no_sources'),
                sources: [],
                consulted: [],
                grounded: false,
                interactionId: null,
            );
        }

        // La chaine de reply du fil, CONSERVEE a l'identique : repondre a une
        // bulle Dossiers emporte toujours le contexte du fil. Le moteur
        // canonique sait recevoir cette memoire — c'est deja par la que le
        // Shell lui passe la sienne — donc il n'y a rien a reinventer, et
        // l'etape `conversation_history` reste `executed` comme avant.
        $conversation = $this->conversationContext->build($inThreadTrigger);

        $history = [
            'strategy' => AiConversationTrace::STRATEGY_REPLY_CHAIN,
            'message_ids' => $conversation->messageIds,
            'count' => count($conversation->messageIds),
            'chars' => $conversation->chars,
            // Le message AUQUEL l'utilisateur repondait, jamais le message
            // courant (CDC-01 P0.12).
            'trigger_id' => $inThreadTrigger?->reply_to_id,
            'budget_exhausted' => $conversation->budgetExhausted,
            'input_message_id' => $inThreadTrigger?->id,
        ];

        $reponse = $this->insights->answer(
            $organization,
            $dossier,
            $requester,
            $question,
            conversationMemory: $conversation->text === '' ? null : $conversation->text,
            history: $history,
            // TASK-1568 / C15 — le point d'entree NOMME son chemin. Le moteur
            // en sert cinq et ne peut pas deviner lequel l'appelle.
            executionPath: AiExecutionPath::LOOP_CHAT_DOSSIERS,
            // Et il nomme aussi sa Boucle : le moteur ne la devine pas non plus
            // (les pages Dossier n'en ont aucune). Elle n'ouvre aucun perimetre
            // — elle rend seulement le tour rattachable a sa Boucle dans la
            // trace, comme avant la bascule.
            loopId: (string) $loop->id,
            surface: 'loop_chat',
            mode: 'dossiers',
            // Ce chemin est TERMINAL : quand il s'abstient, personne ne prend
            // le relais. Son abstention doit donc laisser un tour.
            traceAbstention: true,
        );

        // Zero source exploitable : le moteur l'a dit sans appeler le provider
        // et sans ecrire d'interaction. Rien n'a coute, donc rien n'est publie
        // (principe T-1, inchange). Pas de repli vers l'IA generale, pas de
        // recherche dans un autre Dossier, pas de retour a l'ancien pipeline.
        if ($reponse->interactionId === null) {
            return $reponse;
        }

        if ($publish) {
            $this->publishExchange($loop, $requester, $question, $reponse, $inThreadTrigger, $conversation->messageIds);
        }

        return $reponse;
    }

    /**
     * Le Dossier racine de la Boucle, en LECTURE SEULE.
     *
     * `loop_id` est la seule colonne qui designe une racine : un enfant ne la
     * porte pas (doctrine T1130), un Dossier partage porte
     * `shared_with_loop_id`. La requete ne peut donc pas rendre autre chose
     * qu'une racine, et l'invariant « une Boucle, au plus une racine » est
     * tenu a l'ecriture par `LoopRootDocumentService`.
     */
    private function rootDossier(Loop $loop): ?Dossier
    {
        return Dossier::query()
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Publie la reponse dans le fil.
     *
     * Le contrat de bulle est celui du chemin precedent, a l'identique. Deux
     * points meritent d'etre dits plutot que relus :
     *
     * - `ai_interaction_id` est le SEUL lien par lequel l'Inspector et le Lab
     *   retrouvent la bulle d'un tour (`AiTurnExecutor`). Le perdre ne casse
     *   rien de visible et casse toute l'observabilite ;
     * - la question n'est jamais republiee : depuis le composeur, le
     *   declencheur EST le message humain de l'auteur.
     */
    private function publishExchange(
        Loop $loop,
        User $requester,
        string $question,
        KnowledgeAnswer $reponse,
        ?LoopMessage $inThreadTrigger,
        array $contextMessageIds,
    ): void {
        $consultedForDisplay = $this->consultedForDisplay($reponse);

        DB::transaction(function () use ($loop, $requester, $question, $reponse, $inThreadTrigger, $consultedForDisplay, $contextMessageIds): void {
            $questionMessage = null;

            // La ligne de reversibilite (gouvernance 24/08), conservee telle
            // quelle : sans declencheur dans le fil, la question du membre est
            // publiee si la configuration le demande.
            if ($inThreadTrigger === null && (bool) config('ai.knowledge.publish_question', true)) {
                $questionMessage = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => $requester->id,
                    'reply_to_id' => null,
                    'body' => $question,
                    'image_path' => null,
                    'type' => 'user',
                    'metadata' => [
                        'asked_knowledge_question' => true,
                    ],
                    'organization_id' => $loop->organization_id,
                ]);
            }

            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => null,
                'reply_to_id' => $inThreadTrigger?->id ?? $questionMessage?->id,
                'body' => $reponse->answer,
                'image_path' => null,
                'type' => 'ai',
                'metadata' => [
                    'requested_by' => $requester->id,
                    // `ai_mode` reste le discriminant canonique de l'identite
                    // de bulle (TASK-1308) : le moteur a change, l'identite que
                    // le membre lit — « {Organization} · Dossiers » — non.
                    'ai_mode' => 'rag',
                    'action' => $inThreadTrigger === null ? 'knowledge' : 'dossiers',
                    'question' => $question,
                    'grounded' => $reponse->grounded,
                    'sources' => array_map(KnowledgeAnswer::publicSource(...), $reponse->sources),
                    // Les documents dont le CONTENU a ete lu sans qu'aucune
                    // citation valide n'en sorte, et seulement dans ce cas
                    // (TASK-1309). La cle reste absente autrement.
                    ...($consultedForDisplay === [] ? [] : [
                        'consulted' => array_map(KnowledgeAnswer::publicSource(...), $consultedForDisplay),
                    ]),
                    // TASK-1595 — les questions d'approfondissement, produites
                    // DANS le meme tour provider que la reponse (jamais un
                    // second appel). Cle absente quand il n'y en a pas : la
                    // forme de la metadata ne change pas pour autant.
                    ...($reponse->followUps === [] ? [] : [
                        'follow_up_questions' => $reponse->followUps,
                    ]),
                    'provider' => $reponse->provider,
                    'model' => $reponse->model,
                    'ai_interaction_id' => $reponse->interactionId,
                    'context_message_ids' => $contextMessageIds,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();
        });
    }

    /**
     * Ce qu'on montre quand RIEN n'est cite (TASK-1309).
     *
     * Un modele peut repondre en s'appuyant reellement sur un extrait sans
     * ecrire son marqueur `[Sn]`. Appliquee seule, la regle « sources utilisees
     * = sources citees » faisait alors disparaitre TOUTE provenance : le membre
     * n'avait plus rien a verifier.
     *
     * Sur ce chemin, toutes les entrees consultees sont des extraits de
     * document — il n'y a plus de manifest a exclure. Le filtre sur la source
     * reste ecrit : il dit ce qu'on accepte de montrer, et une entree d'une
     * autre nature qui apparaitrait un jour ne s'y glisserait pas en silence.
     *
     * @return list<array<string, mixed>>
     */
    private function consultedForDisplay(KnowledgeAnswer $reponse): array
    {
        if ($reponse->sources !== []) {
            return [];
        }

        return array_values(array_filter(
            $reponse->consulted,
            static fn (array $source): bool => ($source['source'] ?? null) === DossierInsightsService::SOURCE_NAME,
        ));
    }

    /**
     * Les deux memes gardes qu'avant la bascule : appartenance ACTIVE a la
     * Boucle, et frontiere d'Organization. `DossierInsightsService` revalide
     * ensuite l'acces au Dossier lui-meme — une garde qui vit chez UN appelant
     * ne protege pas les autres.
     */
    private function assertCanRequest(Loop $loop, User $requester): void
    {
        $membership = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $requester->id)
            ->where('status', 'active')
            ->exists();

        if (! $membership) {
            throw new RuntimeException(__('loops.not_an_active_member'));
        }

        if ($loop->organization_id !== $requester->organization_id) {
            throw new RuntimeException(__('loops.cross_organization'));
        }
    }
}
