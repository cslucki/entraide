<?php

namespace App\Services;

use App\Events\LoopMessageCreated;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LoopMessageService
{
    public function sendUserMessage(Loop $loop, User $sender, string $body, ?array $metadata = null, ?string $replyToId = null, ?string $imagePath = null): LoopMessage
    {
        $this->assertCanSend($loop, $sender);

        if ($replyToId !== null) {
            $parent = LoopMessage::where('id', $replyToId)
                ->where('loop_id', $loop->id)
                ->exists();

            if (! $parent) {
                $replyToId = null;
            }
        }

        return DB::transaction(function () use ($loop, $sender, $body, $metadata, $replyToId, $imagePath) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'reply_to_id' => $replyToId,
                'body' => $body,
                'image_path' => $imagePath,
                'type' => 'user',
                'metadata' => $metadata,
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    public function sendHelpRequestMessage(
        Loop $loop,
        User $sender,
        string $body,
        string $title,
        string $need,
        string $context,
        string $expectedHelpType,
        ?array $deadline = null,
        string $urgency = 'normal',
    ): LoopMessage {
        $this->assertCanSend($loop, $sender);

        return DB::transaction(function () use ($loop, $sender, $body, $title, $need, $context, $expectedHelpType, $deadline, $urgency) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'help_request',
                'metadata' => [
                    'title' => $title,
                    'need' => $need,
                    'context' => $context,
                    'expected_help_type' => $expectedHelpType,
                    'deadline' => $deadline,
                    'urgency' => $urgency,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * Annonce ChatLoop d'une Demande canonique deja creee.
     *
     * Le body n'est qu'un libelle d'annonce impose par `loop_messages`; la
     * carte relit titre, description et statut sur `ServiceRequest`. Les deux
     * marqueurs metadata identifient cette ligne comme projection et servent
     * aussi au listener agent pour rester silencieux.
     */
    public function sendServiceRequestProjection(
        Loop $loop,
        User $sender,
        ServiceRequest $request,
    ): LoopMessage {
        $this->assertCanSend($loop, $sender);

        if ($loop->status !== 'active'
            || $request->organization_id !== $loop->organization_id
            || $request->organization_id !== $sender->organization_id
            || $request->user_id !== $sender->id
            || ! in_array($request->status, ['open', 'in_progress'], true)) {
            throw new \RuntimeException('The service request cannot be projected into this loop.');
        }

        return DB::transaction(function () use ($loop, $sender, $request) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => __('requests.chat_projection_body', ['title' => $request->title]),
                'type' => 'help_request',
                'metadata' => [
                    'projection_type' => 'service_request',
                    'service_request_id' => $request->id,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * Annoncer dans ChatLoop qu'un Sondage a ete pose ou clos.
     *
     * Suit exactement le motif de sendHelpRequestMessage() : un `type` propre et
     * un `metadata` structure, que la vue reconnait pour rendre autre chose
     * qu'une bulle de conversation. Aucune seconde architecture de messages
     * systeme n'a ete inventee — celle-ci existait, elle sert.
     *
     * Deux evenements seulement, jamais un par vote : une Boucle qui vote a
     * vingt n'a pas besoin de vingt lignes dans sa conversation.
     *
     * @param  'created'|'closed'  $event
     */
    public function sendPollEventMessage(Loop $loop, User $sender, LoopPoll $poll, string $event): LoopMessage
    {
        $this->assertCanSend($loop, $sender);

        $body = $event === 'closed'
            ? __('polls.chat_closed', ['question' => $poll->question])
            : __('polls.chat_created', ['question' => $poll->question]);

        return DB::transaction(function () use ($loop, $sender, $poll, $event, $body) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'poll_event',
                'metadata' => [
                    'event' => $event,
                    'poll_id' => $poll->id,
                    'question' => $poll->question,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * Annoncer dans ChatLoop qu'une rencontre est proposee, deplacee ou annulee.
     *
     * Meme motif que sendPollEventMessage(), et pour la meme raison : ChatLoop
     * reste le centre vivant de la Boucle, donc ce qui s'y passe ailleurs doit y
     * laisser une trace lisible. Trois evenements seulement, et jamais un par
     * reponse — une Boucle qui organise une reunion a dix n'a pas besoin de dix
     * lignes.
     *
     * @param  'created'|'updated'|'cancelled'  $event
     */
    public function sendEventMessage(Loop $loop, User $sender, LoopEvent $event, string $eventKind): LoopMessage
    {
        $this->assertCanSend($loop, $sender);

        $body = match ($eventKind) {
            'cancelled' => __('events.chat_cancelled', ['title' => $event->title]),
            'updated' => __('events.chat_updated', ['title' => $event->title]),
            default => __('events.chat_created', ['title' => $event->title]),
        };

        return DB::transaction(function () use ($loop, $sender, $event, $eventKind, $body) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'loop_event',
                'metadata' => [
                    'event' => $eventKind,
                    'event_id' => $event->id,
                    'title' => $event->title,
                    // Une date absolue en UTC plus le fuseau : le message reste
                    // juste meme relu depuis un autre continent.
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'timezone' => $event->timezone,
                    'format' => $event->format,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    public function updateUserMessage(Loop $loop, LoopMessage $message, User $user, string $body, ?array $metadata = null): LoopMessage
    {
        $this->assertCanSend($loop, $user);
        $this->assertMessageBelongsToLoop($loop, $message);

        if (! $message->isEditableBy($user)) {
            throw new \RuntimeException('Message cannot be edited.');
        }

        $body = trim($body);

        if ($body === '') {
            throw new \RuntimeException('Message body is required.');
        }

        $message->forceFill([
            'body' => $body,
            'metadata' => $metadata,
            'edited_at' => now(),
        ])->save();

        $loop->touch();

        return $message;
    }

    public function deleteMessage(Loop $loop, LoopMessage $message, User $user): void
    {
        $this->assertMessageBelongsToLoop($loop, $message);

        if (! $this->canDeleteMessage($loop, $user)) {
            throw new \RuntimeException('Message cannot be deleted.');
        }

        $message->forceFill([
            'deleted_at' => now(),
            'deleted_by' => $user->id,
            'pinned_at' => null,
            'pinned_by_id' => null,
        ])->save();

        $loop->touch();
    }

    private function assertCanSend(Loop $loop, User $sender): void
    {
        $membership = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $sender->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw new \RuntimeException('User is not an active member of this loop.');
        }

        $orgId = $sender->organization_id;

        if ($loop->organization_id !== $orgId) {
            throw new \RuntimeException('User does not belong to the same organization as this loop.');
        }
    }

    private function assertMessageBelongsToLoop(Loop $loop, LoopMessage $message): void
    {
        if ($message->loop_id !== $loop->id || $message->organization_id !== $loop->organization_id) {
            throw new \RuntimeException('Message not found.');
        }
    }

    public function canDeleteMessage(Loop $loop, User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($loop->organization_id !== $user->organization_id) {
            return false;
        }

        $role = $this->activeMembershipRole($loop, $user);

        return $role !== null && in_array($role, ['owner', 'moderator'], true);
    }

    private function activeMembershipRole(Loop $loop, User $user): ?string
    {
        return LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');
    }
}
