<?php

namespace App\Jobs;

use App\Events\LoopMessageCreated;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberAiProfileInteraction;
use App\Services\Ai\MemberProfileAgentResponder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAiAgentResponse implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Loop $loop,
        public LoopMessage $message,
    ) {}

    public function handle(MemberProfileAgentResponder $responder): void
    {
        $profile = $this->loop->memberAiProfile;

        if (! $profile) {
            return;
        }

        $sender = $this->message->sender;

        if (! $sender) {
            return;
        }

        if ($sender->id === $profile->user_id) {
            return;
        }

        $result = $responder->answerWithDefaultProvider($profile, $this->message->body);

        $responseMessage = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $profile->user_id,
            'body' => $result['response'],
            'type' => 'user',
            'metadata' => ['ai_generated' => true],
            'organization_id' => $this->loop->organization_id,
        ]);

        event(new LoopMessageCreated($responseMessage));

        $this->loop->touch();

        MemberAiProfileInteraction::create([
            'organization_id' => $this->loop->organization_id,
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $profile->user_id,
            'visitor_user_id' => $sender->id,
            'visitor_type' => 'user',
            'provider' => $result['provider'] ?? null,
            'model' => $result['model'] ?? null,
            'status' => 'success',
            'question' => $this->message->body,
            'response' => $result['response'],
            'matched_fields' => $result['fields'] ?? [],
            'latency_ms' => $result['latency_ms'] ?? null,
        ]);
    }
}
