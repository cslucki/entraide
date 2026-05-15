<?php

namespace App\Events;

use App\Models\LoopMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class LoopMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public string $id;
    public string $loopId;
    public ?string $senderId;
    public string $body;
    public string $type;
    public string $createdAt;

    public function __construct(LoopMessage $message)
    {
        $this->id = $message->id;
        $this->loopId = $message->loop_id;
        $this->senderId = $message->sender_id;
        $this->body = $message->body;
        $this->type = $message->type;
        $this->createdAt = $message->created_at->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("loop.{$this->loopId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'loop.message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'loop_id' => $this->loopId,
            'sender_id' => $this->senderId,
            'body' => $this->body,
            'type' => $this->type,
            'created_at' => $this->createdAt,
        ];
    }
}
