<?php

namespace App\Listeners;

use App\Support\Queues\QueueWorkerHeartbeat;
use Illuminate\Queue\Events\Looping;

/**
 * TASK-1909 — signe de vie du worker planifie `queue:work database`.
 *
 * Decouvert AUTOMATIQUEMENT par Laravel (type de l'argument), comme
 * `RecordImageWorkerHeartbeat` : ne pas l'abonner a la main.
 */
class RecordQueueWorkerHeartbeat
{
    public function __construct(private readonly QueueWorkerHeartbeat $heartbeat) {}

    public function handle(Looping $event): void
    {
        $this->heartbeat->recordFor($event->connectionName, $event->queue);
    }
}
