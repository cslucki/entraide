<?php

namespace App\Services\GuestShell;

use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationGuestShellPolicy;
use App\Support\GuestShell\GuestShellLimitReached;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * TASK-1434 — SW-4 : la memoire produit du Shell Welcome.
 *
 * - `resume()` reprend la conversation ACTIVE la plus recente de CE visiteur
 *   dans CETTE Organization (Addendum V2 §6) ; `start()` en ouvre une
 *   nouvelle ;
 * - `acceptUserMessage()` est la SEULE porte d'entree d'un message visiteur :
 *   elle applique `max_messages` (messages `role=user` de la conversation)
 *   AVANT que quiconque appelle un provider — a la limite, la conversation
 *   est preservee, marquee `limit_reached`, et rien ne part ;
 * - `userMessagesThisMonth()` est le compteur TRANSVERSE du visiteur (toutes
 *   conversations) : SW-6 s'en sert pour qu'une « nouvelle conversation »
 *   ne soit jamais un reset economique ;
 * - aucun message n'est jamais reecrit.
 */
final class GuestConversationService
{
    public function __construct(private readonly GuestShellPolicyService $policies) {}

    public function resume(GuestVisitor $visitor): ?GuestConversation
    {
        return GuestConversation::forOrganization($visitor->organization_id)
            ->forVisitor($visitor)
            ->where('status', '!=', GuestConversation::STATUS_CLOSED)
            ->orderByDesc('last_message_at')
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * @param  array{locale?: string|null, source?: string|null, source_ref?: string|null}  $attributes
     */
    public function start(GuestVisitor $visitor, array $attributes = []): GuestConversation
    {
        return GuestConversation::create([
            'organization_id' => $visitor->organization_id,
            'guest_visitor_id' => $visitor->id,
            'locale' => $this->clean($attributes['locale'] ?? $visitor->locale, 5),
            'status' => GuestConversation::STATUS_ACTIVE,
            'message_count' => 0,
            'started_at' => now(),
            'source' => $this->clean($attributes['source'] ?? null, 40),
            'source_ref' => $this->clean($attributes['source_ref'] ?? null, 255),
        ]);
    }

    /** La conversation, ou une nouvelle si le visiteur n'en a aucune reprenable. */
    public function resumeOrStart(GuestVisitor $visitor, array $attributes = []): GuestConversation
    {
        return $this->resume($visitor) ?? $this->start($visitor, $attributes);
    }

    /**
     * Enregistre un message visiteur SI la politique le permet encore.
     * Ordre : conversation active → corps borne → limite `max_messages`.
     * A la limite : statut `limit_reached`, message NON enregistre, exception —
     * l'appelant n'a donc jamais de raison d'appeler un provider.
     *
     * @throws GuestShellLimitReached
     */
    public function acceptUserMessage(GuestConversation $conversation, string $body): GuestMessage
    {
        if (! $conversation->isActive()) {
            throw new GuestShellLimitReached($conversation, 'conversation_not_active');
        }

        $body = trim($body);

        if ($body === '' || mb_strlen($body) > GuestMessage::maxUserBodyLength()) {
            throw new LogicException('A guest message must be 1 to '.GuestMessage::maxUserBodyLength().' characters.');
        }

        $policy = $this->policies->policyFor($conversation->organization);

        return DB::transaction(function () use ($conversation, $body, $policy) {
            // Verrou de ligne : deux onglets ne franchissent pas la limite ensemble.
            $locked = GuestConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            if ($locked->message_count >= $policy->max_messages) {
                $locked->forceFill(['status' => GuestConversation::STATUS_LIMIT_REACHED])->save();
                $conversation->setRawAttributes($locked->getAttributes(), true);

                throw new GuestShellLimitReached($conversation, 'max_messages_reached');
            }

            $message = $this->append($locked, GuestMessage::ROLE_USER, $body, null);

            $locked->forceFill([
                'message_count' => $locked->message_count + 1,
                'last_message_at' => now(),
                'status' => $locked->message_count + 1 >= $policy->max_messages ? GuestConversation::STATUS_LIMIT_REACHED : GuestConversation::STATUS_ACTIVE,
            ])->save();
            $conversation->setRawAttributes($locked->getAttributes(), true);

            return $message;
        });
    }

    /** La reponse produite (ou le message de service) : jamais decomptee dans `max_messages`. */
    public function recordAssistantMessage(GuestConversation $conversation, string $body, ?string $invocationId = null, string $role = GuestMessage::ROLE_ASSISTANT): GuestMessage
    {
        if (! in_array($role, [GuestMessage::ROLE_ASSISTANT, GuestMessage::ROLE_SYSTEM], true)) {
            throw new LogicException("Role [{$role}] is not an assistant-side role.");
        }

        $message = $this->append($conversation, $role, trim($body), $invocationId);
        $conversation->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    public function close(GuestConversation $conversation): GuestConversation
    {
        $conversation->forceFill(['status' => GuestConversation::STATUS_CLOSED])->save();

        return $conversation;
    }

    /** Messages visiteur de CE visiteur ce mois-ci, TOUTES conversations confondues (quota transverse, SW-6). */
    public function userMessagesThisMonth(GuestVisitor $visitor): int
    {
        return GuestMessage::query()
            ->where('organization_id', $visitor->organization_id)
            ->where('role', GuestMessage::ROLE_USER)
            ->whereIn('guest_conversation_id', GuestConversation::forVisitor($visitor)->select('id'))
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function remainingUserMessages(GuestConversation $conversation, ?OrganizationGuestShellPolicy $policy = null): int
    {
        $policy ??= $this->policies->policyFor($conversation->organization);

        return max(0, $policy->max_messages - $conversation->message_count);
    }

    private function append(GuestConversation $conversation, string $role, string $body, ?string $invocationId): GuestMessage
    {
        return GuestMessage::create([
            'organization_id' => $conversation->organization_id,
            'guest_conversation_id' => $conversation->id,
            'role' => $role,
            'body' => $body,
            'ai_provider_invocation_id' => $invocationId,
        ]);
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
