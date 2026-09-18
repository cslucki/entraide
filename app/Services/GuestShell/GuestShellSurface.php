<?php

namespace App\Services\GuestShell;

use App\Models\AcquisitionEvent;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\Organization;
use App\Models\OrganizationGuestShellPolicy;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\Acquisition\GuestAttribution;
use App\Support\GuestShell\GuestPageContext;
use App\Support\GuestShell\GuestShellClearance;
use App\Support\GuestShell\GuestShellDisplay;
use App\Support\GuestShell\GuestShellDisplayMode;
use App\Support\GuestShell\GuestShellTurn;
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
        private readonly GuestAttribution $attribution,
        private readonly AcquisitionEventRecorder $events,
        private readonly GuestIdentityThrottle $identities,
    ) {}

    /**
     * @return array{display: array{mode: string, reason: string, visible: bool, degraded: bool, preference: string}, page: array{kind: string, label: string}|null, cta: array{label: string, url: string}|null, conversation: array<string, mixed>|null, limits: array{max_input_chars: int}}
     */
    public function read(Organization $organization, Request $request): array
    {
        $page = $this->pages->organizationHome($organization);
        $decision = $this->decide($organization, $page, $request);
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
    /**
     * @param  array<string, mixed>  $claimed  ce que le navigateur declare (shortcut + UTM) — relu en base, jamais cru sur parole (TASK-1447)
     */
    public function turn(Organization $organization, Request $request, string $message, array $claimed = []): array
    {
        $page = $this->pages->organizationHome($organization);
        $decision = $this->decide($organization, $page, $request);
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

        // TASK-1460 (V3 §3, audit F1) : anti-rafale PRE-IDENTITE par Organization — avant toute creation de visiteur ;
        // un visiteur deja porteur de son cookie n'est pas concerne (sa rafale est celle du gate).
        if ($this->visitors->find($request, $organization) === null && ! $this->identities->allowNewIdentity($organization)) {
            $refusal = GuestShellTurn::refused(GuestShellClearance::STEP_ORGANIZATION, GuestIdentityThrottle::REASON);

            return [
                'turn' => $refusal->status,
                'reason' => $refusal->reason,
                'step' => $refusal->step,
                'display' => $this->displayPayload($decision, $policy),
                'user' => null,
                'assistant' => null,
                'conversation' => null,
                'cta' => $page->publicCta,
                'retry_after_seconds' => $this->identities->retryAfterSeconds($organization),
            ];
        }

        $visitor = $this->visitors->ensure($request, $organization, [
            'locale' => app()->getLocale(),
            'referrer' => $request->headers->get('referer'),
        ] + $this->attribution->resolve($organization, $claimed));
        // TASK-1449 (V3 §5) : les faits de cycle de vie, une fois chacun, avec l'attribution FIRST TOUCH du visiteur.
        if ($visitor->wasRecentlyCreated) {
            rescue(fn () => $this->events->record($organization, AcquisitionEvent::GUEST_CREATED, $this->events->visitorDimensions($visitor), [], AcquisitionEvent::GUEST_CREATED.':visitor:'.$visitor->getKey()));
        }
        $conversation = $this->conversations->resumeOrStart($visitor);
        if ($conversation->wasRecentlyCreated) {
            rescue(fn () => $this->events->record($organization, AcquisitionEvent::CONVERSATION_STARTED, $this->events->visitorDimensions($visitor) + ['conversation' => $conversation], [], AcquisitionEvent::CONVERSATION_STARTED.':conversation:'.$conversation->getKey()));
        }
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
     * TASK-1445 (MASTER Q73) : un utilisateur AUTHENTIFIE n'a jamais le Guest Shell —
     * ni rendu, ni lecture, ni geste (aucune identite Guest, aucune invocation) :
     * l'experience membre seulement. Decide cote serveur, avant tout le reste.
     */
    private function decide(Organization $organization, ?GuestPageContext $page, Request $request): GuestShellDisplay
    {
        if ($request->user() !== null) {
            return GuestShellDisplay::off(GuestShellDisplay::REASON_AUTHENTICATED);
        }

        return $this->display->resolve($organization, $page);
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
    /**
     * TASK-1499 — la charge utile d'un message porte desormais son rendu et son
     * heure, en plus de son texte brut.
     *
     * `html` passe par le MEME `markdown()` que la bulle membre et la ChatLoop
     * (`components/conversation/message-bubble`) : CommonMark + GFM, avec
     * `html_input => 'escape'` et `allow_unsafe_links => false`. Aucun moteur
     * parallele n'est introduit — c'est la condition posee par MASTER, et c'est
     * aussi ce qui rend l'insertion cliente sure : le HTML brut d'un modele est
     * ECHAPPE a la source, jamais interprete.
     *
     * Mesure qui a rendu ceci necessaire : le modele renvoyait
     * `[Creer un compte](https://.../register)` et le Shell Guest l'affichait
     * littéralement, en Markdown brut.
     *
     * `at_label` est l'heure telle que la ChatLoop canonique l'ecrit
     * (`diffForHumans()`), pas un format invente ici.
     */
    private function messagePayload(GuestMessage $message): array
    {
        return [
            'role' => (string) $message->role,
            'body' => (string) $message->body,
            'html' => markdown((string) $message->body),
            'at' => $message->created_at?->toIso8601String() ?? '',
            'at_label' => $message->created_at?->diffForHumans() ?? '',
        ];
    }
}
