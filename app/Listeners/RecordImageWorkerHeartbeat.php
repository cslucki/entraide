<?php

namespace App\Listeners;

use App\Support\Images\ImageWorkerHeartbeat;
use Illuminate\Queue\Events\Looping;

/**
 * TASK-1873 — signe de vie du worker qui consomme la queue `images`.
 *
 * Decouvert AUTOMATIQUEMENT par Laravel (type de l'argument) : ne pas
 * l'ajouter a la main dans `AppServiceProvider`, il serait abonne deux fois.
 */
class RecordImageWorkerHeartbeat
{
    public function __construct(private readonly ImageWorkerHeartbeat $heartbeat) {}

    public function handle(Looping $event): void
    {
        $this->heartbeat->recordFor($event->queue);
    }
}
