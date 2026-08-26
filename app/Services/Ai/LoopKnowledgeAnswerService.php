<?php

namespace App\Services\Ai;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\DossierManifestSource;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Events\LoopMessageCreated;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\User;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiUsage;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `loop_knowledge_answer` — reponse documentaire sourcee (TASK-1213 / RAG V1).
 *
 * Un membre pose une question depuis une Boucle. Le service :
 * 1. verifie l'appartenance (Boucle active, meme Organization) ;
 * 2. resout provider/modele/credential de l'Organization (P4, jamais de repli) ;
 * 3. applique la garde economique ;
 * 4. fait construire le contexte par le Context Builder — deux sources
 *    autorisees, tenant- et permission-safe, la MEME `DossierAccessScope` :
 *    `dossier.manifest` (inventaire deterministe, references [Mn], AUCUN
 *    contenu de document) et `dossier.retrieval` (extraits pgvector,
 *    references [Sn]) ;
 * 5. sans AUCUNE provenance — ni manifest ni extrait — : repond « pas trouve
 *    dans mes sources » SANS appeler le modele (aucune hallucination
 *    possible, aucun cout). Des que l'une des deux fournit quelque chose, le
 *    modele est appele : le manifest seul peut repondre a une question
 *    d'inventaire (TASK-1307, revue) ;
 * 6. sinon interroge le SDK (Constitution → capability → AdminAiPrompt), borne
 *    la reponse et ne retient comme sources citees que les references [Mn]
 *    ou [Sn] REELLEMENT presentes dans la provenance fournie — jamais une
 *    reference inventee par le modele, quel que soit son prefixe.
 *
 * TASK-1297 : le chemin n'est PLUS read-only. L'echange est publie dans le
 * fil de la Boucle sur le modele exact de ChatLoopAiService::ask() : la
 * question du membre (type `user`, si `ai.knowledge.publish_question` —
 * reversible en une ligne) puis la reponse documentaire liee (type `ai`,
 * `reply_to_id`, sources publiques en metadata). Rien n'est publie quand rien
 * n'a coute : pas de sources -> aucun appel, aucune interaction, aucun
 * message ; echec provider ou reponse vide -> aucun message, la trace seule.
 * Aucun autre objet metier, une seule trace P1.
 */
