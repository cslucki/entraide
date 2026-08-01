<?php

namespace App\Services;

use App\Events\LoopMessageCreated;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
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
