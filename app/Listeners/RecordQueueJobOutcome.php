<?php

namespace App\Listeners;

use App\Support\Queues\QueueJobCounter;
use App\Support\Queues\QueueSupervision;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;

/**
 * TASK-1909 — compte les jobs traites / en echec des files du worker
 * `database`. Decouvert AUTOMATIQUEMENT (methodes `handle*` typees) : ne pas
 * l'abonner a la main.
 */
class RecordQueueJobOutcome
{
    public function __construct(private readonly QueueJobCounter $counter) {}

    public function handleJobProcessed(JobProcessed $event): void
    {
        // Un job relache (ex. `WithoutOverlapping`) n'est pas « traite ».
        if ($event->job->isReleased() || $event->job->hasFailed()) {
            return;
        }

        $this->count($event->connectionName, $event->job->getQueue(), QueueJobCounter::PROCESSED);
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $this->count($event->connectionName, $event->job->getQueue(), QueueJobCounter::FAILED);
    }

    private function count(?string $connection, ?string $queue, string $outcome): void
    {
        if ($connection !== QueueSupervision::CONNECTION || ! in_array($queue, QueueSupervision::WORKER_QUEUES, true)) {
            return;
        }

        $this->counter->record((string) $queue, $outcome);
    }
}
