<?php

namespace App\Services\Ai;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\ContexteBorne;
use App\Ai\Context\DossierManifestSource;
use App\Ai\Context\DossierRetrievalSource;
use App\Ai\Context\DossierRetrievalTraceRecorder;
use App\Ai\Context\KnowledgeDeltaSource;
use App\Ai\ContexteIa;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Ai\ResolvedModel;
use App\Events\LoopMessageCreated;
use App\Listeners\RecordSdkEmbeddingsInvocation;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\DTO\KnowledgeAnswer;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiTurnIdempotency;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiTurnReason;
use App\Support\Ai\AiTurnState;
use App\Support\Ai\AiTurnTrace;
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
 *
 * TASK-1309 : ce service porte DEUX moteurs documentaires, et un seul corps.
 * `answer()` = mode Dossiers (grounding strict, refus possible) ;
 * `answerHybrid()` = mode « IA + Dossiers » (capability `loop_hybrid_answer`,
 * prompt dedie) qui peut repondre depuis la connaissance generale du modele
 * quand les Dossiers ne fournissent rien, en le disant. Tout le reste — garde
 * d'appartenance, resolution P4, garde economique, Context Builder,
 * validation des citations, ledger, trace, publication — est le MEME code :
 * deux services separes auraient laisse ces regles diverger.
 */
class LoopKnowledgeAnswerService
{
    /**
     * TASK-1309 : les deux moteurs documentaires de ce service. Le mode n'est
     * jamais lu depuis une requete : il est choisi par l'appelant, en dur, a
     * l'entree publique correspondante.
     */
    private const MODE_DOSSIERS = 'dossiers';

    private const MODE_HYBRID = 'ia_dossiers';

