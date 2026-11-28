<?php

namespace App\Support\Queues;

use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * TASK-1909 — signe de vie du worker planifie `queue:work database`
 * (cron local, scheduler PROD ; AGENTS.md section 16).
 *
 * Ne compte qu'un worker sur la connexion `database` qui sert la file des
 * articles : un `queue:work` sur `sync` boucle sans rien consommer (la PROD
 * avant TASK-1909). Le worker est relance chaque minute : vivant = vu depuis
 * moins de `ALIVE_WITHIN_SECONDS`.
 */
final class QueueWorkerHeartbeat
{
    public const CACHE_KEY = 'queues:database_worker_heartbeat';

    public const ALIVE_WITHIN_SECONDS = 150;

    private const WRITE_EVERY_SECONDS = 15;

    private static ?int $lastWrite = null;

    public function recordFor(?string $connection, ?string $queues): void
    {
        $list = array_map('trim', explode(',', (string) $queues));

        if ($connection !== 'database' || ! in_array(DossierArticleIndexingDispatcher::DEDICATED_QUEUE, $list, true)) {
            return;
        }

        $now = time();
        if (self::$lastWrite !== null && $now - self::$lastWrite < self::WRITE_EVERY_SECONDS) {
            return;
        }

        self::$lastWrite = $now;
        Cache::put(self::CACHE_KEY, $now, now()->addDay());
    }

    public function lastSeen(): ?Carbon
    {
        $timestamp = Cache::get(self::CACHE_KEY);

        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    public function isAlive(): bool
    {
        $seen = $this->lastSeen();

        return $seen !== null && $seen->diffInSeconds(now()) <= self::ALIVE_WITHIN_SECONDS;
    }

    public static function resetThrottle(): void
    {
        self::$lastWrite = null;
    }
}
