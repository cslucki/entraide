<?php

namespace App\Support\Images;

use App\Jobs\ProcessImageVariants;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * TASK-1873 — un worker consomme-t-il la queue `images` ?
 *
 * Le worker depose un SIGNE DE VIE a chaque tour de boucle (`Looping`,
 * evenement natif de `queue:work`), au plus une ecriture toutes les 15 s par
 * processus. La console lit son age.
 *
 * Seuil 90 s : en PROD, le worker planifie tourne quelques secondes chaque
 * minute puis s'arrete quand ses files sont vides — un signe de vie age de
 * moins d'une minute et demie veut donc dire « pris en charge ». En local, le
 * worker du service systemd boucle en continu : le signe est frais.
 *
 * Le cache (base en local comme en PROD) suffit ici : c'est un indicateur
 * d'exploitation, pas une donnee de lot — la progression, elle, vit en base.
 */
final class ImageWorkerHeartbeat
{
    public const CACHE_KEY = 'image_variants:worker_heartbeat';

    public const ALIVE_WITHIN_SECONDS = 90;

    private const WRITE_EVERY_SECONDS = 15;

    private static ?int $lastWrite = null;

    /**
     * @param  string|null  $queues  liste du worker (`--queue=a,b,images`)
     */
    public function recordFor(?string $queues): void
    {
        if (! $this->servesImages($queues)) {
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

    /** Remet a zero le cadencement (tests). */
    public static function resetThrottle(): void
    {
        self::$lastWrite = null;
    }

    private function servesImages(?string $queues): bool
    {
        $list = array_map('trim', explode(',', (string) $queues));

        return in_array(ProcessImageVariants::QUEUE, $list, true);
    }
}
