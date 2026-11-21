<?php

namespace App\Support\Images;

use App\Models\ImageVariant;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * TASK-1873 — QUELLES images existantes sont a rattraper.
 *
 * Partage, a l'identique, par `images:optimize` et par la console
 * `/admin/images` : la simulation de l'une et le lot de l'autre voient
 * exactement les memes sources, dans le meme ordre.
 *
 * Lecture SEULE, et en requetes brutes (`DB::table`) : un scope global de
 * tenant n'a rien a faire ici, le filtre d'Organization est explicite.
 *
 * Ecartes d'office : chemins vides, URL externes, assets PARTAGES de la
 * banque d'avatars Scenario, lignes supprimees logiquement (Organization,
 * article, service) — on n'optimise pas ce qui n'est plus affiche.
 */
final class ImageOptimizationInventory
{
    /** Preset dont le poids mesure le gain « servi » d'une surface. */
    private const REFERENCE_PRESETS = [
        'user_avatar' => 'avatar',
        'organization_logo' => 'logo',
        'loop_cover' => 'cover_md',
        'blog_cover' => 'cover_md',
        'service_image' => 'service_thumb',
    ];

    private const CHUNK = 500;

    public function __construct(
        private readonly ImagePresets $presets,
        private readonly ImageVariantService $service,
    ) {}

    /** @return list<string> */
    public function surfaces(): array
    {
        return $this->presets->backfillSurfaces();
    }

    /**
     * Les prochaines sources a traiter, dans un ordre stable (surface, puis
     * chemin). `$force` rend toutes les sources, a jour ou non.
     *
     * @return Collection<int, ImageSource>
     */
    public function pending(?string $surface = null, ?string $organizationId = null, int $limit = 20, bool $force = false): Collection
    {
        $found = collect();
        $seen = [];

        foreach ($this->surfacesFor($surface) as $name) {
            $this->eachChunk($name, $organizationId, function (Collection $sources) use (&$found, &$seen, $limit, $force): bool {
                $rows = $force ? collect() : $this->registryRowsFor($sources);

                foreach ($sources as $source) {
                    // Un meme fichier reference par deux lignes (copie de
                    // scenario, par exemple) ne se traite qu'une fois.
                    if (isset($seen[$source->key()])) {
                        continue;
                    }

                    if ($force || $this->service->needsWork($source, $rows->get($source->path, collect()))) {
                        $seen[$source->key()] = true;
                        $found->push($source);

                        if ($found->count() >= $limit) {
                            return false;
                        }
                    }
                }

                return true;
            });

            if ($found->count() >= $limit) {
                break;
            }
        }

        return $found->take($limit)->values();
    }

    /**
     * Etat par surface : sources en base, restantes, statuts du registre,
     * poids originaux et poids servis (preset de reference).
     *
     * @return array<string, array{total: int, pending: int, ready: int, skipped: int, failed: int, missing: int, source_bytes: int, variant_bytes: int}>
     */
    public function summary(?string $organizationId = null): array
    {
        $summary = [];

        foreach ($this->surfaces() as $surface) {
            $line = ['total' => 0, 'pending' => 0, 'ready' => 0, 'skipped' => 0, 'failed' => 0, 'missing' => 0, 'source_bytes' => 0, 'variant_bytes' => 0];
            $reference = self::REFERENCE_PRESETS[$surface] ?? null;

            $this->eachChunk($surface, $organizationId, function (Collection $sources) use (&$line, $reference): bool {
                $rows = $this->registryRowsFor($sources);

                foreach ($sources as $source) {
                    $line['total']++;
                    $bySource = $rows->get($source->path, collect());

                    if ($this->service->needsWork($source, $bySource)) {
                        $line['pending']++;
                    }

                    $status = $this->statusOf($bySource);
                    if ($status !== null) {
                        $line[$status]++;
                    }

                    $ref = $reference ? $bySource->get($reference) : null;
                    if ($ref?->status === ImageVariant::STATUS_READY) {
                        $line['source_bytes'] += (int) $ref->source_bytes;
                        $line['variant_bytes'] += (int) $ref->bytes;
                    }
                }

                return true;
            });

            $summary[$surface] = $line;
        }

        return $summary;
    }

