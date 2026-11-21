<?php

namespace App\Support\Images;

use App\Models\ImageVariant;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Laravel\Facades\Image;
use Throwable;

/**
 * TASK-1873 — le moteur UNIQUE de derivation des images.
 *
 * Appele par le job `ProcessImageVariants` (uploads neufs, lots de la
 * console), par `images:optimize --execute` et, pour les images de contenu
 * TipTap seulement, directement dans la requete d'upload. Une seule regle
 * pour les trois : c'est la copie qu'on oublie de corriger.
 *
 * ## Ce qu'il ne fait JAMAIS
 *
 *   - ecrire, deplacer ou supprimer un ORIGINAL : il ne lit que la source,
 *     et `forget()` refuse tout chemin hors du repertoire des variantes ;
 *   - traiter un asset PARTAGE de la banque d'avatars Scenario ;
 *   - decoder une image avant d'en avoir lu les dimensions : la garde de
 *     taille passe AVANT le bitmap, c'est elle qui protege la memoire.
 *
 * ## Idempotence
 *
 * Une source dont toutes les variantes sont a jour (version de preset
 * courante, statut terminal) n'est meme pas relue : aucun telechargement
 * depuis le stockage objet. Les noms de fichiers de l'application sont
 * uniques par upload (horodatage, aleatoire ou UUID), une source remplacee
 * change donc de chemin — l'empreinte sha1 n'est comparee qu'au retraitement
 * force, ou apres une montee de version.
 */
final class ImageVariantService
{
    /** Au-dela, une source en echec n'est plus reprise sans `--force`. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ImagePresets $presets,
        private readonly ImageVariantResolver $resolver,
    ) {}

    /**
     * @param  int|null  $inRequestMaxPixels  mode REQUETE (TipTap) : au-dela de
     *                                        ce plafond, ou si la memoire du
     *                                        processus ne suffit pas, on renonce
     *                                        SANS rien inscrire (`DEFERRED`) — la
     *                                        derivation se fera hors requete.
     */
    public function process(ImageSource $source, bool $force = false, ?int $inRequestMaxPixels = null): ImageProcessOutcome
    {
        $presets = $this->presets->forSurface($source->surface);

        if (ManifestAvatarBank::isPublishedAsset($source->path)) {
            return new ImageProcessOutcome(ImageProcessOutcome::SKIPPED, 'shared_asset');
        }

        $lock = Cache::lock('image-variants:'.sha1($source->key()), 300);

        if (! $lock->get()) {
            return new ImageProcessOutcome(ImageProcessOutcome::LOCKED, 'locked');
        }

        try {
            return $this->processLocked($source, $presets, $force, $inRequestMaxPixels);
        } finally {
            $lock->release();
        }
    }

