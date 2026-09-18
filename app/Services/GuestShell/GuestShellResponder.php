<?php

namespace App\Services\GuestShell;

use App\Ai\Agents\GuestShellAgent;
use App\Ai\CapabilityRegistry;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\UsageReference;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Support\Ai\AiEconomicGuard;
use App\Support\Ai\AiUsage;
use App\Support\GuestShell\GuestPageContext;
use App\Support\GuestShell\GuestPublicContext;
use App\Support\GuestShell\GuestShellClearance;
use App\Support\GuestShell\GuestShellLimitReached;
use App\Support\GuestShell\GuestShellTurn;
use Illuminate\Support\Str;
use Throwable;

/**
 * TASK-1437 — SW-7 : le premier appel provider du Shell Welcome, et sa
 * comptabilite exacte (Addendum V2 §9, cadre Cyril §5/§7, MASTER Q62/Q63).
 *
 * Sequence, dans cet ordre et sans raccourci :
 *   garde SW-6 (qui exige le prompt EN BASE, SW-2) → contexte PUBLIC (SW-5) → message
 *   visiteur accepte (SW-4) → appel provider avec le credential de
 *   l'Organization et la borne de sortie de la garde → usage observe →
 *   AiEconomicGuard::finalize → ligne `ai_provider_invocations` (user_id
 *   NULL, capability guest_shell_welcome, process guest_shell) → message
 *   assistant relie a l'invocation.
 *
 * Un refus AVANT l'appel n'ecrit rien. Un echec APRES l'appel garde la verite
 * disponible (status failed, usage si observe, cout inconnu explicite sinon,
 * erreur bornee — jamais une cle ni un prompt), conserve le message visiteur
 * (le tour est consomme) et enregistre un message assistant de repli local.
 * Le Guest n'ecrit JAMAIS `ai_interactions`.
 */
final class GuestShellResponder
{
    /** Repli du budget caracteres de la fenetre d'historique (WP-D §2). */
    private const HISTORY_MAX_CHARS_FALLBACK = 8000;

    public function __construct(
        private readonly GuestShellGate $gate,
        private readonly GuestPublicContextBuilder $context,
        private readonly GuestConversationService $conversations,
        private readonly AiEconomicGuard $economy,
        private readonly AiProviderInvocationLedger $ledger,
    ) {}

