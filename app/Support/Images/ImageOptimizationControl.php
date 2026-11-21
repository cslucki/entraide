<?php

namespace App\Support\Images;

use Illuminate\Support\Facades\Cache;

/**
 * TASK-1873 — l'interrupteur PAUSE du rattrapage.
 *
 * ## Ou il vit, et pourquoi
 *
 * Dans le cache, en `forever`. Le modele `Setting` n'a plus de table (supprimee
 * par `2026_06_02_000040_drop_settings_table`) ; une table dediee pour un seul
 * booleen aurait ete disproportionnee. Le cache PROD est en base
 * (`CACHE_STORE=database`) et le deploiement Laravel Cloud ne lance que
 * `migrate --force` : la pause survit aux deploiements.
 *
 * Risque residuel ASSUME : un `cache:clear` manuel leve la pause. La
 * consequence est bornee — au plus le dernier lot mis en file (50 images au
 * maximum) reprend.
 *
 * ## Portee
 *
 * La pause ne concerne QUE le rattrapage (commande, lots de la console). Les
 * images televersees a l'instant sont toujours traitees : les mettre en
 * pause laisserait un nouvel avatar servi en pleine taille sans raison.
 */
final class ImageOptimizationControl
{
    public const CACHE_KEY = 'image_variants:backfill_paused';

    public function paused(): bool
    {
        return (bool) Cache::get(self::CACHE_KEY, false);
    }

    public function pause(): void
    {
        Cache::forever(self::CACHE_KEY, true);
    }

    public function resume(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