    /**
     * TASK-1309 : ce que le mode IA + Dossiers met a la place du bloc de
     * sources quand les Dossiers accessibles n'ont RIEN fourni. Ce n'est pas
     * une source : c'est le constat de leur silence, dit au modele pour qu'il
     * le dise a l'utilisateur au lieu de le masquer.
     */
    private const HYBRID_NO_DOCUMENTARY_SOURCE_NOTICE =
        "--- SOURCES DOCUMENTAIRES ---\n"
        .'Aucun element des Dossiers accessibles de cette Boucle ne correspond a cette question : '
        .'il n\'y a AUCUNE reference [Mn] ni [Sn] disponible pour cette reponse.';

    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly ContextBuilder $contextBuilder,
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
        private readonly AiConversationContextBuilder $conversationContext,
    ) {}

    /**
     * TASK-1309 : mode Dossiers — grounding documentaire STRICT. Sans aucune
     * provenance, il refuse SANS appeler le modele : c'est sa valeur, pas une
     * limite.
     *
     * TASK-1299 : `$inThreadTrigger` est le message HUMAIN deja persiste par
     * le composeur (`LoopChat::sendMessage()`). Fourni, la question n'est PAS
     * re-publiee — elle existe deja dans le fil, la re-ecrire est le piege de
     * la double persistance — et la reponse lui est liee par `reply_to_id`. A
     * null, le chemin T-1 est inchange octet pour octet (modal knowledge,
     * flag `ai.knowledge.publish_question` gouvernant).
     */
    public function answer(Loop $loop, User $requester, string $question, ?LoopMessage $inThreadTrigger = null, bool $publish = true, ?string $executionPath = null): KnowledgeAnswer
    {
        return $this->respond(self::MODE_DOSSIERS, $loop, $requester, $question, $inThreadTrigger, $publish, $executionPath);
    }

    /**
     * TASK-1309 : mode « IA + Dossiers » — reponse CROISEE.
     *
     * Meme chaine, meme perimetre, meme garde economique, meme ledger que
     * `answer()`. UNE seule difference de comportement, et elle est le coeur
     * du mode : l'absence de provenance documentaire n'est PAS un refus. Le
     * modele repond alors depuis sa connaissance generale, en disant que les
     * Dossiers accessibles n'ont rien apporte — jamais en habillant cette
     * connaissance generale d'une reference [Mn]/[Sn].
     */
    public function answerHybrid(Loop $loop, User $requester, string $question, ?LoopMessage $inThreadTrigger = null, bool $publish = true, ?string $executionPath = null): KnowledgeAnswer
    {
        return $this->respond(self::MODE_HYBRID, $loop, $requester, $question, $inThreadTrigger, $publish, $executionPath);
    }

    /**
     * Le chemin PARTAGE des deux modes (TASK-1309). Un seul corps : la garde
     * d'appartenance, la resolution P4, la garde economique, le Context
     * Builder, la validation de citations, le ledger, la trace et la
     * publication ne peuvent pas diverger entre Dossiers et IA + Dossiers.
     */
    private function respond(string $mode, Loop $loop, User $requester, string $question, ?LoopMessage $inThreadTrigger, bool $publish = true, ?string $executionPath = null): KnowledgeAnswer
    {
        $question = trim($question);

        if ($question === '') {
            throw new RuntimeException(__('loops.knowledge_question_required'));
        }

        $this->assertCanRequest($loop, $requester);

        // TASK-1311 : le REJEU d'un tour deja repondu. Lit la table, pas le
        // cache : tant que la reponse existe dans le fil, le tour est clos —
        // aucun TTL, aucune fenetre de temps a esperer.
        AiTurnIdempotency::assertNotAnswered($inThreadTrigger);

        // TASK-1311 : la COURSE. Ce chemin est le plus CHER de tous — un tour
        // documentaire paie un embedding de requete PUIS une generation, la ou
        // le mode IA ne paie qu'une generation. Un double envoi y coutait donc
        // QUATRE invocations, et il n'avait jamais eu de verrou : ce n'etait pas
        // une decision d'architecture, seulement une garde jamais reportee
        // depuis `ChatLoopAiService`.
        //
        // Le verrou englobe l'acte economique ENTIER — recherche documentaire,
        // generation, trace de l'interaction, publication dans le fil. Le poser
        // plus bas ne protegerait que la moitie de la depense.
        //
        // Un seul point d'ancrage couvre les DEUX modes : `respond()` est le
        // corps partage de `answer()` (Dossiers) et `answerHybrid()`
        // (IA + Dossiers).
        return AiTurnLock::run(
            $loop,
            $requester,
            fn (): KnowledgeAnswer => $this->generateUnderLock($mode, $loop, $requester, $question, $inThreadTrigger, $publish, $executionPath),
        );
    }

    /**
     * Le corps economique du tour, execute SOUS VERROU (TASK-1311).
     *
     * Extrait tel quel de `respond()` : aucune ligne de logique n'a change, le
     * seul but de la separation est que le verrou puisse englober exactement
     * cet acte-la, sans re-indenter deux cents lignes pour le prouver.
     */
    private function generateUnderLock(string $mode, Loop $loop, User $requester, string $question, ?LoopMessage $inThreadTrigger, bool $publish = true, ?string $executionPath = null): KnowledgeAnswer
    {
        $capability = $mode === self::MODE_HYBRID
            ? CapabilityRegistry::LOOP_HYBRID_ANSWER
            : CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER;
        $definition = $this->capabilities->get($capability);
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

        $organization = $loop->organization()->firstOrFail();

        // TASK-1400 — la langue de la reponse appartient a l'Organization.
        //
        // Une reponse aux Dossiers est relue par tout le cercle : la faire
        // suivre la langue de qui a pose la question donnerait deux langues au
        // meme fil selon le lecteur. C'est l'arbitrage generique du 04/09, deja
        // applique par TASK-1388, TASK-1390 et TASK-1398 ; ce chemin etait le
        // dernier a lire encore `app()->getLocale()`.
        $locale = $this->localeDeReference($organization);

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: (string) $loop->id,
            locale: $locale,
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL,
            query: $question,
        );

        // TASK-1566 / CDC-01 V0-A — ce chemin est le PRODUCTEUR PILOTE du bloc
        // `turn`. Il depose ce qu'il traverse au fur et a mesure ; le writer
        // unique (`recordInteraction`) reclame le tout et persiste. Aucun de ces
        // depots ne change quoi que ce soit au comportement : coupes, ils sont
        // inertes et la reponse est identique (garde de non-dependance).
        //
        // TASK-1568 / V0-G — `execution_path` est FOURNI PAR L'APPELANT (C15).
        // Ce moteur a trois points d'entree (`LoopChat`, `LoopController`,
        // `ai:inspect-turn`) et ne peut pas savoir lequel l'a appele : V0-A le
        // derivait du seul `$mode`, et l'endpoint JSON etait trace
        // `loop_chat.dossiers` — plausible, faux. A `null`, `identity()`
        // n'ecrit rien : la cle est absente et se lit `UNAVAILABLE`, jamais un
        // defaut qui se lirait comme une mesure.
        //
        // `surface` et `mode` restent ecrits par le moteur : ce chemin est
        // toujours une surface LoopChat (l'endpoint JSON sert la modale de la
        // Boucle) et le mode est le sien. Aucune des deux valeurs n'est
        // deduite du chemin.
        AiTurnTrace::identity($contexte->organizationId, $contexte->turnId, [
            'surface' => 'loop_chat',
            'mode' => $mode,
            'execution_path' => $executionPath,
            'capability' => $capability,
        ]);

        // P4 : sans configuration IA d'Organization, aucun appel, aucun repli.
        // TASK-1229 : etat « credential absent », code stable, distinct des
        // deux refus economiques ci-dessous.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (DomainException $exception) {
            // TASK-1570 / CDC-01 V0-B — un refus de resolution laisse un TOUR.
            // Aucun modele resolu, aucun appel, aucune ligne au ledger (I3) :
            // seulement la ligne non generative qui dit ou et pourquoi le tour
            // s'est arrete. Le refus rendu au membre est inchange.
            $this->recordEarlyStop($loop, $requester, $contexte, $definition, null,
                AiTurnState::TURN_REFUSED, 'provider_resolution', AiTurnReason::REFUSED_NOT_CONFIGURED, null, [], null);

            throw AiRefusedException::notConfigured($exception);
        }

        AiTurnTrace::identity($contexte->organizationId, $contexte->turnId, [
            // `provider_requested` n'est PAS ecrit : `ResolvedModel` ne porte
            // que ce qui a ete resolu, et le depot n'a aucune autre source
            // honnete pour la valeur demandee. Absent se lit `UNAVAILABLE` ;
            // une valeur recopiee depuis l'effectif se lirait comme une mesure.
            'provider_effective' => $resolved->provider,
            'model' => $resolved->trace(),
            // FACT : `FakeAIProvider` n'est jamais selectionne par
            // `ProviderResolver` (doctrine P4, aucun fallback silencieux) — il
            // n'est injecte que dans `ClarifyUserHelpRequestService`. Sur CE
            // chemin, l'absence de fallback est donc une mesure, pas un defaut.
            'fallback_used' => false,
        ]);

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
            // TASK-1566 : le depot a lieu AVANT le `throw`, pour que l'etage
            // qui a arrete le tour soit celui que la trace nomme. Ce tour
            // n'ecrira pourtant AUCUNE interaction en V0-A — c'est V0-B qui
            // persistera les arrets anticipes. La trace part donc avec le
            // processus, exactement comme avant : rien n'est promis ici qui ne
            // soit tenu.
            AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'economic_check', 'denied', $verdict->reason);

            // TASK-1570 / V0-B — le refus economique laisse un TOUR (`refused`,
            // stage `economic_check`, code du verdict tel que le garde l'a
            // MESURE). Ledger vierge : rien n'est parti. La ligne porte
            // `cost_usd = 0, cost_unknown = false` et n'entre dans aucune somme
            // du garde (audit lecteurs §6.3, teste).
            $this->recordEarlyStop($loop, $requester, $contexte, $definition, $resolved,
                AiTurnState::TURN_REFUSED, 'economic_check', $verdict->reason, null, [], null);

            // Trois etats, trois messages, trois codes : credit utilisateur
            // epuise / budget Organization atteint / autre indisponibilite.
            throw AiRefusedException::fromVerdict($verdict);
        }

        AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'economic_check', 'executed');

        // Le prompt administrable est requis AVANT toute depense (embedding
        // compris) : sans lui, indisponibilite explicite.
        // TASK-1227 : doctrine active de l'Organization composee sous la
        // Constitution ; la regle « repondre depuis les sources [S] » reste
        // appliquee en code (citations revalidees), la doctrine ne peut pas
        // autoriser d'inventer.
        $instructions = $this->prompts->compose($capability, $this->capabilityInstructions($definition->promptKey), (string) $organization->id);

        // La consigne de langue vient EN DERNIER, et en code.
        //
        // En dernier parce que le prompt administrable est redige en francais
        // et ne dit rien de la langue de sortie : place avant, la consigne
        // serait noyee sous plusieurs paragraphes qui la contredisent par leur
        // seule langue.
        //
        // En code, et surtout pas dans `admin_ai_prompts` : reecrire le prompt
        // actif changerait le comportement de TOUS les tenants, francais
        // compris, pour un defaut qui n'est qu'une regle manquante. Le reste du
        // prompt n'est pas touche d'un caractere.
        $instructions .= "\n\n".$this->consigneDeLangue($locale);
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
        //
        // TASK-1543 : l'histoire ([Hn]) en fait partie, au meme titre. Une
        // question de changement peut n'avoir AUCUNE reponse documentaire et
        // une reponse historique complete — refuser parce que les Dossiers
        // n'ont rien dit reviendrait a repondre « je n'ai pas trouve » en
        // tenant la reponse. C'est exactement le defaut que T1307 avait corrige
        // pour le manifest, et cette liste est l'endroit ou il se reproduit.
        $consulted = [
            ...$borne->provenanceFor(KnowledgeDeltaSource::NAME),
            ...$borne->provenanceFor(DossierManifestSource::NAME),
            ...$borne->provenanceFor(DossierRetrievalSource::NAME),
        ];

        // TASK-1566 : `ContextBuilder` a bien tourne sur ce chemin — c'est
        // precisement ce qui le distingue des branches documentaires du Shell,
        // qui l'ignorent. Le compteur `consulted` mesure ce que les TROIS
        // sources autorisees ont rendu ensemble ; le detail par etage du
        // retrieval reste sous `retrieval_trace`, qui ne bouge pas (T1565).
        AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'context_builder', 'executed', null, [
            'consulted' => count($consulted),
        ]);

        // TASK-1309 : le refus « aucune source » n'appartient QU'au mode
        // Dossiers. En mode IA + Dossiers, l'absence de provenance
        // documentaire est une information a transmettre au modele, pas une
        // raison de se taire : c'est tout l'interet du mode.
        if ($consulted === [] && $mode !== self::MODE_HYBRID) {
            // TASK-1565 — ce tour n'ecrira AUCUNE `AiInteraction` : il refuse
            // avant tout appel, donc sans rien a facturer ni a tracer. Sa trace
            // de retrieval n'a par consequent nulle part ou aller, et on la
            // reclame ici uniquement pour ne pas la laisser derriere soi dans
            // le journal du processus.
            //
            // C'est une limite ASSUMEE de ce v0, et elle est etroite : des que
            // le manifest rend quelque chose — le cas ENRICA — ce chemin n'est
            // plus pris et la trace est persistee normalement. La lever
            // exigerait d'ecrire une interaction la ou le produit n'en ecrit
            // pas : un changement de comportement, hors mandat.
            // TASK-1570 / CDC-01 V0-B — la limite assumee par T1565 est levee :
            // l'abstention ECRIT un tour (`abstained`, stage `grounding`,
            // `NO_SOURCES_FOUND`), et la `retrieval_trace` est RECLAMEE ET
            // PERSISTEE au lieu d'etre jetee. Le message rendu au membre, la
            // non-publication dans le fil et `interactionId: null` du DTO —
            // que `LoopChat` lit pour signaler l'auteur — ne changent pas.
            AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'grounding', 'abstained', AiTurnReason::TERMINAL_NO_SOURCES_FOUND, [
                'consulted' => 0,
            ]);
            $this->recordEarlyStop($loop, $requester, $contexte, $definition, $resolved,
                AiTurnState::TURN_ABSTAINED, 'grounding', AiTurnReason::TERMINAL_NO_SOURCES_FOUND, $borne, [], $doctrineVersion);

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
                // TASK-1565 / W3A — ce que la frontiere savait, enfin porte par
                // le vrai chemin ChatLoop. Aucun lecteur produit ne consomme
                // ces champs du DTO (`toArray()` ne les expose pas) : le relai
                // est invisible pour le membre, et lisible par l'inspection.
                sourcesUsed: $borne->sourcesUsed,
                sourcesDenied: $borne->sourcesDenied,
            );
        }

        $agent = new LoopKnowledgeAgent(
            $instructions,
            (int) config('ai.knowledge.max_tokens', 700),
            (float) config('ai.knowledge.temperature', 0.2),
        );

        $startedAt = microtime(true);
        // TASK-1300 / TASK-1308 : sur un reply (a une bulle IA OU humaine
        // depuis TASK-1308), l'echange precedent — borne, agnostique du
        // moteur — s'insere entre les sources et la question ; partout
        // ailleurs le prompt T-3 est inchange octet pour octet.
        $conversation = $this->conversationContext->build($inThreadTrigger);

        // TASK-1567 / CDC-01 V0-L — ce que CE tour a REELLEMENT recu de la
        // conversation. Les valeurs sont celles que le moteur vient de
        // calculer pour son prompt : rien n'est relu, rien n'est recalcule.
        //
        // `count = 0` s'ecrit TEL QUEL, sans statut d'erreur : un follow-up
        // sans reply explicite ne recoit aucun historique, et c'est le
        // comportement produit actuel (CDC-01 P0.12). La strategie reste
        // `reply_chain` — c'est bien elle qui a ete tentee ; ecrire `none`
        // laisserait croire qu'aucune n'a ete essayee.
        AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'conversation_history', 'executed', null, [
            'count' => count($conversation->messageIds),
            'chars' => $conversation->chars,
        ]);

        $history = [
            'strategy' => 'reply_chain',
            'message_ids' => $conversation->messageIds,
            'count' => count($conversation->messageIds),
            'chars' => $conversation->chars,
            // Le message AUQUEL l'utilisateur repondait, jamais le message
            // courant (CDC-01 P0.12).
            'trigger_id' => $inThreadTrigger?->reply_to_id,
            'budget_exhausted' => $conversation->budgetExhausted,
        ];
        $thread = $conversation->text;
        // TASK-1309 : en mode IA + Dossiers sans AUCUNE provenance, le bloc
        // de sources est vide. Le laisser vide, c'est laisser le modele
        // deviner ce que valent les Dossiers ; on le lui DIT, explicitement,
        // pour qu'il puisse repondre depuis sa connaissance generale tout en
        // signalant que les Dossiers n'ont rien apporte (brief section 16).
        $sourcesBlock = $borne->text !== ''
            ? $borne->text
            : ($mode === self::MODE_HYBRID ? self::HYBRID_NO_DOCUMENTARY_SOURCE_NOTICE : '');
        $prompt = $sourcesBlock
            .($thread === '' ? '' : "\n\n".$thread)
            // L'etiquette suit la meme autorite que la consigne. La laisser en
            // francais rouvrirait, a l'endroit le plus proche du modele, l'ancrage
            // que la consigne vient de fermer.
            ."\n\n".trans('ai.loop_knowledge_member_question', [], $locale)."\n".$question;

        try {
            $response = $agent->prompt(
                $prompt,
                provider: $resolved->instance,
                model: $resolved->model,
            );
        } catch (\Throwable $exception) {
            // TASK-1571 / V0-C — l'etape ET le verdict portent le CODE ; la
            // classe d'exception reste dans `metadata.failure` (diagnostic).
            AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'provider_call', 'failed', AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED);

            $this->recordInteraction($loop, $requester, $contexte, $definition, $resolved, $prompt, null,
                AiUsage::notObserved(), ['cost_usd' => null, 'cost_unknown' => null], null, 'failed', $startedAt, null,
                $exception::class, $consulted, [], $doctrineVersion, $borne, $history, AiTurnReason::TERMINAL_PROVIDER_CALL_FAILED);

            throw new RuntimeException(__('loops.ai_error'), 0, $exception);
        }

        AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'provider_call', 'executed');

        // TASK-1391 : normaliser les citations AVANT d'assainir.
        //
        // L'ordre est le correctif. `AiMarkdownSanitizer` neutralise les URL
        // non http(s) en ne gardant que le libelle : une citation ecrite
        // `[S1](fichier.pdf)` — habitude tres courante d'un modele — perdait
        // ses crochets et devenait `S1` AVANT d'etre lue. La source etait
        // reellement recuperee, reellement citee, et sortait quand meme de
        // « Sources utilisees ».
        $answer = AiMarkdownSanitizer::sanitize(
            $this->normaliserCitations((string) $response->text),
            (int) config('ai.knowledge.max_answer_chars', 3000),
        );

        $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        if ($answer === '') {
            // TASK-1570 / CDC-01 V0-B — la reponse vide post-appel n'est plus
            // « facturee sans trace » (trou S11) : l'appel EST parti et se paie,
            // le ledger recoit donc sa ligne reelle, et l'interaction s'ecrit
            // `failed` / stage `generation` / `EMPTY_MODEL_ANSWER`. Le refus
            // rendu au membre est inchange.
            AiTurnTrace::step($contexte->organizationId, $contexte->turnId, 'generation', 'failed', AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER);

            $this->recordInteraction($loop, $requester, $contexte, $definition, $resolved, $prompt, null,
                $usage, $cost->traceAttributes(), $cost, 'failed', $startedAt, $response->invocationId,
                AiTurnReason::TERMINAL_EMPTY_MODEL_ANSWER, $consulted, [], $doctrineVersion, $borne, $history);

            throw new RuntimeException(__('loops.ai_empty_response'));
        }

        // Citations : uniquement les references ([Mn] ou [Sn]) presentes
        // dans la provenance REELLEMENT fournie. Une reference inventee est
        // ignoree.
        $cited = $this->citedSources($answer, $consulted);

        // TASK-1391 : une reference inventee ne doit pas rester sous les yeux
        // du membre. Qu'elle ne devienne pas une source etait deja acquis ;
        // qu'elle disparaisse du texte ne l'etait pas, et un `[S9]` sans S9
        // dans le bloc de provenance est exactement la citation qui ne pointe
        // nulle part que cette tranche interdit.
        $answer = $this->retirerReferencesInventees($answer, $consulted);

        $interaction = $this->recordInteraction($loop, $requester, $contexte, $definition, $resolved, $prompt,
            $answer, $usage, $cost->traceAttributes(), $cost, 'success', $startedAt, $response->invocationId, null,
            $consulted, $cited, $doctrineVersion, $borne, $history);

        // TASK-1309 : « Sources utilisées » = sources REELLEMENT CITEES.
        // Jusqu'ici, faute de citation valide, on retombait sur TOUT ce qui
        // avait ete consulte — une reponse qui refusait de repondre affichait
        // alors dix « sources utilisees » qui n'avaient soutenu aucune
        // affirmation. En mode IA + Dossiers, ce repli aurait ete pire
        // encore : une reponse 100 % connaissance generale se serait parée de
        // sources documentaires.
        $sources = $cited;

        // TASK-1558 — le SEAM d'observation, et le seul.
        //
        // `$publish = false` n'est pas un mode degrade ni un second chemin :
        // tout ce qui precede — garde d'appartenance, idempotence, verrou,
        // Context Builder, garde economique, retrieval, validation des
        // citations, ledger, `AiInteraction` — s'execute a l'identique. Seules
        // les DEUX lignes de `loop_messages` ne sont pas ecrites.
        //
        // C'est la difference entre observer un chemin et en fabriquer une
        // imitation : ici le service reel repond, il facture, il trace ; il ne
        // parle simplement pas dans le fil de quelqu'un d'autre. La telemetrie
        // canonique reste, parce qu'elle appartient au chemin.
        if ($publish) {
            $this->publishExchange($mode, $loop, $requester, $question, $answer, $resolved, $interaction, $cited,
                $sources, $this->consultedForDisplay($cited, $consulted), $inThreadTrigger, $conversation->messageIds);
        }

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
            // TASK-1565 / W3A — la dette nommee dans le docblock de
            // `KnowledgeAnswer` est payee : ce service CALCULAIT les deux dans
            // son `ContexteBorne` et les jetait. Deux services les portaient
            // deja (`OrganizationDoctrineSandbox`, `DossierInsightsService`) ;
            // le vrai chemin ChatLoop etait le dernier a ne pas le faire.
            sourcesUsed: $borne->sourcesUsed,
            sourcesDenied: $borne->sourcesDenied,
        );
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
     * @param  list<string>  $contextMessageIds
     */
    private function publishExchange(
        string $mode,
        Loop $loop,
        User $requester,
        string $question,
        string $answer,
        ResolvedModel $resolved,
        AiInteraction $interaction,
        array $cited,
        array $sources,
        array $consultedForDisplay = [],
        ?LoopMessage $inThreadTrigger = null,
        array $contextMessageIds = [],
    ): void {
        DB::transaction(function () use ($mode, $loop, $requester, $question, $answer, $resolved, $interaction, $cited, $sources, $consultedForDisplay, $inThreadTrigger, $contextMessageIds): void {
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
                    // TASK-1308 : `ai_mode` est le discriminant canonique de
                    // l'identite de bulle (Organization · Dossiers) — le seul
                    // moteur qui ecrit `rag`. `action` reste pour l'audit
                    // historique : `knowledge` sans declencheur (chemin JSON
                    // T-1), `dossiers` pour tout reply explicite au mode
                    // Dossiers — remplace l'ancienne distinction
                    // slash_ia/continuation, retiree avec `/ia` (TASK-1308).
                    // TASK-1309 : le troisieme moteur ecrit son propre
                    // discriminant `llm_rag` — l'identite de bulle
                    // « {Organization} · IA + Dossiers » en decoule, sans
                    // migration de donnees ni relecture des messages existants.
                    'ai_mode' => $mode === self::MODE_HYBRID ? 'llm_rag' : 'rag',
                    'action' => $mode === self::MODE_HYBRID
                        ? 'ia_dossiers'
                        : ($inThreadTrigger === null ? 'knowledge' : 'dossiers'),
                    'question' => $question,
                    'grounded' => $cited !== [],
                    'sources' => array_map(KnowledgeAnswer::publicSource(...), $sources),
                    // TASK-1309 (recette E2E) : les documents dont le CONTENU
                    // a ete lu sans qu'aucune citation valide n'en sorte.
                    // Presents SOUS LEUR VRAI TITRE (« Sources consultées »),
                    // jamais comme un appui — et seulement quand il n'y a
                    // aucune source utilisee a montrer, sinon la cle est
                    // absente et la metadata reste identique a avant.
                    ...($consultedForDisplay === [] ? [] : [
                        'consulted' => array_map(KnowledgeAnswer::publicSource(...), $consultedForDisplay),
                    ]),
                    'provider' => $resolved->provider,
                    'model' => $resolved->model,
                    'ai_interaction_id' => $interaction->id,
                    'context_message_ids' => $contextMessageIds,
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
    /**
     * TASK-1309 (recette E2E reelle) : ce qu'on montre quand RIEN n'est cite.
     *
     * Decouvert en recette : un modele peut produire une reponse parfaitement
     * fondee sur un extrait fourni SANS ecrire son marqueur `[Sn]` (constate
     * sur le banc ai-validation, run 2b66b90e). La regle « sources utilisees
     * = sources citees » est juste, mais appliquee seule elle faisait alors
     * disparaitre TOUTE provenance : le membre n'avait plus rien a verifier.
     *
     * On montre donc, sous le titre « Sources consultées » et jamais sous
     * « Sources utilisées », les documents dont le CONTENU a reellement ete
     * lu — les entrees `dossier.retrieval` uniquement. Le manifest en est
     * exclu volontairement : « j'ai regarde la liste des fichiers » n'est pas
     * une provenance a offrir a la verification, c'est du bruit (et jusqu'a
     * 30 lignes). Aucune de ces entrees n'est presentee comme ayant soutenu
     * une affirmation.
     *
     * Vide des qu'une citation valide existe : dans ce cas la bulle montre
     * les sources utilisees, et la metadata reste identique a avant.
     *
     * @param  list<array<string, mixed>>  $cited
     * @param  list<array<string, mixed>>  $consulted
     * @return list<array<string, mixed>>
     */
    private function consultedForDisplay(array $cited, array $consulted): array
    {
        if ($cited !== []) {
            return [];
        }

        return array_values(array_filter(
            $consulted,
            static fn (array $source): bool => ($source['source'] ?? null) === DossierRetrievalSource::NAME,
        ));
    }

    /**
     * Ramene toute citation a sa forme canonique `[Ref]`, avant assainissement.
     *
     * Deux formes que le modele produit spontanement, et que le parseur —
     * une comparaison LITTERALE de `[Ref]` — laissait tomber sans un mot :
     *
     * - le lien markdown `[S1](fichier.pdf)`. Le sanitizer neutralise les URL
     *   non http(s) en ne gardant que le libelle, donc les crochets
     *   disparaissaient AVANT la lecture des citations ;
     * - le groupe `[S1, S2]`, forme naturelle des qu'une affirmation s'appuie
     *   sur deux extraits. Aucun prompt ne l'interdit, et la seule doctrine
     *   qui montre un exemple suggere la forme accolee.
     *
     * Normaliser en amont plutot que compliquer le parseur garde une seule
     * definition d'une citation dans le service, et corrige du meme geste ce
     * que le membre LIT : il voit desormais `[S1]`, pas `S1`.
     */
    private function normaliserCitations(string $texte): string
    {
        // `[S1](cible)` -> `[S1]`. La cible est jetee : la provenance
        // affichee vient du registre, jamais d'une URL ecrite par le modele.
        $texte = (string) preg_replace('/\[([SMH]\d+)\]\([^)]*\)/', '[$1]', $texte);

        // `[S1, S2]`, `[S1,S2]`, `[S1 et S2]`, `[S1; S2]` -> `[S1][S2]`.
        return (string) preg_replace_callback(
            '/\[([SMH]\d+(?:\s*(?:,|;|et|and)\s*[SMH]\d+)+)\]/i',
            static function (array $groupe): string {
                preg_match_all('/[SMH]\d+/i', $groupe[1], $refs);

                return implode('', array_map(static fn (string $ref): string => '['.$ref.']', $refs[0]));
            },
            $texte,
        );
    }

    /**
     * Efface du texte publie les references qui ne designent aucune source.
     *
     * `citedSources()` empechait deja une reference inventee de DEVENIR une
     * source. Elle restait pourtant sous les yeux du membre : `[S9]` sans S9
     * dans le bloc de provenance est une citation qui ne pointe nulle part —
     * exactement ce que la promesse de cette tranche interdit.
     *
     * Seul le marqueur est retire ; la phrase qui le portait reste. Supprimer
     * la phrase reviendrait a reecrire la reponse du modele, ce qui n'est pas
     * le role de cette methode — et `DossierInsightsService`, qui le fait,
     * assume ce choix dans un autre contexte.
     *
     * @param  list<array<string, mixed>>  $consulted
     */
    private function retirerReferencesInventees(string $texte, array $consulted): string
    {
        $connues = array_filter(array_column($consulted, 'ref'));

        return trim((string) preg_replace_callback(
            '/\s*\[([SMH]\d+)\]/',
            static fn (array $marqueur): string => in_array($marqueur[1], $connues, true) ? $marqueur[0] : '',
            $texte,
        ));
    }

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
     *
     * TASK-1309 : le scenario vient de `CapabilityDefinition::$promptKey` —
     * `loop_knowledge_answer` ou `loop_hybrid_answer`. Deux capabilities, deux
     * lignes administrables, une seule regle de chargement.
     */
    /**
     * La langue qui fait autorite pour ce que le modele produit.
     *
     * `Organization.locale` a une valeur par defaut en base (`fr`) et n'est
     * donc jamais nulle en pratique. Le repli reste ecrit pour qu'une
     * Organization arrivant d'un chemin qui ne l'aurait pas posee ne produise
     * pas un `trans()` sur une locale vide — ou le traducteur retomberait sur
     * la langue du LECTEUR, precisement ce que cette tranche supprime.
     */
    private function localeDeReference(Organization $organization): string
    {
        $locale = trim((string) $organization->locale);

        return $locale !== '' ? $locale : (string) config('app.fallback_locale', 'fr');
    }

    /**
     * La regle de langue envoyee au modele, ecrite DANS cette langue.
     *
     * Une consigne « answer in English » redigee en francais ajouterait un
     * quatrieme signal francais a un prompt qui en compte deja trop ; formulee
     * en anglais, elle est elle-meme une preuve de la langue attendue.
     */
    private function consigneDeLangue(string $locale): string
    {
        return (string) trans('ai.loop_knowledge_answer_language', [], $locale);
    }

    private function capabilityInstructions(string $scenarioId): string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', $scenarioId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if ($prompt === null || trim((string) $prompt->prompt_text) === '') {
            throw new RuntimeException(__('loops.knowledge_prompt_missing'));
        }

        return (string) $prompt->prompt_text;
    }

    /**
     * TASK-1570 / CDC-01 V0-B — le writer des ARRETS ANTICIPES : une
     * `AiInteraction` NON GENERATIVE.
     *
     * Le tour s'est arrete avant tout appel provider (refus de resolution,
     * refus economique, abstention zero-source). Jusqu'ici il ne laissait
     * AUCUNE ligne : ni interaction, ni ledger, ni log — l'angle mort que
     * CDC-01 §1.1 nomme en premier. Cette ligne dit ou et pourquoi.
     *
     * Ce qu'elle est, et n'est pas (§6.1) :
     *   - `response = null`, `input/output_tokens = 0` : VRAI zero, rien n'est
     *     parti ; `cost_usd = 0`, `cost_unknown = false` : un cout CONNU et nul,
     *     pas un cout inconnu — elle n'entre donc ni dans le budget ni dans le
     *     quota UNKNOWN du garde (§6.3, teste) ;
     *   - AUCUNE ligne au ledger (I3) : `ai_provider_invocations` reste
     *     reserve aux appels emis ;
     *   - `model` et `prompt` sont des colonnes NOT NULL : `''` quand rien n'a
     *     ete resolu ni construit — le lecteur rend `null`, jamais une valeur
     *     inventee ;
     *   - pas de `latency_ms` : ce chemin ne mesure pas ces arrets, et un
     *     chrono pose ici pour l'occasion ne mesurerait qu'une partie du tour
     *     (C7-bis). Absent se lit `UNAVAILABLE`.
     *   - la `retrieval_trace` et les invocations embedding du tour sont
     *     RECLAMEES et persistees : sur une abstention, la recherche a eu lieu
     *     et a coute — sa trace n'est plus jetee (limite T1565 levee).
     *
     * Lecteurs (§6.3) : `metadata.status` porte un statut NOUVEAU
     * (`abstained` | `refused`) que `AiQualityReport` exclut et que
     * `AiProviderInvocationConsole` affiche comme un tour non generatif
     * (A7) — jamais comme une generation.
     *
     * @param  array<string, mixed>  $history
     */
    private function recordEarlyStop(
        Loop $loop,
        User $requester,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ?ResolvedModel $resolved,
        string $turnStatus,
        string $stage,
        string $reasonCode,
        ?ContexteBorne $borne,
        array $history,
        ?int $doctrineVersion,
    ): AiInteraction {
        // TASK-1573 / V0-E — un seul claim, partage (voir `recordInteraction`).
        $dossierRetrieval = $borne === null ? null : DossierRetrievalTraceRecorder::claim($contexte->organizationId, $contexte->turnId);

        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            'feature' => $definition->id,
            'model' => $resolved?->trace() ?? '',
            'prompt' => '',
            'response' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
            'cost_unknown' => false,
            'metadata' => array_filter([
                'loop_id' => $loop->id,
                'requested_by' => $requester->id,
                'provider' => $resolved?->provider,
                'capability' => $definition->id,
                'status' => $turnStatus,
                'turn_id' => $contexte->turnId,
                RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY => RecordSdkEmbeddingsInvocation::claimQueryInvocationIds($contexte->organizationId, $contexte->turnId),
                'sources_used' => $borne?->sourcesUsed,
                DossierRetrievalTraceRecorder::TURN_METADATA_KEY => $borne === null ? null : [
                    'sources_denied' => $borne->sourcesDenied,
                    'dossier_retrieval' => $dossierRetrieval,
                ],
                AiTurnTrace::TURN_METADATA_KEY => AiTurnTrace::compose(
                    $contexte->turnId,
                    AiTurnTrace::claim($contexte->organizationId, $contexte->turnId),
                    [
                        'status' => $turnStatus,
                        'stage' => $stage,
                        'reason_code' => $reasonCode,
                        'decided_by' => class_basename(self::class),
                        'history' => $history,
                        // TASK-1573 / V0-E — sur une abstention, les familles
                        // disent ce qui a ete cherche, retenu (rien) et refuse.
                        'sources' => $borne === null ? [] : AiTurnTrace::sourcesBlock($borne->sourcesUsed, $borne->sourcesDenied, $dossierRetrieval),
                    ],
                ),
            ], static fn ($value): bool => $value !== null)
                + ['doctrine_version' => $doctrineVersion],
        ]);
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
        ContexteBorne $borne,
        /** @param array<string, mixed> $history ce que le tour a VU (V0-L) */
        array $history,
        /** TASK-1571 / V0-C — le code du verdict quand il ne coincide pas avec `$failure` */
        ?string $reasonCode = null,
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

        // TASK-1566 : la latence est mesuree UNE fois et partagee par
        // `metadata.latency_ms` (cle historique, inchangee) et `turn.latency_ms`.
        // Deux appels a `microtime()` rendraient deux valeurs differentes pour
        // la meme duree — un ecart minuscule, mais qui suffirait a faire douter
        // d'une trace le jour ou quelqu'un les comparerait.
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        // TASK-1573 / V0-E — la trace de retrieval est reclamee UNE fois et
        // sert deux cles : `retrieval_trace` (detail fin, T1565) et
        // `turn.sources` (compteurs). Deux claims rendraient `null` au second.
        $dossierRetrieval = DossierRetrievalTraceRecorder::claim($contexte->organizationId, $contexte->turnId);

        return AiInteraction::create([
            'user_id' => $requester->id,
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            // TASK-1309 : la capability EST la feature de cette trace —
            // `loop_knowledge_answer` (valeur historique, inchangee) ou
            // `loop_hybrid_answer`. Le `process` economique, lui, reste
            // commun aux deux (voir CapabilityRegistry).
            'feature' => $definition->id,
            'model' => $resolved->trace(),
            'prompt' => $prompt,
            'response' => $response,
            'input_tokens' => $usage->inputTokensOrZero(),
            'output_tokens' => $usage->outputTokensOrZero(),
            ...$costAttributes,
            'metadata' => array_filter([
                'loop_id' => $loop->id,
                'requested_by' => $requester->id,
                'latency_ms' => $latencyMs,
                'provider' => $resolved->provider,
                'capability' => $definition->id,
                'status' => $status,
                'sdk_invocation_id' => $sdkInvocationId,
                // TASK-1558 : l'identite du TOUR, tracee. Sans elle, les
                // invocations embedding ci-dessous ne se rattachent a rien
                // d'observable depuis la base : T1556 les reclame PAR turn_id,
                // mais ne l'ecrivait nulle part.
                'turn_id' => $contexte->turnId,
                // TASK-1556 : les invocations embedding (query) que CE tour a
                // declenchees, reclamees une seule fois — `[]` mesure, jamais null.
                RecordSdkEmbeddingsInvocation::TURN_METADATA_KEY => RecordSdkEmbeddingsInvocation::claimQueryInvocationIds($contexte->organizationId, $contexte->turnId),
                'failure' => $failure,
                'retrieval' => ['consulted' => $ids($consulted), 'cited' => $ids($cited)],
                // TASK-1565 / W3A — les sources qui ont REELLEMENT fourni de la
                // matiere. Lue par `AiTurnInspection::provider()` et par
                // personne d'autre : aucun lecteur produit ne la consomme.
                'sources_used' => $borne->sourcesUsed,
                // TASK-1565 — la trace des etages du retrieval, reclamee UNE
                // fois au journal process-local ou `DossierRetrievalSource` l'a
                // deposee (meme pattern que les invocations embedding
                // ci-dessus, T1556).
                //
                // `sources_denied` voyage ICI, et non au premier niveau, et
                // c'est une decision mesuree : au premier niveau, la cle est
                // lue par `AiResponseExplanationService::ragPanel()` et allume
                // un bandeau VISIBLE PAR LE MEMBRE (`loops.why_denied`). Le
                // docblock de `KnowledgeAnswer` l'avait annonce — « une
                // difference PRODUIT, qui demande sa propre mesure et pas un
                // branchement de commodite ». T1565 est une TASK
                // d'observabilite a comportement produit inchange : elle ne
                // l'allume pas.
                //
                // `dossier_retrieval` a `null` signifie « la source n'a produit
                // aucune trace pour ce tour » — refusee, absente de la
                // capability, ou collecte coupee. Jamais un tableau vide, qui
                // se lirait comme une mesure a zero.
                DossierRetrievalTraceRecorder::TURN_METADATA_KEY => [
                    'sources_denied' => $borne->sourcesDenied,
                    'dossier_retrieval' => $dossierRetrieval,
                ],
                // TASK-1566 / CDC-01 V0-A — le bloc canonique du TOUR.
                //
                // Ce chemin est le PRODUCTEUR PILOTE : il est le premier a
                // ecrire le bloc complet que le schema v1 prevoit A CE STADE.
                // « A ce stade » est litteral — trois sous-blocs prevus par le
                // schema sont DELIBEREMENT absents, parce que les TASKs qui les
                // produisent n'ont pas encore eu lieu :
                //
                //   `sources`  -> V0-E (les quatre familles)
                //   `history`  -> V0-L (ce que le tour a vu de la conversation)
                //   `state`    -> V0-F (le grounding se lit, axe 2 ecrit)
                //
                // Les ecrire ici « puisque la valeur est a portee de main »
                // serait exactement la derive que le decoupage evite : une cle
                // a moitie alimentee est plus dangereuse qu'une cle absente,
                // parce qu'elle se lit comme une mesure. Absentes, elles se
                // lisent `UNAVAILABLE` (CDC-01 §11) — ce qui est la verite.
                //
                // Ce bloc n'est lu par AUCUN lecteur produit : il voyage sous
                // `turn`, imbrique, pour la meme raison exactement que
                // `retrieval_trace` ci-dessus.
                AiTurnTrace::TURN_METADATA_KEY => AiTurnTrace::compose(
                    $contexte->turnId,
                    AiTurnTrace::claim($contexte->organizationId, $contexte->turnId),
                    [
                        // Le vocabulaire du TOUR n'est pas celui de la ligne :
                        // `metadata.status` conserve ses valeurs historiques
                        // (`completed`/`failed`, lues par des tiers), tandis que
                        // `turn.status` parle le vocabulaire des trois axes.
                        // Traduire ici, c'est eviter de renommer une valeur que
                        // des lecteurs consomment deja (invariant I8).
                        'status' => $status === 'failed'
                            ? AiTurnState::TURN_FAILED
                            : AiTurnState::TURN_ANSWERED,
                        'stage' => $status === 'failed' ? 'generation' : null,
                        // TASK-1570 / V0-B — le code n'est ecrit que s'il vient
                        // du registre : une classe d'exception (`failure` d'un
                        // provider qui leve) n'est pas un `reason_code`, et la
                        // nommer comme tel appartient a V0-C.
                        'reason_code' => $reasonCode ?? (AiTurnReason::isKnown($failure) ? $failure : null),
                        'decided_by' => class_basename(self::class),
                        // La MEME mesure que `latency_ms` ci-dessus — jamais un
                        // second chronometre, qui donnerait deux valeurs pour
                        // une seule duree (correction C7 du CDC).
                        'latency_ms' => $latencyMs,
                        // TASK-1567 / V0-L — ce que le tour a VU de la
                        // conversation. Valeurs deja calculees par le moteur
                        // pour son prompt ; aucune relecture, aucun recalcul.
                        'history' => $history,
                        // TASK-1573 / V0-E — les quatre familles, formatees
                        // depuis la borne et la trace deja en main.
                        'sources' => AiTurnTrace::sourcesBlock($borne->sourcesUsed, $borne->sourcesDenied, $dossierRetrieval),
                    ],
                ),
            ], static fn ($value): bool => $value !== null)
                // TASK-1236 : cle toujours presente, meme a null (aucune doctrine
                // active) — sa PRESENCE distingue une interaction tracee d'une
                // ligne anterieure au mecanisme, ce qu'un array_filter effacerait.
                + ['doctrine_version' => $doctrineVersion],
        ]);
    }
}