    /**
     * Poids de l'original sur le disque — simulation seulement, bornee au
     * lot affiche (un appel HEAD par fichier sur le stockage objet).
     */
    public function sourceSize(ImageSource $source): ?int
    {
        $disk = Storage::disk($source->disk);

        try {
            return $disk->exists($source->path) ? (int) $disk->size($source->path) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  callable(Collection<int, ImageSource>): bool  $callback  rend false pour s'arreter
     */
    private function eachChunk(string $surface, ?string $organizationId, callable $callback): void
    {
        // Sous-select : les alias `src_path`/`src_org` ne sont filtrables
        // qu'a l'exterieur. `src_path` est la cle d'ordre stable du `chunk`.
        DB::query()->fromSub($this->query($surface), 'src')
            ->when($organizationId !== null, fn ($q) => $q->where('src_org', $organizationId))
            ->orderBy('src_path')
            ->chunk(self::CHUNK, function ($rows) use ($surface, $callback) {
                $sources = collect($rows)
                    ->reject(fn ($row) => ManifestAvatarBank::isPublishedAsset((string) $row->src_path))
                    ->map(fn ($row) => new ImageSource($surface, (string) $row->src_path, $row->src_org))
                    ->values();

                return $callback($sources);
            });
    }

    private function query(string $surface): Builder
    {
        $query = match ($surface) {
            'user_avatar' => DB::table('users')->select(['avatar as src_path', 'organization_id as src_org'])
                ->whereNotNull('avatar'),
            'organization_logo' => DB::table('organizations')->select(['logo_path as src_path', 'id as src_org'])
                ->whereNotNull('logo_path')->whereNull('deleted_at'),
            'loop_cover' => DB::table('loops')->select(['cover_image_path as src_path', 'organization_id as src_org'])
                ->whereNotNull('cover_image_path'),
            'blog_cover' => DB::table('blog_posts')->select(['image as src_path', 'organization_id as src_org'])
                ->whereNotNull('image')->whereNull('deleted_at'),
            'service_image' => DB::table('service_images')
                ->join('services', 'services.id', '=', 'service_images.service_id')
                ->select(['service_images.path as src_path', 'service_images.organization_id as src_org'])
                ->whereNull('services.deleted_at'),
            default => throw new InvalidArgumentException("Surface sans rattrapage : {$surface}"),
        };

        $column = match ($surface) {
            'user_avatar' => 'avatar',
            'organization_logo' => 'logo_path',
            'loop_cover' => 'cover_image_path',
            'blog_cover' => 'image',
            'service_image' => 'service_images.path',
        };

        return $query->where($column, '<>', '')
            ->where($column, 'not like', 'http://%')
            ->where($column, 'not like', 'https://%');
    }

    /** @return list<string> */
    private function surfacesFor(?string $surface): array
    {
        if ($surface === null) {
            return $this->surfaces();
        }

        if (! in_array($surface, $this->surfaces(), true)) {
            throw new InvalidArgumentException("Surface sans rattrapage : {$surface}");
        }

        return [$surface];
    }

    /**
     * @param  Collection<int, ImageSource>  $sources
     * @return Collection<string, Collection<string, ImageVariant>>
     */
    private function registryRowsFor(Collection $sources): Collection
    {
        if ($sources->isEmpty()) {
            return collect();
        }

        return ImageVariant::query()
            ->where('disk', 'public')
            ->whereIn('source_path', $sources->pluck('path')->unique()->all())
            ->get()
            ->groupBy('source_path')
            ->map(fn (Collection $rows) => $rows->keyBy('preset'));
    }

    /**
     * Le statut dominant d'une source : un seul echec suffit a la signaler.
     *
     * @param  Collection<string, ImageVariant>  $rows
     */
    private function statusOf(Collection $rows): ?string
    {
        if ($rows->isEmpty()) {
            return null;
        }

        foreach ([ImageVariant::STATUS_FAILED, ImageVariant::STATUS_MISSING, ImageVariant::STATUS_SKIPPED] as $status) {
            if ($rows->contains(fn (ImageVariant $row) => $row->status === $status)) {
                return $status;
            }
        }

        return ImageVariant::STATUS_READY;
    }
}