    /**
     * Vrai si au moins un preset de la surface reste a produire pour cette
     * source. Lecture du registre seule, aucun acces au stockage.
     *
     * @param  Collection<string, ImageVariant>|null  $rows  lignes deja chargees, par preset
     */
    public function needsWork(ImageSource $source, ?Collection $rows = null): bool
    {
        $rows ??= $this->rowsFor($source);
        $reappeared = $this->reappearanceProbe($source);

        foreach ($this->presets->forSurface($source->surface) as $name => $preset) {
            if (! $this->isDone($rows->get($name), $preset['version'], $reappeared)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retire les variantes d'une source dont l'ORIGINAL vient d'etre supprime
     * par le code existant (remplacement, retrait). Jamais l'inverse : ne
     * touche que des fichiers du repertoire des variantes.
     */
    public function forget(string $path, string $disk = 'public'): void
    {
        $rows = ImageVariant::query()->where('disk', $disk)->where('source_path', $path)->get();

        if ($rows->isEmpty()) {
            return;
        }

        foreach ($rows as $row) {
            $this->deleteDerivedFile($disk, $row->path);
        }

        ImageVariant::query()->whereKey($rows->modelKeys())->delete();
        $this->resolver->flush();
    }

    public function variantPath(ImageSource $source, string $preset, int $version): string
    {
        $hash = sha1($source->key());

        return sprintf(
            '%s/%s-v%d/%s/%s.webp',
            $this->directory(),
            $preset,
            $version,
            substr($hash, 0, 2),
            $hash,
        );
    }

    /**
     * @param  array<string, array{mode: string, width: int, height: int|null, version: int}>  $presets
     */
    private function processLocked(ImageSource $source, array $presets, bool $force, ?int $inRequestMaxPixels): ImageProcessOutcome
    {
        $rows = $this->rowsFor($source);
        $reappeared = $this->reappearanceProbe($source);

        $pending = array_filter(
            $presets,
            fn (array $preset, string $name) => $force || ! $this->isDone($rows->get($name), $preset['version'], $reappeared),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($pending === []) {
            return new ImageProcessOutcome(ImageProcessOutcome::UP_TO_DATE);
        }

        $disk = Storage::disk($source->disk);

        if (! $disk->exists($source->path)) {
            $this->markAll($source, $pending, $rows, ImageVariant::STATUS_MISSING, 'source_missing');

            return new ImageProcessOutcome(ImageProcessOutcome::MISSING, 'source_missing');
        }

        try {
            $size = $disk->size($source->path);

            if ($size > (int) config('image_variants.max_source_bytes')) {
                return $this->skip($source, $pending, $rows, "too_heavy:{$size}", $size);
            }

            $bytes = (string) $disk->get($source->path);
        } catch (Throwable $e) {
            return $this->fail($source, $pending, $rows, 'read_failed: '.$e->getMessage());
        }

        $sourceBytes = strlen($bytes);
        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            return $this->skip($source, $pending, $rows, 'not_an_image', $sourceBytes);
        }

        [$width, $height] = $info;
        $mime = (string) ($info['mime'] ?? '');

        if (! in_array($mime, (array) config('image_variants.mimes'), true)) {
            return $this->skip($source, $pending, $rows, "unsupported_format:{$mime}", $sourceBytes);
        }

        if ($width * $height > (int) config('image_variants.max_pixels')
            || max($width, $height) > (int) config('image_variants.max_side')) {
            return $this->skip($source, $pending, $rows, "too_large:{$width}x{$height}", $sourceBytes);
        }

        // Mode requete : plafond plus bas, et AUCUNE inscription — le job
        // hors requete reprendra cette source comme si de rien n'etait.
        if ($inRequestMaxPixels !== null
            && ($width * $height > $inRequestMaxPixels || ! $this->memoryAllows($width, $height, $sourceBytes))) {
            return new ImageProcessOutcome(ImageProcessOutcome::DEFERRED, "deferred:{$width}x{$height}", 0, $sourceBytes);
        }

        // Une erreur « memoire epuisee » est FATALE en PHP : aucun `catch` ne
        // la rattrape, le repli n'aurait jamais lieu. On la previent.
        if (! $this->memoryAllows($width, $height, $sourceBytes)) {
            return $this->fail($source, $pending, $rows, "insufficient_memory:{$width}x{$height}", $sourceBytes);
        }

        $hash = sha1($bytes);

        try {
            $base = Image::decode($bytes);
        } catch (Throwable $e) {
            return $this->fail($source, $pending, $rows, 'decode_failed: '.$e->getMessage(), $sourceBytes);
        }

        unset($bytes);

        $generated = 0;
        $variantBytes = 0;
        $failure = null;

        foreach ($pending as $name => $preset) {
            try {
                [$path, $written, $w, $h] = $this->writeVariant($disk, $source, $base, $name, $preset);
            } catch (Throwable $e) {
                $failure = "encode_failed:{$name}: ".$e->getMessage();
                $this->record($source, $name, $preset['version'], $rows->get($name), [
                    'status' => ImageVariant::STATUS_FAILED,
                    'error' => mb_substr($failure, 0, 1000),
                    'source_bytes' => $sourceBytes,
                    'source_hash' => $hash,
                ]);

                continue;
            }

            $previous = $rows->get($name);
            if ($previous?->path && $previous->path !== $path) {
                $this->deleteDerivedFile($source->disk, $previous->path);
            }

            $this->record($source, $name, $preset['version'], $previous, [
                'status' => ImageVariant::STATUS_READY,
                'path' => $path,
                'width' => $w,
                'height' => $h,
                'bytes' => $written,
                'format' => 'webp',
                'source_bytes' => $sourceBytes,
                'source_hash' => $hash,
                'error' => null,
            ]);

            $generated++;
            $variantBytes += $written;
        }

        unset($base);
        gc_collect_cycles();
        $this->resolver->flush();

        if ($failure !== null) {
            Log::warning('[TASK-1873] variante d image en echec', ['path' => $source->path, 'error' => $failure]);

            return new ImageProcessOutcome(ImageProcessOutcome::FAILED, $failure, $generated, $sourceBytes, $variantBytes);
        }

        return new ImageProcessOutcome(ImageProcessOutcome::PROCESSED, null, $generated, $sourceBytes, $variantBytes);
    }

    /**
     * @param  array{mode: string, width: int, height: int|null, version: int}  $preset
     * @return array{0: string, 1: int, 2: int, 3: int}
     */
    private function writeVariant(Filesystem $disk, ImageSource $source, ImageInterface $base, string $name, array $preset): array
    {
        $image = clone $base;

        if ($preset['mode'] === 'cover') {
            $image->cover($preset['width'], $preset['height'] ?? $preset['width']);
        } else {
            $image->scaleDown($preset['width'], $preset['height']);
        }

        $encoded = (string) $image->encode(new WebpEncoder(
            quality: (int) config('image_variants.quality'),
            strip: true,
        ));

        $path = $this->variantPath($source, $name, $preset['version']);

        if (! $disk->put($path, $encoded)) {
            throw new \RuntimeException("ecriture refusee par le disque ({$path})");
        }

        return [$path, strlen($encoded), $image->width(), $image->height()];
    }

    /**
     * @param  \Closure(): bool  $reappeared  vrai si un original declare absent existe de nouveau
     */
    private function isDone(?ImageVariant $row, int $version, \Closure $reappeared): bool
    {
        if ($row === null || $row->preset_version !== $version) {
            return false;
        }

        return match ($row->status) {
            ImageVariant::STATUS_READY, ImageVariant::STATUS_SKIPPED => true,
            // Un original restaure (sauvegarde, copie depuis la PROD) est repris.
            ImageVariant::STATUS_MISSING => ! $reappeared(),
            ImageVariant::STATUS_FAILED => $row->attempts >= self::MAX_ATTEMPTS,
            default => false,
        };
    }

    /**
     * Sonde paresseuse et memorisee : le stockage n'est interroge que si une
     * ligne `missing` le demande, et une seule fois par source.
     *
     * @return \Closure(): bool
     */
    private function reappearanceProbe(ImageSource $source): \Closure
    {
        $known = null;

        return function () use ($source, &$known): bool {
            return $known ??= Storage::disk($source->disk)->exists($source->path);
        };
    }

    /**
     * Le decodage tient-il dans la memoire restante du processus ? Estimation
     * prudente : bitmap source + copie de travail (~9 octets par pixel), plus
     * l'original en memoire et une marge. `memory_limit = -1` : toujours oui.
     */
    private function memoryAllows(int $width, int $height, int $sourceBytes): bool
    {
        $limit = $this->memoryLimitBytes();

        if ($limit <= 0) {
            return true;
        }

        $needed = ($width * $height * 9) + $sourceBytes + (16 * 1024 * 1024);

        return memory_get_usage(true) + $needed <= $limit;
    }

    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /** @return Collection<string, ImageVariant> */
    private function rowsFor(ImageSource $source): Collection
    {
        return ImageVariant::query()
            ->where('disk', $source->disk)
            ->where('source_path', $source->path)
            ->get()
            ->keyBy('preset');
    }

    /**
     * @param  array<string, array{version: int}>  $pending
     * @param  Collection<string, ImageVariant>  $rows
     */
    private function skip(ImageSource $source, array $pending, Collection $rows, string $reason, ?int $sourceBytes): ImageProcessOutcome
    {
        $this->markAll($source, $pending, $rows, ImageVariant::STATUS_SKIPPED, $reason, $sourceBytes);

        return new ImageProcessOutcome(ImageProcessOutcome::SKIPPED, $reason, 0, $sourceBytes);
    }

    /**
     * @param  array<string, array{version: int}>  $pending
     * @param  Collection<string, ImageVariant>  $rows
     */
    private function fail(ImageSource $source, array $pending, Collection $rows, string $reason, ?int $sourceBytes = null): ImageProcessOutcome
    {
        Log::warning('[TASK-1873] source d image en echec', ['path' => $source->path, 'error' => $reason]);
        $this->markAll($source, $pending, $rows, ImageVariant::STATUS_FAILED, $reason, $sourceBytes);

        return new ImageProcessOutcome(ImageProcessOutcome::FAILED, $reason, 0, $sourceBytes);
    }

    /**
     * Un statut terminal sans variante. Une variante `ready` d'une version
     * anterieure garde son fichier et sa ligne : la remplacer par un echec
     * priverait l'affichage d'une variante qui fonctionne.
     *
     * @param  array<string, array{version: int}>  $pending
     * @param  Collection<string, ImageVariant>  $rows
     */
    private function markAll(ImageSource $source, array $pending, Collection $rows, string $status, string $reason, ?int $sourceBytes = null): void
    {
        foreach ($pending as $name => $preset) {
            $row = $rows->get($name);

            if ($row?->status === ImageVariant::STATUS_READY && $status !== ImageVariant::STATUS_MISSING) {
                continue;
            }

            if ($status === ImageVariant::STATUS_MISSING && $row?->path) {
                // La source a disparu : sa variante ne doit plus etre servie.
                $this->deleteDerivedFile($source->disk, $row->path);
            }

            $this->record($source, $name, $preset['version'], $row, [
                'status' => $status,
                'path' => null,
                'width' => null,
                'height' => null,
                'bytes' => null,
                'format' => null,
                'error' => mb_substr($reason, 0, 1000),
                'source_bytes' => $sourceBytes,
            ]);
        }

        $this->resolver->flush();
    }

    /** @param  array<string, mixed>  $attributes */
    private function record(ImageSource $source, string $preset, int $version, ?ImageVariant $row, array $attributes): void
    {
        $failed = ($attributes['status'] ?? null) === ImageVariant::STATUS_FAILED;
        $attempts = $failed ? (($row?->preset_version === $version ? $row->attempts : 0) + 1) : 0;

        ImageVariant::query()->updateOrCreate(
            ['disk' => $source->disk, 'source_path' => $source->path, 'preset' => $preset],
            array_merge($attributes, [
                'organization_id' => $source->organizationId,
                'surface' => $source->surface,
                'preset_version' => $version,
                'attempts' => $attempts,
                'processed_at' => now(),
            ]),
        );
    }

    private function deleteDerivedFile(string $disk, ?string $path): void
    {
        if ($path === null || ! str_starts_with($path, $this->directory().'/')) {
            return;
        }

        Storage::disk($disk)->delete($path);
    }

    private function directory(): string
    {
        return trim((string) config('image_variants.directory'), '/');
    }
}