    /** @param  GuestPageContext|null  $page  ou se trouve le visiteur (TASK-1440) — compose dans le contexte, jamais dans le prompt DB. */
    public function respond(Organization $organization, GuestVisitor $visitor, GuestConversation $conversation, string $message, ?GuestPageContext $page = null): GuestShellTurn
    {
        $clearance = $this->gate->clear($organization, $visitor, $conversation, $message);
        if ($clearance->isRefused()) {
            return GuestShellTurn::refused((string) $clearance->step, (string) $clearance->reason);
        }

        // Le prompt vient de la base et la GARDE l'a deja exige (V3 §13, 4e controle) :
        // un laissez-passer sans prompt est une faute de code, pas une situation metier.
        $prompt = $clearance->prompt ?? throw new \LogicException('A guest shell clearance must carry the active prompt.');

        // Le contexte public de l'Organization (SW-5) ; la garde a deja verifie active + publique.
        $context = $this->context->build($organization, UsageReference::SURFACE_SHELL_WELCOME, $page);
        if ($context === null) {
            return GuestShellTurn::refused(GuestShellClearance::STEP_ORGANIZATION, 'organization_not_public');
        }

        try {
            $userMessage = $this->conversations->acceptUserMessage($conversation, $message);
        } catch (GuestShellLimitReached $exception) {
            return GuestShellTurn::refused(GuestShellClearance::STEP_CONVERSATION_LIMIT, $exception->reason);
        }

        $continuing = $this->history($conversation, $userMessage)->isNotEmpty();

        $agent = new GuestShellAgent(
            $this->instructions($prompt->text, $context, $continuing),
            $clearance->maxOutputTokens,
            (float) config('ai.guest_shell.temperature', 0.4),
        );
        $resolved = $clearance->resolved;
        $startedAt = microtime(true);

        try {
            $response = $agent->prompt(
                $this->userPrompt($conversation, $userMessage, $message),
                provider: $resolved->instance,
                model: $resolved->model,
            );
        } catch (Throwable $exception) {
            // L'appel a ete TENTE : le tour est consomme, la verite disponible va au ledger.
            $invocation = $this->ledger->recordGeneration(
                organizationId: (string) $organization->getKey(),
                userId: null,
                capability: CapabilityRegistry::GUEST_SHELL_WELCOME,
                process: GuestShellPolicyService::PROCESS,
                resolved: $resolved,
                usage: AiUsage::notObserved(),
                cost: null,
                status: 'failed',
                correlationId: $clearance->correlationId,
                sdkInvocationId: null,
                failureReason: Str::limit($exception::class, 120, ''),
                startedAtMicrotime: $startedAt,
            );
            report($exception);

            $fallback = $this->conversations->recordAssistantMessage(
                $conversation,
                __('guest_shell.fallback_unavailable', [], $context->locale),
                $invocation->id,
            );

            return GuestShellTurn::failed($userMessage, $fallback, $invocation, 'provider_failed');
        }

        // TASK-1460 (V3 §3, audit F3) : la reponse EST facturee — si la mesure ou le ledger echouent apres coup,
        // le cout entre quand meme au ledger (unknown, failed : T1448 le compte dans les operations inconnues) et le
        // visiteur recoit le repli honnete ; jamais un cout reel sans trace.
        try {
            $usage = AiUsage::fromSdkTextTokens($response->usage->promptTokens, $response->usage->completionTokens);
            $cost = $this->economy->finalize($resolved->provider, $resolved->model, $usage);

            $invocation = $this->ledger->recordGeneration(
                organizationId: (string) $organization->getKey(),
                userId: null,
                capability: CapabilityRegistry::GUEST_SHELL_WELCOME,
                process: GuestShellPolicyService::PROCESS,
                resolved: $resolved,
                usage: $usage,
                cost: $cost,
                status: 'success',
                correlationId: $clearance->correlationId,
                sdkInvocationId: $response->invocationId ?? null,
                failureReason: null,
                startedAtMicrotime: $startedAt,
            );
        } catch (Throwable $exception) {
            $invocation = $this->ledger->recordGeneration(
                organizationId: (string) $organization->getKey(),
                userId: null,
                capability: CapabilityRegistry::GUEST_SHELL_WELCOME,
                process: GuestShellPolicyService::PROCESS,
                resolved: $resolved,
                usage: AiUsage::notObserved(),
                cost: null,
                status: 'failed',
                correlationId: $clearance->correlationId,
                sdkInvocationId: $response->invocationId ?? null,
                failureReason: Str::limit('ledger:'.$exception::class, 120, ''),
                startedAtMicrotime: $startedAt,
            );
            report($exception);

            $fallback = $this->conversations->recordAssistantMessage(
                $conversation,
                __('guest_shell.fallback_unavailable', [], $context->locale),
                $invocation->id,
            );

            return GuestShellTurn::failed($userMessage, $fallback, $invocation, 'ledger_failed');
        }

        $text = trim((string) $response->text);
        $answer = $this->conversations->recordAssistantMessage(
            $conversation,
            $text !== '' ? $text : __('guest_shell.fallback_empty', [], $context->locale),
            $invocation->id,
        );

        return GuestShellTurn::answered($userMessage, $answer, $invocation);
    }

    /**
     * Le prompt en base, verbatim, PUIS le contexte public, PUIS la langue, PUIS
     * l'etat de la CONVERSATION — aucun templating.
     *
     * ## Pourquoi l'etat de conversation est une INSTRUCTION et pas du contexte
     *
     * Mesure sur la conversation reelle de Cyril (`01a0873b`) :
     *
     * | Tour | Message | Reponse |
     * |---|---|---|
     * | 1 | « Je m'appelle CYril... » | « **Bonjour Cyril !** Bienvenue... » |
     * | 2 | « Oui » | « Super, Cyril ! L'atelier... » |
     * | 3 | « Oui combien ca coute ? » | « **Bonjour Cyril !** Je ne peux pas... » |
     * | 4 | « C'est quoi le mycelium ? » | « **Bonjour Cyril !** Le mycelium... » |
     *
     * Le tour 2 prouve que l'historique EST transmis et fonctionne : le modele
     * y retrouve le prenom ET le sujet precedent. Les tours 3 et 4 connaissent
     * encore « Cyril », que le visiteur n'a jamais repete.
     *
     * Le defaut n'etait donc PAS un historique manquant. Le prompt en base dit
     * « Tu l'ACCUEILLES au nom de cette organisation » — une instruction juste
     * au premier tour, fausse a tous les suivants. Rien ne disait au modele
     * qu'il etait deja en conversation.
     *
     * On ne touche pas au prompt en base : c'est une donnee de plateforme, et
     * le meme prompt doit rester correct pour un premier tour. L'etat, lui, est
     * un fait de RUNTIME — sa place est ici, a cote de la langue et du contexte.
     */
    private function instructions(string $promptText, GuestPublicContext $context, bool $continuing): string
    {
        return implode("\n\n", array_filter([
            trim($promptText),
            $context->text(),
            __('guest_shell.instruction_locale', ['locale' => $context->locale], $context->locale),
            $continuing ? __('guest_shell.instruction_continuing', [], $context->locale) : null,
        ]));
    }

