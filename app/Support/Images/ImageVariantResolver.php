<?php

namespace App\Support\Images;

use App\Models\ImageVariant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * TASK-1873 — quelle URL servir pour une image, a l'affichage.
 *
 * ## Pourquoi une carte en cache, et pas une requete par image
 *
 * `avatar_url` est lu par une cinquantaine de vues, souvent en liste (membres,
 * fil, conversations). Une requete par avatar fabriquerait exactement les N+1
 * que les campagnes de performance T1813-T1817 ont retires. La carte de TOUTES
 * les variantes pretes tient en une cle de cache, relue une fois par requete
 * HTTP, et n'est reconstruite (une requete SQL) qu'apres une ecriture du
 * registre — `flush()`, appele par le service.
 *
 * Une carte perimee ne peut que REPLIER vers l'original : une source remplacee
 * change de chemin, l'ancienne entree n'est donc plus demandee par personne.
 *
 * ## Repli
 *
 * Aucune variante prete, ou registre illisible (table absente avant
 * migration) : l'URL ACTUELLE de l'original, a l'identique. L'affichage ne
 * depend jamais du rattrapage.
 */
final class ImageVariantResolver
{
    public const CACHE_KEY = 'image_variants:ready_map:v1';

    private const CACHE_TTL_SECONDS = 3600;

    /** @var array<string, array{0: string, 1: int, 2: int}>|null */
    private ?array $map = null;

    /**
     * La variante prete d'une source pour un preset, ou null.
     *
     * @return array{url: string, width: int, height: int}|null
     */
    public function variant(?string $path, string $preset, string $disk = 'public'): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        $entry = $this->map()[$disk.'|'.$path.'|'.$preset] ?? null;

        if ($entry === null) {
            return null;
        }

        return [
            'url' => Storage::disk($disk)->url($entry[0]),
            'width' => $entry[1],
            'height' => $entry[2],
        ];
    }

    /**
     * L'URL de la variante si elle existe, sinon celle de l'original.
     */
    public function url(?string $path, string $preset, string $disk = 'public'): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return $this->variant($path, $preset, $disk)['url'] ?? Storage::disk($disk)->url($path);
    }

    /**
     * `srcset` des variantes PRETES parmi `$presets`, en descripteurs de
     * largeur. Null tant qu'aucune n'est prete : la balise garde alors son
     * seul `src`, c'est-a-dire l'original.
     *
     * @param  list<string>  $presets
     */
    public function srcset(?string $path, array $presets, string $disk = 'public'): ?string
    {
        $candidates = [];

        foreach ($presets as $preset) {
            $variant = $this->variant($path, $preset, $disk);

            if ($variant !== null) {
                $candidates[$variant['width']] = $variant['url'].' '.$variant['width'].'w';
            }
        }

        if ($candidates === []) {
            return null;
        }

        ksort($candidates);

        return implode(', ', $candidates);
    }

    public function flush(): void
    {
        $this->map = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, array{0: string, 1: int, 2: int}> */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        try {
            return $this->map = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
                $map = [];

                ImageVariant::query()
                    ->where('status', ImageVariant::STATUS_READY)
                    ->whereNotNull('path')
                    ->toBase()
                    ->select(['disk', 'source_path', 'preset', 'path', 'width', 'height'])
                    ->orderBy('id')
                    ->chunk(2000, function ($rows) use (&$map) {
                        foreach ($rows as $row) {
                            $map[$row->disk.'|'.$row->source_path.'|'.$row->preset] = [
                                (string) $row->path,
                                (int) $row->width,
                                (int) $row->height,
                            ];
                        }
                    });

                return $map;
            });
        } catch (Throwable) {
            // Registre illisible : repli integral sur les originaux.
            return $this->map = [];
        }
    }
}