class LoopKnowledgeAnswerService
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly ContextBuilder $contextBuilder,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
    ) {}

    /**
     * TASK-1299 : `$inThreadTrigger` est le message HUMAIN deja persiste par
     * le composeur (`/ia` via `LoopChat::sendMessage()`). Fourni, la question
     * n'est PAS re-publiee — elle existe deja dans le fil, la re-ecrire est
     * le piege de la double persistance — et la reponse lui est liee par
     * `reply_to_id`. A null, le chemin T-1 est inchange octet pour octet
     * (modal knowledge, flag `ai.knowledge.publish_question` gouvernant).
     */
    public function answer(Loop $loop, User $requester, string $question, ?LoopMessage $inThreadTrigger = null): KnowledgeAnswer
    {
        $question = trim($question);

        if ($question === '') {
            throw new RuntimeException(__('loops.knowledge_question_required'));
        }

        $this->assertCanRequest($loop, $requester);

        $capability = CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER;
        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

        $organization = $loop->organization()->firstOrFail();

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: (string) $loop->id,
            locale: str_starts_with((string) app()->getLocale(), 'en') ? 'en' : 'fr',
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: $question,
        );

        // P4 : sans configuration IA d'Organization, aucun appel, aucun repli.
        // TASK-1229 : etat « credential absent », code stable, distinct des
        // deux refus economiques ci-dessous.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            throw AiRefusedException::notConfigured($exception);
        }

        // TASK-1229 : le demandeur est passe a la garde — son credit IA du
        // mois (utilisations) s'applique ICI, dans l'autorite existante, avant
        // toute recherche documentaire et toute generation.
        $verdict = $this->economicGuard->authorize(
            $organization,
            $definition->process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.knowledge.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.knowledge.economic_guard.monthly_unknown_limit', 10),
            $requester,
        );

        if (! $verdict->allowed) {
            // Trois etats, trois messages, trois codes : credit utilisateur
            // epuise / budget Organization atteint / autre indisponibilite.
            throw AiRefusedException::fromVerdict($verdict);
        }

        // Le prompt administrable est requis AVANT toute depense (embedding
        // compris) : sans lui, indisponibilite explicite.
        // TASK-1227 : doctrine active de l'Organization composee sous la
        // Constitution ; la regle « repondre depuis les sources [S] » reste
        // appliquee en code (citations revalidees), la doctrine ne peut pas
        // autoriser d'inventer.
        $instructions = $this->prompts->compose($capability, $this->knowledgeInstructions(), (string) $organization->id);
        // TASK-1236 : version de doctrine reellement composee ci-dessus, tracee
        // sur l'interaction enregistree plutot que reconstituee a posteriori.
        $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

        $borne = $this->contextBuilder->build($contexte, $definition);
        // TASK-1307 (revue) : la connaissance disponible est la provenance des
        // DEUX sources autorisees de cette capability — le manifest
        // (existence des elements du Dossier, [Mn]) ET le retrieval (contenu
        // documentaire, [Sn]). Ni l'un ni l'autre n'est privilegie a priori :
        // le manifest seul suffit a une question d'inventaire, le retrieval
        // seul a une question de contenu, les deux ensemble a une question
        // mixte. Le refus ci-dessous ne se declenche que si AUCUNE des deux
        // n'a fourni quoi que ce soit.
        $consulted = [
            ...$borne->provenanceFor(DossierManifestSource::NAME),
            ...$borne->provenanceFor(DossierRetrievalSource::NAME),
        ];

        if ($consulted === []) {
            // Rien de pertinent dans les Dossiers accessibles : on le dit, sans
            // inventer et sans appeler le modele.
            return new KnowledgeAnswer(
                answer: __('loops.knowledge_no_sources'),
                sources: [],
                consulted: [],
                grounded: false,
                interactionId: null,
                // TASK-1229 : la recherche documentaire a pu etre emise (une
                // utilisation reelle) : le credit se lit ici aussi.
                credit: $this->economicGuard->userCreditStatus($organization, $requester),
            );
        }

        $agent = new LoopKnowledgeAgent(
            $instructions,
            (int) config('ai.knowledge.max_tokens', 700),
            (float) config('ai.knowledge.temperature', 0.2),
        );

        $startedAt = microtime(true);
        // TASK-1300 : sur une continuation, l'echange precedent — borne —
        // s'insere entre les sources et la question ; partout ailleurs le
        // prompt T-3 est inchange octet pour octet.
        $thread = $this->threadContext($inThreadTrigger);
        $prompt = $borne->text
            .($thread === '' ? '' : "\n\n".$thread)
            ."\n\nQuestion du membre :\n".$question;

        try {
            $response = $agent->prompt(
                $prompt,
                provider: $resolved->instance,
                model: $resolved->model,
            );
        } catch (\Throwable $exception) {
            $this->recordInteraction($loop, $requester, $contexte, $definition, $resolved, $prompt, null,
                AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null], null, 'failed', $startedAt, null,
                $exception::class, $consulted, [], $doctrineVersion);

            throw new RuntimeException(__('loops.ai_error'), 0, $exception);
        }

        $answer = AiMarkdownSanitizer::sanitize(
            (string) $response->text,
            (int) config('ai.knowledge.max_answer_chars', 3000),
        );

        if ($answer === '') {
            throw new RuntimeException(__('loops.ai_empty_response'));
        }

        $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        // Citations : uniquement les references ([Mn] ou [Sn]) presentes
        // dans la provenance REELLEMENT fournie. Une reference inventee est
        // ignoree.
        $cited = $this->citedSources($answer, $consulted);

        $interaction = $this->recordInteraction($loop, $requester, $contexte, $definition, $resolved, $prompt,
            $answer, $usage, $cost->traceAttributes(), $cost, 'success', $startedAt, $response->invocationId, null,
            $consulted, $cited, $doctrineVersion);

        $sources = $cited !== [] ? $cited : $consulted;

        $this->publishExchange($loop, $requester, $question, $answer, $resolved, $interaction, $cited, $sources, $inThreadTrigger);

        return new KnowledgeAnswer(
            answer: $answer,
            sources: $sources,
            consulted: $consulted,
            grounded: $cited !== [],
            interactionId: $interaction->id,
            // TASK-1229 : le credit APRES cette reponse (recherche + generation
            // decomptees) — l'alerte de seuil se lit ici, l'action n'a pas
            // ete bloquee.
            credit: $this->economicGuard->userCreditStatus($organization, $requester),
        );
    }

    /**
     * TASK-1300 : profondeur maximale de remontee de la chaine reply_to_id
     * pour le contexte d'une continuation — SIX messages, soit trois tours
     * d'echange membre/IA.
     *
     * Pourquoi six : le « minimum utile » du brief T-4 tient en UN tour (la
     * reponse IA continuee et la question humaine qui l'avait produite) ;
     * trois tours couvrent une conversation de deux continuations avec une
     * marge d'un tour, sans que les tours anterieurs — deja resumes de fait
     * par la derniere reponse IA — ne gonflent le prompt : la connaissance
     * vient du RAG Loop-scoped, pas du fil. Pourquoi une constante et pas
     * une config : le brief impose les bornes EXISTANTES, on n'ouvre pas une
     * surface de reglage que personne n'a demande a regler (arbitrage Cyril
     * 24/08). La borne de CARACTERES, elle, reste la borne existante
     * `ai.chatloop.max_context_chars`. Ce plafond garantit qu'une chaine de
     * quarante continuations ne produit jamais un prompt de quarante tours —
     * ni meme quarante lectures : la remontee s'arrete la, absolument.
     */
    private const MAX_THREAD_DEPTH = 6;

    /**
     * TASK-1300 : le contexte de continuation — l'echange precedent, remonte
     * par la chaine `reply_to_id` depuis le declencheur, BORNE en profondeur
     * (MAX_THREAD_DEPTH) et en caracteres (`ai.chatloop.max_context_chars`,
     * le plus ancien coupe d'abord, le parent direct toujours conserve,
     * tronque au besoin).
     *
     * Chaine vide (declencheur sans reply, `/ia` simple, chemin modal T-1)
     * ou parent qui n'est pas un message IA visible : aucun contexte — le
     * prompt T-3 reste inchange sur ces chemins. Seuls les types `user` et
     * `ai` entrent au transcript ; un maillon d'un autre type arrete la
     * remontee, un maillon supprime est saute (son slot de profondeur est
     * consomme : la borne reste un nombre de LECTURES, pas de lignes).
     */
    private function threadContext(?LoopMessage $trigger): string
    {
        $parent = $trigger?->replyTo;

        if ($parent === null || $parent->type !== 'ai' || $parent->isDeleted()) {
            return '';
        }

        $budget = (int) config('ai.chatloop.max_context_chars', 12000);
        $lines = [];
        $total = 0;
        $current = $parent;

        for ($depth = 0; $depth < self::MAX_THREAD_DEPTH && $current !== null; $depth++) {
            if (! in_array($current->type, ['user', 'ai'], true)) {
                break;
            }

            if (! $current->isDeleted()) {
                $line = ($current->type === 'ai' ? 'BouclePro : ' : 'Membre : ').trim((string) $current->body);

                if ($lines === []) {
                    $line = mb_substr($line, 0, $budget);
                    $lines[] = $line;
                    $total = mb_strlen($line);
                } elseif ($total + mb_strlen($line) + 1 <= $budget) {
                    $lines[] = $line;
                    $total += mb_strlen($line) + 1;
                } else {
                    break;
                }
            }

            $current = $current->replyTo;
        }

        if ($lines === []) {
            return '';
        }

        return "Echange precedent dans la Boucle :\n".implode("\n", array_reverse($lines));
    }

    /**
     * TASK-1297 : publication de l'echange dans le fil, calquee sur
     * ChatLoopAiService::ask() — la question du membre (type `user`) puis la
     * reponse (type `ai`) liee par `reply_to_id`, sender_id null,
     * organization_id de la Boucle, sources publiques et provenance en
     * metadata. N'est appelee QU'APRES une generation reussie et sa trace :
     * un refus, un echec provider ou une reponse vide n'arrivent jamais ici.
     *
     * @param  list<array<string, mixed>>  $cited
     * @param  list<array<string, mixed>>  $sources
     */
    private function publishExchange(
        Loop $loop,
        User $requester,
        string $question,
        string $answer,
        ResolvedModel $resolved,
        AiInteraction $interaction,
        array $cited,
        array $sources,
        ?LoopMessage $inThreadTrigger = null,
    ): void {
        DB::transaction(function () use ($loop, $requester, $question, $answer, $resolved, $interaction, $cited, $sources, $inThreadTrigger): void {
            $questionMessage = null;

            // La ligne de reversibilite (gouvernance 24/08) : false = seule la
            // reponse est publiee, la question restant en metadata. Un
            // declencheur dans le fil (TASK-1299) rend la question deja
            // publiee PAR SON AUTEUR : rien a re-ecrire, le flag est sans
            // objet sur ce chemin.
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
                'body' => $answer,
                'image_path' => null,
                'type' => 'ai',
                'metadata' => [
                    'requested_by' => $requester->id,
                    // TASK-1300 : la provenance persistee du declencheur est
                    // la source de verite — `slash_ia` pour une invocation
                    // explicite, `continuation` pour une reponse au fil.
                    'action' => $inThreadTrigger === null
                        ? 'knowledge'
                        : (($inThreadTrigger->metadata['slash_ia'] ?? false) ? 'slash_ia' : 'continuation'),
                    'question' => $question,
                    'grounded' => $cited !== [],
                    'sources' => array_map(KnowledgeAnswer::publicSource(...), $sources),
                    'provider' => $resolved->provider,
                    'model' => $resolved->model,
                    'ai_interaction_id' => $interaction->id,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();
        });
    }

    /**
     * TASK-1307 (revue) : ne suppose JAMAIS la forme d'une reference — pas de
     * regex `[S\d+]` qui « croirait » que toute source commence par S. Pour
     * chaque entree REELLEMENT fournie (manifest [Mn] ou retrieval [Sn]), on
     * verifie si son propre marqueur `[ref]` apparait litteralement dans la
     * reponse. La propriete de securite est la meme qu'avant, garantie
     * autrement : une reference que le modele invente ne correspond a AUCUNE
     * entree de `$consulted`, donc ne peut jamais devenir une source publique
     * — quel que soit son prefixe.
     *
     * @param  list<array<string, mixed>>  $consulted
     * @return list<array<string, mixed>>
     */
    private function citedSources(string $answer, array $consulted): array
    {
        return array_values(array_filter(
            $consulted,
            static function (array $source) use ($answer): bool {
                $ref = $source['ref'] ?? null;

                return $ref !== null && str_contains($answer, '['.$ref.']');
            },
        ));
    }

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

    /**
     * Instruction capability chargee depuis la source editable ; son absence
     * est une indisponibilite explicite (aucun prompt metier hardcode).
     */
    private function knowledgeInstructions(): string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', 'loop_knowledge_answer')
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null || trim((string) $prompt->prompt_text) === '') {
            throw new RuntimeException(__('loops.knowledge_prompt_missing'));
        }

        return (string) $prompt->prompt_text;
    }

    /**
     * @param  array{cost_usd: ?float, cost_unknown: ?bool}  $costAttributes
     * @param  list<array<string, mixed>>  $consulted
     * @param  list<array<string, mixed>>  $cited
     */
    private function recordInteraction(
        Loop $loop,
        User $requester,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $prompt,
        ?string $response,
        AiUsage $usage,
        array $costAttributes,
        ?AiCost $cost,
        string $status,
        float $startedAt,
        ?string $sdkInvocationId,
        ?string $failure,
        array $consulted,
        array $cited,
        ?int $doctrineVersion,
    ): AiInteraction {
        // TASK-1220 : ligne canonique du ledger, memes points que la trace P1
        // (succes ET echec) ; les refus pre-provider n'arrivent jamais ici.
        $this->ledger->recordGeneration(
            organizationId: $contexte->organizationId,
            userId: (string) $requester->id,
            capability: $definition->id,
            process: $definition->process,
            resolved: $resolved,
            usage: $usage,
            cost: $cost,
            status: $status,
            correlationId: $contexte->correlationId,
            sdkInvocationId: $sdkInvocationId,
            failureReason: $failure,
            startedAtMicrotime: $startedAt,
        );

        $ids = static fn (array $sources): array => array_values(array_map(
            static fn (array $s): array => ['chunk_id' => $s['chunk_id'] ?? null, 'dossier_id' => $s['dossier_id'] ?? null, 'blog_post_id' => $s['blog_post_id'] ?? null],
            $sources,
        ));

        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            'feature' => 'loop_knowledge_answer',
            'model' => $resolved->trace(),
            'prompt' => $prompt,
            'response' => $response,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$costAttributes,
            'metadata' => array_filter([
                'loop_id' => $loop->id,
                'requested_by' => $requester->id,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'provider' => $resolved->provider,
                'capability' => $definition->id,
                'status' => $status,
                'sdk_invocation_id' => $sdkInvocationId,
                'failure' => $failure,
                'retrieval' => ['consulted' => $ids($consulted), 'cited' => $ids($cited)],
            ], static fn ($value): bool => $value !== null)
                // TASK-1236 : cle toujours presente, meme a null (aucune doctrine
                // active) — sa PRESENCE distingue une interaction tracee d'une
                // ligne anterieure au mecanisme, ce qu'un array_filter effacerait.
                + ['doctrine_version' => $doctrineVersion],
        ]);
    }
}
