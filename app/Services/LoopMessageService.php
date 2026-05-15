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
    public function sendUserMessage(Loop $loop, User $sender, string $body, ?array $metadata = null): LoopMessage
    {
        $membership = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $sender->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw new \RuntimeException('User is not an active member of this loop.');
        }

        if ($loop->community_id !== $sender->community_id) {
            throw new \RuntimeException('User does not belong to the same community as this loop.');
        }

        return DB::transaction(function () use ($loop, $sender, $body, $metadata) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'user',
                'metadata' => $metadata,
            ]);

            event(new LoopMessageCreated($message));

            return $message;
        });
    }
}
