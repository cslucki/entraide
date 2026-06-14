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
    public function sendUserMessage(Loop $loop, User $sender, string $body, ?array $metadata = null, ?string $replyToId = null): LoopMessage
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

        return DB::transaction(function () use ($loop, $sender, $body, $metadata, $replyToId) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'reply_to_id' => $replyToId,
                'body' => $body,
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
}
