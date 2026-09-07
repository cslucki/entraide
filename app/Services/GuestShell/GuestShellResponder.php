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

        $agent = new GuestShellAgent(
            $this->instructions($prompt->text, $context),
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

        $text = trim((string) $response->text);
        $answer = $this->conversations->recordAssistantMessage(
            $conversation,
            $text !== '' ? $text : __('guest_shell.fallback_empty', [], $context->locale),
            $invocation->id,
        );

        return GuestShellTurn::answered($userMessage, $answer, $invocation);
    }

    /** Le prompt en base, verbatim, PUIS le contexte public, PUIS la langue — aucun templating. */
    private function instructions(string $promptText, GuestPublicContext $context): string
    {
        return implode("\n\n", array_filter([
            trim($promptText),
            $context->text(),
            __('guest_shell.instruction_locale', ['locale' => $context->locale], $context->locale),
        ]));
    }

    /**
     * Le message actuel, precede d'un historique BORNE de la conversation
     * (les derniers messages, jamais ceux d'une autre conversation ni d'un
     * autre visiteur) — la conversation est persistante, le modele doit la voir.
     */
    private function userPrompt(GuestConversation $conversation, GuestMessage $current, string $message): string
    {
        $limit = max(0, (int) config('ai.guest_shell.history_messages', 10));
        $previous = $limit === 0 ? collect() : GuestMessage::query()
            ->where('guest_conversation_id', $conversation->getKey())
            ->whereKeyNot($current->getKey())
            ->whereIn('role', [GuestMessage::ROLE_USER, GuestMessage::ROLE_ASSISTANT])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        if ($previous->isEmpty()) {
            return trim($message);
        }

        $lines = $previous->map(fn (GuestMessage $entry) => ($entry->role === GuestMessage::ROLE_USER ? 'Visiteur' : 'Assistant').' : '.trim((string) $entry->body))->all();

        return implode("\n", $lines)."\n\nVisiteur : ".trim($message);
    }
}