    /**
     * Le message actuel, precede d'un historique BORNE de la conversation
     * (les derniers messages, jamais ceux d'une autre conversation ni d'un
     * autre visiteur) — la conversation est persistante, le modele doit la voir.
     */
    /**
     * La fenetre bornee des messages precedents de CETTE conversation.
     *
     * @return \Illuminate\Support\Collection<int, GuestMessage>
     */
    private function history(GuestConversation $conversation, GuestMessage $current)
    {
        $limit = max(0, (int) config('ai.guest_shell.history_messages', 10));

        if ($limit === 0) {
            return collect();
        }

        $window = GuestMessage::query()
            ->where('guest_conversation_id', $conversation->getKey())
            ->whereKeyNot($current->getKey())
            ->whereIn('role', [GuestMessage::ROLE_USER, GuestMessage::ROLE_ASSISTANT])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->withinCharacterBudget($window)->reverse()->values();
    }

    /**
     * Le budget CARACTERES de la fenetre, en plus du nombre de messages.
     *
     * WP-D §2 : « la limite de conversation Guest et la fenetre envoyee au
     * provider doivent etre coherentes, avec un budget caracteres/tokens
     * explicite et fail-safe ».
     *
     * Sans lui, la borne n'existait qu'en NOMBRE de messages : dix tours a
     * `ai.shell.max_input_chars` (2000) plus les reponses, c'est une charge
     * utile que rien ne plafonnait. Un compteur de messages n'est pas un
     * budget.
     *
     * On coupe par les messages les PLUS ANCIENS — la collection arrive
     * ordonnee du plus recent au plus ancien —, parce que la continuite se joue
     * sur les derniers tours : c'est « ca » et « oui » qu'il faut pouvoir
     * resoudre, pas le tout premier message.
     *
     * Fail-safe : un budget absent, nul ou negatif ne desactive pas la borne,
     * il retombe sur la valeur par defaut. Une garde qu'une configuration vide
     * peut ouvrir n'est pas une garde.
     *
     * @param  \Illuminate\Support\Collection<int, GuestMessage>  $recentFirst
     * @return \Illuminate\Support\Collection<int, GuestMessage>
     */
    private function withinCharacterBudget($recentFirst)
    {
        $budget = (int) config('ai.guest_shell.history_max_chars', self::HISTORY_MAX_CHARS_FALLBACK);

        if ($budget <= 0) {
            $budget = self::HISTORY_MAX_CHARS_FALLBACK;
        }

        $kept = collect();
        $used = 0;

        foreach ($recentFirst as $entry) {
            $cost = mb_strlen(trim((string) $entry->body));

            if ($used + $cost > $budget) {
                break;
            }

            $used += $cost;
            $kept->push($entry);
        }

        return $kept;
    }

    /**
     * Le transcrit borne, puis le tour COURANT explicitement etiquete.
     *
     * L'etiquette n'est pas cosmetique. Le SDK envoie cette chaine comme UN
     * SEUL message utilisateur : sans marqueur, le modele voit un bloc de texte
     * ou son propre tour precedent et la question du jour se confondent. C'est
     * ce qui faisait echouer l'anaphore — « Oui combien ca coute ? » repondu
     * comme une question isolee sur les couts, au lieu de porter sur l'atelier
     * du tour precedent.
     */
    private function userPrompt(GuestConversation $conversation, GuestMessage $current, string $message): string
    {
        $previous = $this->history($conversation, $current);

        if ($previous->isEmpty()) {
            return trim($message);
        }

        $lines = $previous->map(fn (GuestMessage $entry) => ($entry->role === GuestMessage::ROLE_USER ? 'Visiteur' : 'Assistant').' : '.trim((string) $entry->body))->all();

        return "[CONVERSATION EN COURS]\n".implode("\n", $lines)."\n\n[MESSAGE ACTUEL]\nVisiteur : ".trim($message);
    }
}
