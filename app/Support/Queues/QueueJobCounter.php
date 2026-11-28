<?php

namespace App\Support\Queues;

use Illuminate\Support\Facades\Cache;

/**
 * TASK-1909 — combien de jobs le worker `database` a TRAITES (ou vus
 * echouer) sur les dernieres 24 h, par file. Demande de Cyril en recette :
 * la console ne montrait que l'attente, pas le travail fait.
 *
 * Un compteur par file et par heure dans le cache (25 h de vie) ; la somme
 * des 24 dernieres heures est lue a l'affichage. Alimente par les evenements
 * `JobProcessed` / `JobFailed` : cron et mode manuel compris.
 */
final class QueueJobCounter
{
    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    public function record(string $queue, string $outcome): void
    {
        $key = $this->key($queue, $outcome, now()->format('YmdH'));

        Cache::add($key, 0, now()->addHours(25));
        Cache::increment($key);
    }

    public function last24h(string $queue, string $outcome): int
    {
        $total = 0;

        for ($h = 0; $h < 24; $h++) {
            $total += (int) Cache::get($this->key($queue, $outcome, now()->subHours($h)->format('YmdH')), 0);
        }

        return $total;
    }

    private function key(string $queue, string $outcome, string $hour): string
    {
        return "queues:{$outcome}:{$queue}:{$hour}";
    }
}
