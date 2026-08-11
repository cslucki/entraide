<?php

namespace App\Jobs;

use App\Events\LoopMessageCreated;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberAiProfileInteraction;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiProcess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateAiAgentResponse implements ShouldQueue
{
    use Queueable;

    /**
     * TASK-1131 — propagation asynchrone de la corrélation.
     *
     * `$correlationId` est figé au moment du DISPATCH, c'est-à-dire dans
     * l'opération d'origine (le message posté dans la boucle). Il est sérialisé
     * avec le job, donc :
     * - une même opération conserve sa corrélation jusqu'à l'écriture de trace ;
     * - un retry rejoue le même payload et conserve donc la même corrélation ;
     * - deux opérations distinctes produisent deux jobs et deux corrélations.
     *
     * La corrélation n'est jamais recréée à l'exécution.
     */
    public function __construct(
        public Loop $loop,
        public LoopMessage $message,
        public ?string $correlationId = null,
    ) {
        $this->correlationId = $correlationId ?? AiCorrelation::id();
    }

    public function handle(MemberProfileAgentResponder $responder): void
    {
        // Réadopte la corrélation de l'opération d'origine : le worker exécute
        // ce job dans un scope neuf, sans quoi chaque job repartirait sur une
        // corrélation arbitraire.
        AiCorrelation::bind($this->correlationId);

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
            'correlation_id' => AiCorrelation::id(),
            'process' => AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY,
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
