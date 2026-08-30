<?php

namespace App\Services\ChatLoop;

use App\Ai\Agents\LoopDecisionSuggestionAgent;
use App\Ai\Agents\LoopDirectAnswerAgent;
use App\Ai\Agents\LoopSummaryAgent;
use App\Ai\CapabilityDefinition;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\LoopMessagesSource;
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
use App\Services\Ai\AiConversationContextBuilder;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\JsonResponseParser;
use App\Services\Loops\LoopDecisionService;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiCost;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiMarkdownSanitizer;
use App\Support\Ai\AiProcess;
use App\Support\Ai\AiRefusedException;
use App\Support\Ai\AiTurnIdempotency;
use App\Support\Ai\AiTurnLock;
use App\Support\Ai\AiUsage;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Facades\DB;

class ChatLoopAiService
{
    public function __construct(
        private readonly AiEconomicGuard $economicGuard,
        private readonly AiProviderInvocationLedger $ledger,
        private readonly CapabilityRegistry $capabilities,
        private readonly PromptRepository $prompts,
        private readonly ProviderResolver $providers,
        private readonly ContextBuilder $contextBuilder,
        private readonly LoopMessagesSource $loopMessages,
        private readonly AiConversationContextBuilder $conversationContext,
    ) {}

    /**
     * Capability `loop_ask` — mode IA du composeur unifie (TASK-1308) :
     * reponse LLM directe a un tour DEJA persiste par le composeur
     * (`LoopChat::sendMessage()`), question libre ou reply explicite.
     *
     * A la difference de `ask()` (chemin herite T-1233, conserve pour le
     * formulaire `loops.ai` uniquement) : le message humain n'est PAS
     * re-cree ici — il existe deja dans le fil, choisi par le composeur, pas
     * par cette methode — et le contexte n'est PAS le fenetrage
     * `loop.messages` (activite generale de la Boucle) mais la chaine
     * `reply_to_id` du declencheur, via `AiConversationContextBuilder` —
     * la MEME autorite que le mode Dossiers (`LoopKnowledgeAnswerService`),
     * pour que « repondre » signifie la meme chose des deux moteurs.
     * Absence de reply (question libre, mode IA sans cible) : contexte vide,
     * le prompt est la question seule.
     */
    public function respondInThread(Loop $loop, User $requester, string $question, LoopMessage $triggerMessage): LoopMessage
    {
        $this->assertCanRequest($loop, $requester);

        // TASK-1311 : le REJEU. Ce message declencheur a-t-il deja sa reponse ?
        // Le verrou ci-dessous n'y peut rien — a trois secondes d'intervalle il
        // est deja libere, et la seconde generation partirait.
        AiTurnIdempotency::assertNotAnswered($triggerMessage);

        // TASK-1311 : verrou du tour, extrait dans `AiTurnLock` — meme
        // TTL, meme message de refus, meme liberation en `finally`. La cle
        // devient `{organization}:{loop}:{user}` : deux membres d'une meme
        // Boucle sont deux tours differents et ne se bloquent plus.
        return AiTurnLock::run($loop, $requester, function () use ($loop, $requester, $question, $triggerMessage) {
            $locale = $this->resolveLocale($requester, $loop);
            $capability = CapabilityRegistry::LOOP_ASK;
            $definition = $this->capabilities->get($capability);

            // La Boucle est une PORTEE a l'interieur du tenant, jamais le tenant.
            $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

            $organization = $loop->organization()->firstOrFail();

            $contexte = new ContexteIa(
                organizationId: (string) $organization->id,
                userId: (string) $requester->id,
                loopId: (string) $loop->id,
                locale: $locale,
                capability: $capability,
                correlationId: AiCorrelation::id(),
                source: CapabilityRegistry::SOURCE_LOOP_MESSAGES,
            );

            $scenarioId = (string) config('ai.chatloop.ask_scenario', 'chatloop_ai_ask');

            $instructions = $this->prompts->compose(
                $capability,
                $this->resolvePromptOrFail($scenarioId, $locale, 'loops.ai_answer_prompt_missing'),
                (string) $organization->id,
            );
            $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

            $conversation = $this->conversationContext->build($triggerMessage);

            $prompt = $conversation->isEmpty()
                ? $question
                : $conversation->text."\n\nQuestion : ".$question;

            try {
                $resolved = $this->providers->resolve($capability, $contexte);
            } catch (\DomainException $exception) {
                throw AiRefusedException::notConfigured($exception);
            }

            $verdict = $this->economicGuard->authorize(
                $organization,
                $definition->process,
                $resolved->provider,
                $resolved->model,
                (float) config('ai.chatloop.economic_guard.monthly_budget_usd', 2.00),
                (int) config('ai.chatloop.economic_guard.monthly_unknown_limit', 10),
                $requester,
            );

            if (! $verdict->allowed) {
                throw AiRefusedException::fromVerdict($verdict);
            }

            $agent = new LoopDirectAnswerAgent(
                $instructions,
                (int) config('ai.chatloop.max_tokens', 512),
                (float) config('ai.chatloop.temperature', 0.7),
            );

            $interaction = $this->generateViaSdk(
                agent: $agent,
                loop: $loop,
                requester: $requester,
                contexte: $contexte,
                definition: $definition,
                resolved: $resolved,
                scenarioId: $scenarioId,
                prompt: $prompt,
                extraMetadata: [
                    'provenance' => [
                        'conversation.thread' => $conversation->messageIds,
                    ],
                    'question' => $question,
                ],
                doctrineVersion: $doctrineVersion,
            );

            $answer = AiMarkdownSanitizer::sanitize(
                (string) $interaction->response,
                (int) config('ai.chatloop.max_response_chars', 1400),
            );

            if ($answer === '') {
                throw new \RuntimeException(__('loops.ai_empty_response'));
            }

            return DB::transaction(function () use ($loop, $requester, $question, $answer, $resolved, $interaction, $triggerMessage, $conversation) {
                $message = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => null,
                    'reply_to_id' => $triggerMessage->id,
                    'body' => $answer,
                    'image_path' => null,
                    'type' => 'ai',
                    'metadata' => [
                        'requested_by' => $requester->id,
                        // TASK-1308 : `ai_mode` est le discriminant canonique
                        // d'identite de bulle (Organization · IA). `action`
                        // distingue ce chemin (`ia`, compositeur unifie) du
                        // chemin herite `ask` (formulaire `loops.ai`).
                        'ai_mode' => 'llm',
                        'action' => 'ia',
                        'question' => $question,
                        'context_message_ids' => $conversation->messageIds,
                        'trigger_message_id' => $triggerMessage->id,
                        'provider' => $resolved->provider,
                        'model' => $resolved->model,
                        'ai_interaction_id' => $interaction->id,
                    ],
                    'organization_id' => $loop->organization_id,
                ]);

                event(new LoopMessageCreated($message));

                $loop->touch();

                return $message;
            });
        });
    }

    /**
     * Capability `loop_answer` — « Demander a l'IA » : intervention spontanee
     * de l'IA dans la Boucle, publiee comme message `ai` (TASK-1233).
     *
     * Meme chaine que `summarize()` : capability canonique -> Constitution ->
     * doctrine de l'Organization -> prompt administrable EXIGE -> Context
     * Builder (sources autorisees) -> provider et credential de l'Organization
     * -> AiEconomicGuard (budget + credit du demandeur) -> invocation SDK
     * tracee (ledger + ai_interactions, provenance) -> publication. La SURFACE
     * historique est conservee (verrou, messages, metadata, evenements,
     * erreurs) ; le CONTENU de la reponse change quand une doctrine est active :
     * c'est le but.
     */
    public function answer(Loop $loop, User $requester): LoopMessage
    {
        $this->assertCanRequest($loop, $requester);

        // TASK-1311 : verrou du tour, extrait dans `AiTurnLock` — meme
        // TTL, meme message de refus, meme liberation en `finally`. La cle
        // devient `{organization}:{loop}:{user}` : deux membres d'une meme
        // Boucle sont deux tours differents et ne se bloquent plus.
        return AiTurnLock::run($loop, $requester, function () use ($loop, $requester) {
            $locale = $this->resolveLocale($requester, $loop);

            if (! $this->loopHasEnoughContent($loop)) {
                throw new \RuntimeException(__('loops.not_enough_content_to_summarize'));
            }

            $scenarioId = (string) config('ai.chatloop.scenario', 'chatloop_ai_answer');

            $generated = $this->generateDirectAnswer(
                loop: $loop,
                requester: $requester,
                capability: CapabilityRegistry::LOOP_ANSWER,
                scenarioId: $scenarioId,
                locale: $locale,
                question: null,
            );

            $answer = AiMarkdownSanitizer::sanitize(
                (string) $generated['interaction']->response,
                (int) config('ai.chatloop.max_response_chars', 1400),
            );

            if ($answer === '') {
                throw new \RuntimeException(__('loops.ai_empty_response'));
            }

            return DB::transaction(function () use ($loop, $requester, $answer, $generated) {
                $message = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => null,
                    // TASK-1297 : le meme lien que ask() — la reponse pointe le
                    // message humain qui l'a declenchee (nullable : contexte
                    // sans message non-IA).
                    'reply_to_id' => $generated['trigger_message_id'],
                    'body' => $answer,
                    'image_path' => null,
                    'type' => 'ai',
                    'metadata' => [
                        'requested_by' => $requester->id,
                        'action' => 'answer',
                        'context_message_ids' => $generated['context_message_ids'],
                        'trigger_message_id' => $generated['trigger_message_id'],
                        'provider' => $generated['provider'],
                        'model' => $generated['model'],
                        'ai_interaction_id' => $generated['interaction']->id,
                    ],
                    'organization_id' => $loop->organization_id,
                ]);

                event(new LoopMessageCreated($message));

                $loop->touch();

                return $message;
            });
        });
    }

    /**
     * Capability `loop_summary` — READ-ONLY metier (TASK-1207 / IA P3).
     *
     * Le resume N'EST PLUS publie en `LoopMessage`. `CapabilityDefinition::$canWrite`
     * vaut `false` : la capability peut lire les messages autorises de la Boucle,
     * appeler l'IA et deposer ses traces techniques, mais elle ne peut pas creer
     * de contribution visible sans validation humaine. Le resume vit desormais
     * dans sa trace `ai_interactions`, relue par `latestSummary()`.
     */
    public function summarize(Loop $loop, User $requester): LoopSummary
    {
        $this->assertCanRequest($loop, $requester);

        // TASK-1311 : verrou du tour, extrait dans `AiTurnLock` — meme
        // TTL, meme message de refus, meme liberation en `finally`. La cle
        // devient `{organization}:{loop}:{user}` : deux membres d'une meme
        // Boucle sont deux tours differents et ne se bloquent plus.
        return AiTurnLock::run($loop, $requester, function () use ($loop, $requester) {
            $locale = $this->resolveLocale($requester, $loop);

            if (! $this->loopHasEnoughContent($loop)) {
                throw new \RuntimeException(__('loops.not_enough_content_to_summarize'));
            }

            $capability = CapabilityRegistry::LOOP_SUMMARY;
            $definition = $this->capabilities->get($capability);

            // La Boucle est une PORTEE a l'interieur du tenant, jamais le tenant.
            $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

            $organization = $loop->organization()->firstOrFail();

            $contexte = new ContexteIa(
                organizationId: (string) $organization->id,
                userId: (string) $requester->id,
                loopId: (string) $loop->id,
                locale: $locale,
                capability: $capability,
                correlationId: AiCorrelation::id(),
                source: CapabilityRegistry::SOURCE_LOOP_MESSAGES,
            );

            $scenarioId = (string) config('ai.chatloop.summarize_scenario', 'chatloop_ai_summarize');

            // Constitution d'abord, instruction capability ensuite. AdminAiPrompt
            // reste la source de l'instruction, il ne remplace pas la Constitution.
            // TASK-1221 : au point CANONIQUE, le prompt administrable est EXIGE —
            // plus aucun repli silencieux vers un prompt hardcode (meme regle que
            // clarify et knowledge). Le provisioning deploy-safe garantit qu'un
            // prompt actif existe des le deploiement.
            // TASK-1227 : la doctrine active de l'Organization est composee
            // sous la Constitution, au meme point que clarify et knowledge.
            $instructions = $this->prompts->compose(
                $capability,
                $this->resolvePromptOrFail($scenarioId, $locale, 'loops.ai_summary_prompt_missing'),
                (string) $organization->id,
            );
            // TASK-1236 : version de doctrine reellement composee ci-dessus,
            // tracee sur l'interaction plutot que reconstituee a posteriori.
            $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

            // TASK-1209 : plus de construction ad hoc. La capability declare ses
            // sources, le Context Builder decide ce qu'elle a le droit de lire.
            $borne = $this->contextBuilder->build($contexte, $definition);
            $context = $borne->text;

            // TASK-1212 : pas de configuration IA pour cette Organization =
            // indisponibilite explicite, avant tout appel, sans repli plateforme.
            // TASK-1229 : etat « credential absent », code stable (AiRefusedException).
            try {
                $resolved = $this->providers->resolve($capability, $contexte);
            } catch (\DomainException $exception) {
                throw AiRefusedException::notConfigured($exception);
            }

            // TASK-1229 : le demandeur est passe a la garde — son credit IA du
            // mois s'applique ici, dans l'autorite existante.
            $verdict = $this->economicGuard->authorize(
                $organization,
                $definition->process,
                $resolved->provider,
                $resolved->model,
                (float) config('ai.chatloop.summary_economic_guard.monthly_budget_usd', 2.00),
                (int) config('ai.chatloop.summary_economic_guard.monthly_unknown_limit', 10),
                $requester,
            );

            // Un refus est un refus : aucun appel SDK n'est emis. Trois etats,
            // trois messages, trois codes.
            if (! $verdict->allowed) {
                throw AiRefusedException::fromVerdict($verdict, 'loops.ai_summary_temporarily_unavailable');
            }

            $interaction = $this->generateSummaryViaSdk(
                $loop,
                $requester,
                $contexte,
                $definition,
                $resolved,
                $scenarioId,
                $instructions,
                $context,
                $doctrineVersion,
            );

            $summary = LoopSummary::fromInteraction(
                $interaction,
                (int) config('ai.chatloop.max_response_chars', 1400),
            );

            if (trim($summary->body) === '') {
                throw new \RuntimeException(__('loops.ai_empty_response'));
            }

            return $summary;
        });
    }

    /**
     * Capability `loop_decision_suggestion` — « Decision Memory IA »
     * (TASK-1327 / Premium-1).
     *
     * La capability PROPOSE, elle n'ecrit JAMAIS : `canWrite=false`,
     * `requiresHumanConfirmation=true`. Aucune ligne de `loop_decisions` n'est
     * touchee ici — la suggestion pre-remplit le formulaire de
     * `LoopDecisionsCard`, et seul `promote()` (le geste humain de TASK-1106)
     * capitalise. La suggestion validee vit dans `metadata.decision_suggestion`
     * du tour (`ai_interactions`), le motif AiShellResponder.
     *
     * Meme chaine canonique que `summarize()` : capability -> Constitution ->
     * doctrine -> prompt administrable EXIGE -> Context Builder -> provider et
     * credential de l'Organization -> AiEconomicGuard (process partage
     * `chatloop.summarize` : meme acte economique, meme seau) -> invocation SDK
     * tracee.
     *
     * Le modele recoit, APRES le contexte du Builder, un index des messages
     * candidats (id | auteur | date | extrait) derive STRICTEMENT de la
     * provenance du Builder — le motif `UserLoopsSource` : les identifiants
     * offerts sont la seule monnaie que le modele peut rendre, et
     * `validatedSuggestion()` ne retient qu'une correspondance EXACTE
     * (motif clarify, TASK-1321).
     */
    public function suggestDecision(Loop $loop, User $requester): LoopDecisionSuggestion
    {
        $this->assertCanRequest($loop, $requester);

        // Le contrat Premium-1 : ne JAMAIS proposer une capitalisation a qui
        // n'a pas le droit de consigner. La Card filtre deja son bouton et
        // revalide au geste, mais la garde vit AUSSI dans la primitive — une
        // garde laissee au seul appelant se perd.
        if (! app(LoopPermissionResolver::class)->can($requester, $loop, 'decisions.record')) {
            throw new \RuntimeException(__('loops.cards.decisions.suggest_forbidden'));
        }

        return AiTurnLock::run($loop, $requester, function () use ($loop, $requester) {
            $locale = $this->resolveLocale($requester, $loop);

            if (! $this->loopHasEnoughContent($loop)) {
                throw new \RuntimeException(__('loops.not_enough_content_to_summarize'));
            }

            $capability = CapabilityRegistry::LOOP_DECISION_SUGGESTION;
            $definition = $this->capabilities->get($capability);

            // La Boucle est une PORTEE a l'interieur du tenant, jamais le tenant.
            $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

            $organization = $loop->organization()->firstOrFail();

            $contexte = new ContexteIa(
                organizationId: (string) $organization->id,
                userId: (string) $requester->id,
                loopId: (string) $loop->id,
                locale: $locale,
                capability: $capability,
                correlationId: AiCorrelation::id(),
                source: CapabilityRegistry::SOURCE_LOOP_MESSAGES,
            );

            $scenarioId = (string) config('ai.chatloop.decision_suggestion_scenario', 'loop_decision_suggestion');

            // Constitution d'abord, doctrine de l'Organization, puis le prompt
            // administrable EXIGE — aucun repli hardcode (regle TASK-1221).
            $instructions = $this->prompts->compose(
                $capability,
                $this->resolvePromptOrFail($scenarioId, $locale, 'loops.decision_suggestion_prompt_missing'),
                (string) $organization->id,
            );
            $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

            $borne = $this->contextBuilder->build($contexte, $definition);

            $candidates = $this->decisionCandidates(
                $loop,
                $borne->provenanceFor(CapabilityRegistry::SOURCE_LOOP_MESSAGES),
            );

            if ($candidates === []) {
                // Rien a offrir au modele : aucune suggestion, aucun appel.
                return LoopDecisionSuggestion::none();
            }

            $prompt = $borne->text."\n\n".$this->candidatesBlock($candidates, $locale);

            try {
                $resolved = $this->providers->resolve($capability, $contexte);
            } catch (\DomainException $exception) {
                throw AiRefusedException::notConfigured($exception);
            }

            // Process partage avec le resume : memes bornes de garde, meme
            // seau — un 15e process rouvrirait la convergence economique
            // fermee par TASK-1286/1291 (regle TASK-1309).
            $verdict = $this->economicGuard->authorize(
                $organization,
                $definition->process,
                $resolved->provider,
                $resolved->model,
                (float) config('ai.chatloop.summary_economic_guard.monthly_budget_usd', 2.00),
                (int) config('ai.chatloop.summary_economic_guard.monthly_unknown_limit', 10),
                $requester,
            );

            if (! $verdict->allowed) {
                throw AiRefusedException::fromVerdict($verdict, 'loops.ai_summary_temporarily_unavailable');
            }

            $agent = new LoopDecisionSuggestionAgent(
                $instructions,
                (int) config('ai.chatloop.max_tokens', 512),
                // Une extraction bornee, pas une redaction creative.
                (float) config('ai.chatloop.decision_suggestion_temperature', 0.2),
            );

            $interaction = $this->generateViaSdk(
                agent: $agent,
                loop: $loop,
                requester: $requester,
                contexte: $contexte,
                definition: $definition,
                resolved: $resolved,
                scenarioId: $scenarioId,
                prompt: $prompt,
                extraMetadata: [
                    'provenance' => [
                        CapabilityRegistry::SOURCE_LOOP_MESSAGES => array_column($candidates, 'id'),
                    ],
                    'sources_used' => $borne->sourcesUsed,
                    'sources_denied' => $borne->sourcesDenied,
                ],
                doctrineVersion: $doctrineVersion,
            );

            return $this->validatedSuggestion($loop, $interaction, array_column($candidates, 'id'));
        });
    }

    /**
     * Les messages que le modele a REELLEMENT recus, hydrates pour l'index des
     * candidats — l'intersection entre la selection de la source et la
     * provenance retenue par le Builder (meme geste que `triggerMessageId()` :
     * meme ensemble, aucune derive possible).
     *
     * @param  list<array{source: string, id: string, type: string, extrait: string}>  $provenance
     * @return list<array{id: string, author: string, date: string, excerpt: string}>
     */
    private function decisionCandidates(Loop $loop, array $provenance): array
    {
        $ids = array_map(static fn (array $entry): string => (string) $entry['id'], $provenance);

        if ($ids === []) {
            return [];
        }

        $candidates = [];

        foreach ($this->loopMessages->selectMessages($loop) as $message) {
            if (! in_array((string) $message->id, $ids, true)) {
                continue;
            }

            $body = $this->loopMessages->plainText((string) $message->body);

            if ($body === '') {
                continue;
            }

            $candidates[] = [
                'id' => (string) $message->id,
                'author' => $this->loopMessages->authorOf($message),
                'date' => (string) $message->created_at?->toDateString(),
                'excerpt' => mb_substr($body, 0, 120),
            ];
        }

        return $candidates;
    }

    /**
     * L'index des candidats offert au modele. Les identifiants sont la seule
     * monnaie qu'il peut rendre — motif `UserLoopsSource`, ou la liste bornee
     * des Boucles autorisees joue exactement ce role.
     *
     * @param  list<array{id: string, author: string, date: string, excerpt: string}>  $candidates
     */
    private function candidatesBlock(array $candidates, string $locale): string
    {
        [$header, $footer] = $locale === 'en'
            ? ['--- CANDIDATE MESSAGES (id | author | date | excerpt) ---', '--- END OF CANDIDATE MESSAGES ---']
            : ['--- MESSAGES CANDIDATS (id | auteur | date | extrait) ---', '--- FIN DES MESSAGES CANDIDATS ---'];

        $lines = array_map(
            static fn (array $candidate): string => '- '.$candidate['id'].' | '.$candidate['author'].' | '.$candidate['date'].' | '.$candidate['excerpt'],
            $candidates,
        );

        return $header."\n".implode("\n", $lines)."\n".$footer;
    }

    /**
     * Revalidation serveur de la suggestion (motif clarify, TASK-1321).
     *
     * Le modele peut renvoyer n'importe quelle chaine : un identifiant
     * plausible mais inexistant, celui d'un message d'une autre Boucle ou
     * d'une autre Organization, un message supprime, un message deja promu.
     * Aucune de ces valeurs ne doit survivre. Seule une correspondance EXACTE
     * avec la liste effectivement fournie au contexte, confirmee en base dans
     * CETTE Boucle, est retenue ; tout le reste devient « aucune suggestion »,
     * qui est un resultat parfaitement valide — une suggestion sans provenance
     * verifiable ne se presente pas.
     *
     * `provenance.verified` est reconstruit ICI, independamment du texte du
     * modele, a partir du seul fait confirme : le message existe dans cette
     * Boucle, non supprime, non promu. `ai_wording` (titre + rationale) reste
     * explicitement `verified: false` — jamais fusionne avec le fait verifie.
     *
     * @param  list<string>  $candidateIds
     */
    private function validatedSuggestion(Loop $loop, AiInteraction $interaction, array $candidateIds): LoopDecisionSuggestion
    {
        $decoded = json_decode(
            JsonResponseParser::extractJsonFromText((string) $interaction->response),
            true,
        );

        if (! is_array($decoded) || ! (bool) ($decoded['decision_found'] ?? false)) {
            return $this->traceSuggestion($interaction, ['decision_found' => false], LoopDecisionSuggestion::none((string) $interaction->id));
        }

        // Les memes bornes que la surface canonique : `LoopDecisionService`
        // tronque a 255/5000, autant offrir au formulaire ce qui sera garde.
        $title = mb_substr(trim((string) ($decoded['title'] ?? '')), 0, 255);
        $rationale = mb_substr(trim((string) ($decoded['rationale'] ?? '')), 0, 5000);
        $messageId = trim((string) ($decoded['source_message_id'] ?? ''));

        if ($title === '' || $messageId === '' || ! in_array($messageId, $candidateIds, true)) {
            return $this->traceSuggestion($interaction, ['decision_found' => false], LoopDecisionSuggestion::none((string) $interaction->id));
        }

        // La confirmation en base, dans CETTE Boucle — la moderation est
        // terminale pour la promotion, y compris via une suggestion IA.
        $message = LoopMessage::where('loop_id', $loop->id)
            ->whereNull('deleted_at')
            ->whereKey($messageId)
            ->first();

        if (! $message) {
            return $this->traceSuggestion($interaction, ['decision_found' => false], LoopDecisionSuggestion::none((string) $interaction->id));
        }

        // Un message deja consigne ne se re-propose pas : deux promotions du
        // meme message feraient deux Decisions pour un seul choix.
        if (app(LoopDecisionService::class)->isPromoted($loop, $message)) {
            return $this->traceSuggestion($interaction, ['decision_found' => false], LoopDecisionSuggestion::none((string) $interaction->id));
        }

        $excerpt = mb_substr($this->loopMessages->plainText((string) $message->body), 0, 160);

        return $this->traceSuggestion(
            $interaction,
            [
                'decision_found' => true,
                'provenance' => [
                    'verified' => [
                        [
                            'type' => 'loop_message_reference',
                            'loop_message_id' => (string) $message->id,
                            'loop_id' => (string) $loop->id,
                        ],
                    ],
                    'ai_wording' => [
                        'title' => $title,
                        'rationale' => $rationale,
                        'verified' => false,
                    ],
                ],
            ],
            LoopDecisionSuggestion::found(
                messageId: (string) $message->id,
                title: $title,
                rationale: $rationale,
                excerpt: $excerpt,
                aiInteractionId: (string) $interaction->id,
            ),
        );
    }

    /**
     * La suggestion — validee ou refusee — dans la metadata du tour : c'est la
     * seule persistance de Premium-1, une trace, jamais une Decision.
     *
     * @param  array<string, mixed>  $suggestionMeta
     */
    private function traceSuggestion(AiInteraction $interaction, array $suggestionMeta, LoopDecisionSuggestion $suggestion): LoopDecisionSuggestion
    {
        $metadata = is_array($interaction->metadata) ? $interaction->metadata : [];

        $interaction->update(['metadata' => $metadata + ['decision_suggestion' => $suggestionMeta]]);

        return $suggestion;
    }

    /**
     * Appel texte du Laravel AI SDK + trace P1 unique.
     *
     * Une operation utilisateur = un `correlation_id` metier, porte par le
     * ContexteIa. Une invocation SDK = un `invocationId` distinct, genere par le
     * SDK et conserve en `metadata.sdk_invocation_id`. Les deux ne se confondent
     * jamais.
     *
     * L'instrumentation est ici, au call site, et NON dans un listener global
     * `AgentPrompted` : un listener ecrirait une seconde trace pour le meme
     * appel, alors que `ai_interactions` est le registre canonique que lit
     * `AiEconomicGuard`. Une trace produit + une trace SDK, ce serait un appel
     * compte deux fois dans le budget.
     */
    private function generateSummaryViaSdk(
        Loop $loop,
        User $requester,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $scenarioId,
        string $instructions,
        string $context,
        ?int $doctrineVersion,
    ): AiInteraction {
        $agent = new LoopSummaryAgent(
            $instructions,
            (int) config('ai.chatloop.max_tokens', 512),
            (float) config('ai.chatloop.temperature', 0.7),
        );

        return $this->generateViaSdk(
            agent: $agent,
            loop: $loop,
            requester: $requester,
            contexte: $contexte,
            definition: $definition,
            resolved: $resolved,
            scenarioId: $scenarioId,
            prompt: $context,
            extraMetadata: [],
            doctrineVersion: $doctrineVersion,
        );
    }

    /**
     * Invocation SDK canonique + trace P1 unique + ledger, partagee par le
     * resume et par « Demander a l'IA » (TASK-1233). Une operation utilisateur
     * = un `correlation_id` ; une invocation SDK = un `invocationId` distinct.
     * Provider ET modele passes EXPLICITEMENT : le SDK ne retombe sur aucun
     * defaut, et une liste a une seule entree exclut tout failover.
     *
     * @param  array<string, mixed>  $extraMetadata
     */
    private function generateViaSdk(
        LoopSummaryAgent|LoopDirectAnswerAgent|LoopDecisionSuggestionAgent $agent,
        Loop $loop,
        User $requester,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $scenarioId,
        string $prompt,
        array $extraMetadata,
        ?int $doctrineVersion,
    ): AiInteraction {
        $startedAt = microtime(true);

        try {
            // Provider ET modele passes EXPLICITEMENT : le SDK ne retombe sur
            // aucun defaut, et une liste a une seule entree exclut tout failover.
            $response = $agent->prompt(
                $prompt,
                // TASK-1212 : l'instance SDK du tenant (son credential), la
                // famille `provider` ne servant qu'a la trace et au tarif.
                provider: $resolved->instance,
                model: $resolved->model,
                timeout: $this->providerTimeout($resolved->provider),
            );
        } catch (\Throwable $exception) {
            // Trace de l'echec sans cout invente : `cost_usd` NULL et
            // `cost_unknown` NULL, l'etat « statut de cout non evalue » du
            // tri-etat P1-2. Un echec n'entre donc ni dans le budget mensuel,
            // ni dans le quota UNKNOWN.
            $this->recordInteraction(
                loop: $loop,
                requester: $requester,
                contexte: $contexte,
                definition: $definition,
                resolved: $resolved,
                scenarioId: $scenarioId,
                context: $prompt,
                extraMetadata: $extraMetadata,
                text: null,
                usage: AiUsage::notObserved(),
                costAttributes: ['cost_usd' => null, 'cost_unknown' => null],
                cost: null,
                status: 'failed',
                latencyMs: $this->elapsedMs($startedAt),
                startedAt: $startedAt,
                sdkInvocationId: null,
                failure: $exception::class,
                doctrineVersion: $doctrineVersion,
            );

            throw new \RuntimeException(__('loops.ai_error'), 0, $exception);
        }

        $usage = AiUsage::fromSdkTextTokens(
            $response->usage->promptTokens,
            $response->usage->completionTokens,
        );

        // laravel/ai v0.7.2 n'expose AUCUN cout provider (ni `Usage`, ni `Meta`).
        // On ne contourne pas le SDK par un appel HTTP secondaire pour en obtenir
        // un : le catalogue tranche, sinon UNKNOWN.
        $cost = $this->economicGuard->finalize($resolved->provider, $resolved->model, $usage);

        return $this->recordInteraction(
            loop: $loop,
            requester: $requester,
            contexte: $contexte,
            definition: $definition,
            resolved: $resolved,
            scenarioId: $scenarioId,
            context: $prompt,
            extraMetadata: $extraMetadata,
            text: trim($response->text),
            usage: $usage,
            costAttributes: $cost->traceAttributes(),
            cost: $cost,
            status: 'success',
            latencyMs: $this->elapsedMs($startedAt),
            startedAt: $startedAt,
            sdkInvocationId: $response->invocationId,
            failure: null,
            doctrineVersion: $doctrineVersion,
        );
    }

    /**
     * @param  array{cost_usd: ?float, cost_unknown: ?bool}  $costAttributes
     * @param  array<string, mixed>  $extraMetadata  provenance et sources (TASK-1233)
     */
    private function recordInteraction(
        Loop $loop,
        User $requester,
        ContexteIa $contexte,
        CapabilityDefinition $definition,
        ResolvedModel $resolved,
        string $scenarioId,
        string $context,
        array $extraMetadata,
        ?string $text,
        AiUsage $usage,
        array $costAttributes,
        ?AiCost $cost,
        string $status,
        int $latencyMs,
        ?float $startedAt,
        ?string $sdkInvocationId,
        ?string $failure,
        ?int $doctrineVersion,
    ): AiInteraction {
        // TASK-1220 : ligne canonique du ledger `ai_provider_invocations`,
        // memes points que la trace P1 (succes ET echec). Les refus
        // pre-provider (configuration absente, budget atteint) leverent avant
        // tout appel SDK : ils n'ecrivent RIEN ici — aucune consommation
        // fictive. TASK-1233 : plus aucun chemin HTTP legacy — resume et
        // « Demander a l'IA » passent tous par cette methode.
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

        return AiInteraction::create([
            'user_id' => $requester->id,
            // Le tenant vient de la Boucle, pas du contexte de requete : une
            // trace ne peut pas atterrir dans une autre Organization que celle
            // dont les messages ont ete lus.
            'organization_id' => $contexte->organizationId,
            'correlation_id' => $contexte->correlationId,
            'process' => $definition->process,
            'feature' => $scenarioId,
            'model' => $resolved->trace(),
            'prompt' => $context,
            'response' => $text,
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
                'failure' => $failure,
                ...$extraMetadata,
            ], static fn ($value): bool => $value !== null)
                // TASK-1236 : cle toujours presente, meme a null (aucune doctrine
                // active) — sa PRESENCE distingue une interaction tracee d'une
                // ligne anterieure au mecanisme, ce qu'un array_filter effacerait.
                + ['doctrine_version' => $doctrineVersion],
        ]);
    }

    private function providerTimeout(string $provider): int
    {
        $config = match ($provider) {
            'ollama' => config('ai.ollama'),
            'openrouter' => config('ai.openrouter'),
            default => config('ai.openai'),
        };

        return (int) (is_array($config) ? ($config['timeout'] ?? 30) : 30);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Dernier resume IA de la Boucle, relu depuis sa trace technique.
     *
     * Le resume n'etant plus publie en `LoopMessage` (`can_write=false`), la
     * source est `ai_interactions` : meme tenant, meme process, meme Boucle.
     * Les echecs ne peuvent pas remonter — ils n'ont pas de `response`.
     */
    public function latestSummary(Loop $loop): ?LoopSummary
    {
        $interaction = AiInteraction::query()
            ->where('organization_id', $loop->organization_id)
            ->where('process', AiProcess::fromFeature(
                (string) config('ai.chatloop.summarize_scenario', 'chatloop_ai_summarize')
            ))
            // TASK-1327 : `loop_decision_suggestion` partage VOLONTAIREMENT le
            // process `chatloop.summarize` (meme acte economique). Le dernier
            // RESUME se reconnait donc a sa feature — prefixe pour couvrir les
            // traces historiques suffixees de locale — sinon le JSON brut
            // d'une suggestion deviendrait « le dernier resume » de la carte.
            ->where('feature', 'like', (string) config('ai.chatloop.summarize_scenario', 'chatloop_ai_summarize').'%')
            ->where('metadata->loop_id', $loop->id)
            ->whereNotNull('response')
            ->where('response', '!=', '')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $interaction === null
            ? null
            : LoopSummary::fromInteraction(
                $interaction,
                (int) config('ai.chatloop.max_response_chars', 1400),
            );
    }

    /**
     * Capability `loop_ask` — « Demander a l'IA » : question d'un membre,
     * publiee (message `user`) puis repondue (message `ai`) dans la Boucle
     * (TASK-1233). Meme chaine canonique que `answer()`.
     */
    public function ask(Loop $loop, User $requester, string $question): LoopMessage
    {
        $this->assertCanRequest($loop, $requester);

        // TASK-1311 : verrou du tour, extrait dans `AiTurnLock` — meme
        // TTL, meme message de refus, meme liberation en `finally`. La cle
        // devient `{organization}:{loop}:{user}` : deux membres d'une meme
        // Boucle sont deux tours differents et ne se bloquent plus.
        return AiTurnLock::run($loop, $requester, function () use ($loop, $requester, $question) {
            $locale = $this->resolveLocale($requester, $loop);
            $scenarioId = (string) config('ai.chatloop.ask_scenario', 'chatloop_ai_ask');

            $generated = $this->generateDirectAnswer(
                loop: $loop,
                requester: $requester,
                capability: CapabilityRegistry::LOOP_ASK,
                scenarioId: $scenarioId,
                locale: $locale,
                question: trim($question),
            );

            $answer = AiMarkdownSanitizer::sanitize(
                (string) $generated['interaction']->response,
                (int) config('ai.chatloop.max_response_chars', 1400),
            );

            if ($answer === '') {
                throw new \RuntimeException(__('loops.ai_empty_response'));
            }

            return DB::transaction(function () use ($loop, $requester, $question, $answer, $generated) {
                $userMessage = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => $requester->id,
                    'reply_to_id' => null,
                    'body' => $question,
                    'image_path' => null,
                    'type' => 'user',
                    'metadata' => [
                        'asked_ai_question' => true,
                    ],
                    'organization_id' => $loop->organization_id,
                ]);

                $message = LoopMessage::create([
                    'loop_id' => $loop->id,
                    'sender_id' => null,
                    'reply_to_id' => $userMessage->id,
                    'body' => $answer,
                    'image_path' => null,
                    'type' => 'ai',
                    'metadata' => [
                        'requested_by' => $requester->id,
                        'action' => 'ask',
                        'question' => $question,
                        'context_message_ids' => $generated['context_message_ids'],
                        'trigger_message_id' => $generated['trigger_message_id'],
                        'provider' => $generated['provider'],
                        'model' => $generated['model'],
                        'ai_interaction_id' => $generated['interaction']->id,
                    ],
                    'organization_id' => $loop->organization_id,
                ]);

                event(new LoopMessageCreated($message));

                $loop->touch();

                return $message;
            });
        });
    }

    /**
     * La chaine canonique commune a `answer()` et `ask()` (TASK-1233), calquee
     * maillon par maillon sur `summarize()` :
     *
     *   capability -> scope -> ContexteIa -> PromptRepository::compose
     *   (Constitution -> doctrine active -> prompt administrable EXIGE) ->
     *   ContextBuilder (loop.messages, budget borne, provenance) ->
     *   ProviderResolver (credential Organization, aucun repli plateforme) ->
     *   AiEconomicGuard::authorize (budget Organization, process, credit du
     *   demandeur) -> SDK -> ledger + trace.
     *
     * Un refus leve AVANT tout appel provider : rien n'est ecrit, rien n'est
     * publie (la question de `ask` n'est un message qu'apres la reponse).
     *
     * @return array{interaction: AiInteraction, context_message_ids: list<string>, trigger_message_id: ?string, provider: string, model: string}
     */
    private function generateDirectAnswer(
        Loop $loop,
        User $requester,
        string $capability,
        string $scenarioId,
        string $locale,
        ?string $question,
    ): array {
        $definition = $this->capabilities->get($capability);

        // La Boucle est une PORTEE a l'interieur du tenant, jamais le tenant.
        $this->capabilities->assertScopeAllowed($capability, CapabilityRegistry::SCOPE_LOOP);

        $organization = $loop->organization()->firstOrFail();

        $contexte = new ContexteIa(
            organizationId: (string) $organization->id,
            userId: (string) $requester->id,
            loopId: (string) $loop->id,
            locale: $locale,
            capability: $capability,
            correlationId: AiCorrelation::id(),
            source: CapabilityRegistry::SOURCE_LOOP_MESSAGES,
        );

        // Constitution -> doctrine de l'Organization -> prompt administrable
        // (EXIGE, comme summarize/clarify/knowledge : aucun repli hardcode).
        // Les instructions passees ici sont, octet pour octet, le prompt
        // administrable + l'instruction de langue d'avant la migration : une
        // Organization sans doctrine active obtient la meme composition qu'une
        // capability canonique sans doctrine — invariant teste (TASK-1233).
        $instructions = $this->prompts->compose(
            $capability,
            $this->resolvePromptOrFail($scenarioId, $locale, 'loops.ai_answer_prompt_missing'),
            (string) $organization->id,
        );
        // TASK-1236 : version de doctrine reellement composee ci-dessus,
        // tracee sur l'interaction plutot que reconstituee a posteriori.
        $doctrineVersion = $this->prompts->activeDoctrineVersion((string) $organization->id);

        // Le contexte vient du Context Builder : la capability declare ses
        // sources, le Builder decide ce qu'elle a le droit de lire, et la
        // provenance (ids des messages) est celle du Builder — plus aucune
        // construction ad hoc.
        $borne = $this->contextBuilder->build($contexte, $definition);
        $context = $borne->text;

        $contextMessageIds = array_map(
            static fn (array $entry): string => (string) $entry['id'],
            $borne->provenanceFor(CapabilityRegistry::SOURCE_LOOP_MESSAGES),
        );

        // Le declencheur (`reply_to`) est le dernier message humain PARMI ceux
        // que le Builder a retenus : meme ensemble, aucune derive possible.
        $triggerMessageId = $this->triggerMessageId($loop, $contextMessageIds);

        $prompt = $question !== null && $question !== ''
            ? ($context !== '' ? $context."\n\n".'Question : '.$question : $question)
            : $context;

        // Provider et credential de l'Organization — sans configuration tenant,
        // indisponibilite explicite AVANT tout appel, aucun repli plateforme.
        try {
            $resolved = $this->providers->resolve($capability, $contexte);
        } catch (\DomainException $exception) {
            throw AiRefusedException::notConfigured($exception);
        }

        // La garde economique : budget Organization, budget du process, quota
        // d'inconnus, et le credit du demandeur — la meme autorite que partout.
        $verdict = $this->economicGuard->authorize(
            $organization,
            $definition->process,
            $resolved->provider,
            $resolved->model,
            (float) config('ai.chatloop.economic_guard.monthly_budget_usd', 2.00),
            (int) config('ai.chatloop.economic_guard.monthly_unknown_limit', 10),
            $requester,
        );

        if (! $verdict->allowed) {
            throw AiRefusedException::fromVerdict($verdict);
        }

        $agent = new LoopDirectAnswerAgent(
            $instructions,
            (int) config('ai.chatloop.max_tokens', 512),
            (float) config('ai.chatloop.temperature', 0.7),
        );

        $interaction = $this->generateViaSdk(
            agent: $agent,
            loop: $loop,
            requester: $requester,
            contexte: $contexte,
            definition: $definition,
            resolved: $resolved,
            scenarioId: $scenarioId,
            prompt: $prompt,
            extraMetadata: [
                'provenance' => [
                    CapabilityRegistry::SOURCE_LOOP_MESSAGES => $contextMessageIds,
                ],
                'sources_used' => $borne->sourcesUsed,
                'sources_denied' => $borne->sourcesDenied,
                'question' => $question,
            ],
            doctrineVersion: $doctrineVersion,
        );

        return [
            'interaction' => $interaction,
            'context_message_ids' => $contextMessageIds,
            'trigger_message_id' => $triggerMessageId,
            'provider' => $resolved->provider,
            'model' => $resolved->model,
        ];
    }

    /**
     * Le dernier message NON-IA parmi ceux retenus par le Context Builder,
     * dans l'ordre chronologique de la source (meme selection, meme ordre).
     *
     * @param  list<string>  $contextMessageIds
     */
    private function triggerMessageId(Loop $loop, array $contextMessageIds): ?string
    {
        if ($contextMessageIds === []) {
            return null;
        }

        $trigger = null;

        foreach ($this->loopMessages->selectMessages($loop) as $message) {
            if ($message->type !== 'ai' && in_array((string) $message->id, $contextMessageIds, true)) {
                $trigger = (string) $message->id;
            }
        }

        return $trigger;
    }

    public function loopHasEnoughContent(Loop $loop): bool
    {
        $minWords = (int) config('ai.chatloop.min_summary_words', 30);

        if ($minWords <= 0) {
            return true;
        }

        $limit = (int) config('ai.chatloop.max_context_messages', 30);

        $words = 0;

        $loop->messages()
            ->notDeleted()
            ->orderByDesc('created_at')
            ->limit($limit)
            ->pluck('body')
            ->each(function (?string $body) use (&$words): void {
                $body = $this->loopMessages->plainText((string) $body);

                if ($body === '') {
                    return;
                }

                $words += str_word_count($body);
            });

        return $words >= $minWords;
    }

    private function assertCanRequest(Loop $loop, User $requester): void
    {
        $membership = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $requester->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw new \RuntimeException(__('loops.not_an_active_member'));
        }

        if ($loop->organization_id !== $requester->organization_id) {
            throw new \RuntimeException(__('loops.cross_organization'));
        }
    }

    private function resolveLocale(User $requester, Loop $loop): string
    {
        $appLocale = (string) app()->getLocale();

        if (in_array($appLocale, ['fr', 'en'], true)) {
            return str_starts_with($appLocale, 'en') ? 'en' : 'fr';
        }

        $locale = $requester->preferred_locale
            ?: $loop->organization?->locale
            ?: currentOrganization()?->locale;

        return str_starts_with((string) $locale, 'en') ? 'en' : 'fr';
    }

    /**
     * Resolution STRICTE du prompt administrable d'une capability canonique
     * (TASK-1221, etendue a `loop_answer` / `loop_ask` par TASK-1233).
     *
     * Cascade DB `{scenario}_{locale}` -> `{scenario}_fr` -> `{scenario}`,
     * meme instruction de langue — mais AUCUN repli hardcode : sans prompt
     * AdminAiPrompt actif, l'indisponibilite est explicite. Un admin qui
     * desactive tous les prompts d'une capability doit le VOIR, pas etre
     * rattrape en silence par un texte fige dans le code.
     */
    private function resolvePromptOrFail(string $scenarioId, string $locale, string $missingMessageKey): string
    {
        $prompt = $this->findActivePrompt($scenarioId.'_'.$locale)
            ?? $this->findActivePrompt($scenarioId.'_fr')
            ?? $this->findActivePrompt($scenarioId);

        if ($prompt === null || trim($prompt) === '') {
            throw new \RuntimeException(__($missingMessageKey));
        }

        return $prompt.$this->languageInstruction($locale);
    }

    /**
     * Instruction de langue commune aux deux resolutions de prompt ChatLoop.
     */
    private function languageInstruction(string $locale): string
    {
        return $locale === 'en'
            ? "\n\nIMPORTANT: You MUST answer in English, whatever the language used in the conversation. Never reply in another language. Always finish your answer with a complete final sentence; never leave the answer unfinished or truncated."
            : "\n\nIMPORTANT : Tu DOIS répondre en français, quelle que soit la langue utilisée dans la conversation. Ne réponds jamais dans une autre langue. Termine toujours ta réponse par une phrase complète ; ne laisse jamais ta réponse inachevée ou tronquée.";
    }

    private function findActivePrompt(string $scenarioId): ?string
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', $scenarioId)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        return $prompt?->prompt_text;
    }
}
