<?php

namespace App\Services\GuestShell;

use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\Organization;
use App\Models\OrganizationGuestShellPolicy;
use App\Support\GuestShell\GuestShellDisplay;
use App\Support\GuestShell\GuestShellDisplayMode;
use Illuminate\Http\Request;

/**
 * TASK-1442 — SW-8a : la SURFACE publique du Shell Welcome (Shell Welcome V3
 * §19, MASTER Q70). Deux gestes, et rien d'autre :
 *
 *  - `read()`  : LECTURE PURE pour la page publique et le GET de reprise —
 *    aucune identite Guest creee, aucun cookie pose, aucune conversation
 *    ouverte, aucun appel provider. Si un cookie VALIDE de CETTE
 *    Organization existe, la conversation active est relue.
 *  - `turn()`  : le PREMIER GESTE REEL. Les gardes structurelles qui n'ont
 *    pas besoin d'un visiteur passent d'abord (Organization publique, page
 *    eligible, politique prete, prompt actif — la decision d'affichage) ;
 *    SEULEMENT ensuite `ensure()` (cookie au premier geste), reprise ou
 *    ouverture de conversation (contrat SW-4), puis le responder SW-7 qui
 *    porte la garde SW-6 definitive avant tout appel provider.
 *
 * Un POST vers un Shell structurellement indisponible ne cree JAMAIS une
 * identite Guest. Un refus ne fabrique JAMAIS une reponse IA.
 */
final class GuestShellSurface
{
    public const TURN_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly GuestPageContextResolver $pages,
        private readonly GuestShellDisplayModeResolver $display,
        private readonly GuestShellPolicyService $policies,
        private readonly GuestVisitorResolver $visitors,
        private readonly GuestConversationService $conversations,
        private readonly GuestShellResponder $responder,
    ) {}

    /**
     * @return array{display: array{mode: string, reason: string, visible: bool, degraded: bool, preference: string}, page: array{kind: string, label: string}|null, cta: array{label: string, url: string}|null, conversation: array<string, mixed>|null, limits: array{max_input_chars: int}}
     */
    public function read(Organization $organization, Request $request): array
    {
        $page = $this->pages->organizationHome($organization);
        $decision = $this->display->resolve($organization, $page);
        $policy = $this->policies->policyFor($organization);

        $conversation = null;
        if ($decision->isVisible()) {
            $visitor = $this->visitors->find($request, $organization);
            $conversation = $visitor !== null ? $this->conversations->resume($visitor) : null;
        }

        return [
            'display' => $this->displayPayload($decision, $policy),
            'page' => $page === null ? null : ['kind' => $page->kind, 'label' => $page->publicLabel],
            'cta' => $page?->publicCta,
            'conversation' => $conversation === null ? null : $this->conversationPayload($conversation, $policy),
            'limits' => ['max_input_chars' => GuestMessage::maxUserBodyLength()],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function turn(Organization $organization, Request $request, string $message): array
    {
        $page = $this->pages->organizationHome($organization);
        $decision = $this->display->resolve($organization, $page);
        $policy = $this->policies->policyFor($organization);

        if ($page === null || ! $decision->isVisible()) {
            // Aucune identite pour un Shell indisponible : ni cookie, ni visiteur, ni conversation.
            return [
                'turn' => self::TURN_UNAVAILABLE,
                'reason' => $decision->reason,
                'step' => null,
                'display' => $this->displayPayload($decision, $policy),
                'user' => null,
                'assistant' => null,
                'conversation' => null,
                'cta' => $page?->publicCta,
            ];
        }

        $visitor = $this->visitors->ensure($request, $organization, [
            'locale' => app()->getLocale(),
            'referrer' => $request->headers->get('referer'),
            'utm_source' => $request->query('utm_source'),
            'utm_medium' => $request->query('utm_medium'),
            'utm_campaign' => $request->query('utm_campaign'),
        ]);
        $conversation = $this->conversations->resumeOrStart($visitor);
        $result = $this->responder->respond($organization, $visitor, $conversation, $message, $page);

        return [
            'turn' => $result->status,
            'reason' => $result->reason,
            'step' => $result->step,
            'display' => $this->displayPayload($decision, $policy),
            'user' => $result->userMessage === null ? null : $this->messagePayload($result->userMessage),
            'assistant' => $result->assistantMessage === null ? null : $this->messagePayload($result->assistantMessage),
            'conversation' => $this->conversationPayload($conversation->fresh(), $policy),
            'cta' => $page->publicCta,
        ];
    }

    /**
     * Le widget est monte quand le Shell est reellement disponible en overlay,
     * OU en etat DEGRADE honnete (politique activee par la plateforme mais pas
     * prete / sans prompt) : un accueil non-IA + CTA, jamais un faux echange.
     * `enabled = false` ou page non eligible = rien du tout.
     *
     * @return array{mode: string, reason: string, visible: bool, degraded: bool, preference: string}
     */
    private function displayPayload(GuestShellDisplay $decision, OrganizationGuestShellPolicy $policy): array
    {
        $degraded = ! $decision->isVisible()
            && (bool) $policy->enabled
            && in_array($decision->reason, [GuestShellDisplay::REASON_POLICY_NOT_READY, GuestShellDisplay::REASON_NO_ACTIVE_PROMPT], true);

        return [
            'mode' => $decision->mode,
            'reason' => $decision->reason,
            'visible' => $decision->isVisible(),
            'degraded' => $degraded,
            'preference' => GuestShellDisplayMode::isValid($policy->display_mode) ? $policy->display_mode : GuestShellDisplayMode::DEFAULT,
        ];
    }

    /**
     * @return array{id: string, status: string, message_count: int, remaining: int, messages: list<array{role: string, body: string, at: string}>}
     */
    private function conversationPayload(GuestConversation $conversation, OrganizationGuestShellPolicy $policy): array
    {
        return [
            'id' => (string) $conversation->getKey(),
            'status' => (string) $conversation->status,
            'message_count' => (int) $conversation->message_count,
            'remaining' => $this->conversations->remainingUserMessages($conversation, $policy),
            'messages' => $conversation->messages()
                ->whereIn('role', [GuestMessage::ROLE_USER, GuestMessage::ROLE_ASSISTANT])
                ->get()
                ->map(fn (GuestMessage $message) => $this->messagePayload($message))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{role: string, body: string, at: string}
     */
    private function messagePayload(GuestMessage $message): array
    {
        return ['role' => (string) $message->role, 'body' => (string) $message->body, 'at' => $message->created_at?->toIso8601String() ?? ''];
    }
}
