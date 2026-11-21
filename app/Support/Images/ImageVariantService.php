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

    public function process(ImageSource $source, bool $force = false): ImageProcessOutcome
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
            return $this->processLocked($source, $presets, $force);
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

        foreach ($this->presets->forSurface($source->surface) as $name => $preset) {
            if (! $this->isDone($rows->get($name), $preset['version'])) {
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
    private function processLocked(ImageSource $source, array $presets, bool $force): ImageProcessOutcome
    {
        $rows = $this->rowsFor($source);

        $pending = array_filter(
            $presets,
            fn (array $preset, string $name) => $force || ! $this->isDone($rows->get($name), $preset['version']),
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

    private function isDone(?ImageVariant $row, int $version): bool
    {
        if ($row === null || $row->preset_version !== $version) {
            return false;
        }

        return match ($row->status) {
            ImageVariant::STATUS_READY, ImageVariant::STATUS_SKIPPED, ImageVariant::STATUS_MISSING => true,
            ImageVariant::STATUS_FAILED => $row->attempts >= self::MAX_ATTEMPTS,
            default => false,
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
