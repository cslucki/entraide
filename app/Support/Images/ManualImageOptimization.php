<?php

namespace App\Support\Images;

use App\Models\ImageOptimizationBatch;
use App\Models\ImageOptimizationBatchItem;
use App\Models\ImageVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TASK-1875 — lot d'optimisation MANUEL : sans worker, sans connexion
 * asynchrone, pilote par le navigateur du SuperAdmin.
 *
 * ## Le principe : UNE image par requete HTTP
 *
 * `launch()` enregistre le lot et ses images, sans rien convertir. Ensuite,
 * chaque requete `step()` traite AU PLUS une image, puis rend la main ; le
 * navigateur enchaine la suivante. Fermer l'onglet arrete tout : aucun
 * traitement ne tourne hors d'une requete du SuperAdmin, et le lot reste en
 * base, repris a la demande.
 *
 * ## Protections (instance web de 512 Mo, PROD `QUEUE_CONNECTION=sync`)
 *
 *   - une image A LA FOIS, pour toute l'application : verrou global non
 *     bloquant ; une seconde requete simultanee repond « occupe » sans rien
 *     faire ;
 *   - une image par requete, meme si Livewire ou le navigateur regroupaient
 *     deux appels : marqueur pose sur la requete HTTP elle-meme ;
 *   - reservation conditionnelle `pending` -> `processing` : une image n'est
 *     jamais traitee deux fois ;
 *   - MODE REQUETE du moteur (`inRequestMaxPixels`) : plafond de pixels ET
 *     garde memoire AVANT tout decodage ; au-dela, l'image est ECARTEE avec
 *     sa raison (`deferred:LxH`), sans conversion ni inscription au registre —
 *     un worker pourra la traiter plus tard ;
 *   - l'original n'est jamais ecrit : seul le moteur T1873 touche aux
 *     fichiers, et seulement dans le repertoire des variantes ;
 *   - aucun chemin ne vient du navigateur : il ne transmet que l'identifiant
 *     du lot, les images sont celles enregistrees a son lancement.
 *
 * ## Reprise apres interruption
 *
 * Une image restee `processing` (requete tuee, deploiement) revient en
 * attente apres `STALE_AFTER_SECONDS`, delai SUPERIEUR a la duree de vie du
 * verrou : la requete qui la traitait ne peut plus etre vivante.
 */
final class ManualImageOptimization
{
    /** Taille maximale d'un lot manuel (decision produit, 21/11/2026). */
    public const MAX_ITEMS = 25;

    /**
     * TASK-1876 — tailles proposees au SuperAdmin : une image, puis cinq,
     * puis un lot complet (recette PROD progressive, sans Pause manuelle).
     */
    public const LIMITS = [1, 5, self::MAX_ITEMS];

    public const STEP_LOCK = 'image-optimization:manual-step';

    /** Bien au-dela d'une conversion web (quelques secondes), sous le delai de reprise. */
    public const STEP_LOCK_SECONDS = 180;

    public const STALE_AFTER_SECONDS = 300;

    /** Marqueur pose sur la requete HTTP courante. */
    public const REQUEST_FLAG = 'image_optimization.manual_step_done';

    /** Raison d'une image ecartee faute de pouvoir la traiter dans une requete web. */
    public const DEFERRED_PREFIX = 'deferred:';

    public function __construct(
        private readonly ImageOptimizationInventory $inventory,
        private readonly ImageOptimizationBatches $batches,
        private readonly ImageVariantService $service,
        private readonly ImagePresets $presets,
    ) {}

    /**
     * Les prochaines images du lot manuel, toutes surfaces de rattrapage
     * confondues, dans l'ordre stable de l'inventaire T1873. Les images deja
     * ecartees par un lot manuel comme trop lourdes pour une requete web n'y
     * reviennent pas : sinon, toujours premieres, elles boucheraient chaque
     * nouveau lot.
     *
     * @return Collection<int, ImageSource>
     */
    public function candidates(int $limit = self::MAX_ITEMS): Collection
    {
        $limit = max(1, min($limit, self::MAX_ITEMS));
        $deferred = $this->deferredKeys();

        return $this->inventory->pending(null, null, $limit + count($deferred))
            ->reject(fn (ImageSource $source) => isset($deferred[$source->disk.'|'.$source->path]))
            ->take($limit)
            ->values();
    }

    /**
     * Enregistre le lot et ses images. AUCUNE conversion.
     *
     * @throws ActiveBatchExists un lot (manuel ou non) occupe deja la console
     */
    public function launch(int $limit = self::MAX_ITEMS): ?ImageOptimizationBatch
    {
        $limit = max(1, min($limit, self::MAX_ITEMS));

        // Meme verrou que le lancement asynchrone : un seul lot actif, quel
        // que soit son mode.
        $lock = Cache::lock('image-optimization:launch', 30);

        if (! $lock->get()) {
            throw new ActiveBatchExists($this->batches->active());
        }

        try {
            if ($active = $this->batches->active()) {
                throw new ActiveBatchExists($active);
            }

            $sources = $this->candidates($limit);

            if ($sources->isEmpty()) {
                return null;
            }

            $batch = DB::transaction(function () use ($sources, $limit) {
                $batch = ImageOptimizationBatch::create([
                    'organization_id' => null,
                    'surface' => null,
                    'requested' => $limit,
                    'total' => $sources->count(),
                    'status' => ImageOptimizationBatch::STATUS_PENDING,
                    'mode' => ImageOptimizationBatch::MODE_MANUAL,
                    'force' => false,
                ]);

                foreach ($sources as $source) {
                    $batch->items()->create([
                        'organization_id' => $source->organizationId,
                        'surface' => $source->surface,
                        'disk' => $source->disk,
                        'source_path' => $source->path,
                        'status' => ImageOptimizationBatchItem::STATUS_PENDING,
                        'force' => false,
                    ]);
                }

                return $batch;
            });
        } finally {
            $lock->release();
        }

        Log::info('[TASK-1875] lot manuel d optimisation enregistre', ['batch' => $batch->id, 'total' => $batch->total]);

        return $batch;
    }

    /** La pause laisse finir l'image en cours ; aucune suivante ne demarre. */
    public function pause(ImageOptimizationBatch $batch): void
    {
        $this->batches->pause($batch);
    }

    public function resume(ImageOptimizationBatch $batch): void
    {
        ImageOptimizationBatch::query()
            ->whereKey($batch->id)
            ->where('mode', ImageOptimizationBatch::MODE_MANUAL)
            ->where('status', ImageOptimizationBatch::STATUS_PAUSED)
            ->update(['status' => ImageOptimizationBatch::STATUS_RUNNING, 'paused_at' => null]);
    }

    /**
     * Traite AU PLUS une image du lot, dans la requete courante.
     *
     * Etats rendus :
     *   `processed` une image traitee (quelle que soit son issue) ;
     *   `finished`  plus rien a traiter, lot clos ;
     *   `paused`    lot en pause : rien n'est demarre ;
     *   `busy`      une autre requete traite une image ;
     *   `locked`    l'image est tenue ailleurs (upload) : remise en attente ;
     *   `limit`     cette requete a deja traite une image.
     *
     * @return array{state: string, item_status?: string, reason?: string|null}
     */
    public function step(ImageOptimizationBatch $batch, Request $request): array
    {
        if ($request->attributes->get(self::REQUEST_FLAG)) {
            return ['state' => 'limit'];
        }

        if (! $batch->isManual()) {
            throw new \InvalidArgumentException('Lot non manuel : il se traite par la queue images.');
        }

        $lock = Cache::lock(self::STEP_LOCK, self::STEP_LOCK_SECONDS);

        if (! $lock->get()) {
            return ['state' => 'busy'];
        }

        try {
            $this->recoverStale($batch);
            $batch = $batch->fresh();

            if ($batch->isPaused()) {
                return ['state' => 'paused'];
            }

            if (! $batch->isActive()) {
                return ['state' => 'finished'];
            }

            $item = $batch->items()
                ->where('status', ImageOptimizationBatchItem::STATUS_PENDING)
                ->orderBy('attempts')
                ->orderBy('source_path')
                ->first();

            if ($item === null) {
                $this->batches->refresh($batch);

                return ['state' => $batch->fresh()->isActive() ? 'busy' : 'finished'];
            }

            $claimed = ImageOptimizationBatchItem::query()
                ->whereKey($item->id)
                ->where('status', ImageOptimizationBatchItem::STATUS_PENDING)
                ->update([
                    'status' => ImageOptimizationBatchItem::STATUS_PROCESSING,
                    'started_at' => now(),
                    'attempts' => $item->attempts + 1,
                ]);

            if ($claimed === 0) {
                return ['state' => 'busy'];
            }

            // Une image par requete : le marqueur est pose AVANT la conversion.
            $request->attributes->set(self::REQUEST_FLAG, true);

            ImageOptimizationBatch::query()
                ->whereKey($batch->id)
                ->where('status', ImageOptimizationBatch::STATUS_PENDING)
                ->update(['status' => ImageOptimizationBatch::STATUS_RUNNING]);
            ImageOptimizationBatch::query()
                ->whereKey($batch->id)
                ->whereNull('started_at')
                ->update(['started_at' => now()]);

            return $this->processItem($item, $batch);
        } finally {
            $lock->release();
        }
    }

    /** @return array{state: string, item_status?: string, reason?: string|null} */
    private function processItem(ImageOptimizationBatchItem $item, ImageOptimizationBatch $batch): array
    {
        try {
            $outcome = $this->service->process(
                new ImageSource($item->surface, $item->source_path, $item->organization_id, $item->disk),
                $item->force,
                inRequestMaxPixels: min(
                    (int) config('image_variants.in_request_max_pixels'),
                    (int) config('image_variants.max_pixels'),
                ),
            );
        } catch (Throwable $e) {
            $outcome = new ImageProcessOutcome(ImageProcessOutcome::FAILED, 'exception: '.$e->getMessage());
        }

        if ($outcome->status === ImageProcessOutcome::LOCKED) {
            // Un upload traite la meme image a l'instant : elle sera reprise.
            ImageOptimizationBatchItem::query()
                ->whereKey($item->id)
                ->update(['status' => ImageOptimizationBatchItem::STATUS_PENDING]);

            return ['state' => 'locked'];
        }

        $status = match ($outcome->status) {
            ImageProcessOutcome::PROCESSED => ImageOptimizationBatchItem::STATUS_SUCCEEDED,
            ImageProcessOutcome::UP_TO_DATE, ImageProcessOutcome::SKIPPED, ImageProcessOutcome::DEFERRED => ImageOptimizationBatchItem::STATUS_SKIPPED,
            ImageProcessOutcome::MISSING => ImageOptimizationBatchItem::STATUS_MISSING,
            default => ImageOptimizationBatchItem::STATUS_FAILED,
        };

        ImageOptimizationBatchItem::query()->whereKey($item->id)->update([
            'status' => $status,
            'source_bytes' => $outcome->sourceBytes,
            'variant_bytes' => $outcome->variantBytes ?: null,
            'served_bytes' => $status === ImageOptimizationBatchItem::STATUS_SUCCEEDED ? $this->servedBytes($item) : null,
            'error' => $outcome->reason !== null ? mb_substr($outcome->reason, 0, 1000) : null,
            'finished_at' => now(),
        ]);

        $this->batches->refresh($batch->fresh());

        return ['state' => 'processed', 'item_status' => $status, 'reason' => $outcome->reason];
    }

    /** Une image `processing` abandonnee (requete tuee) revient en attente. */
    private function recoverStale(ImageOptimizationBatch $batch): void
    {
        $batch->items()
            ->where('status', ImageOptimizationBatchItem::STATUS_PROCESSING)
            ->where('started_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->update(['status' => ImageOptimizationBatchItem::STATUS_PENDING]);
    }

    /** Poids de la variante que l'affichage telecharge reellement. */
    private function servedBytes(ImageOptimizationBatchItem $item): ?int
    {
        $preset = $this->presets->referencePreset($item->surface);

        if ($preset === null) {
            return null;
        }

        return ImageVariant::query()
            ->where('disk', $item->disk)
            ->where('source_path', $item->source_path)
            ->where('preset', $preset)
            ->where('status', ImageVariant::STATUS_READY)
            ->value('bytes');
    }

    /** @return array<string, true> cles `disk|chemin` ecartees par un lot manuel */
    private function deferredKeys(): array
    {
        return ImageOptimizationBatchItem::query()
            ->whereHas('batch', fn ($q) => $q->where('mode', ImageOptimizationBatch::MODE_MANUAL))
            ->where('status', ImageOptimizationBatchItem::STATUS_SKIPPED)
            ->where('error', 'like', self::DEFERRED_PREFIX.'%')
            ->get(['disk', 'source_path'])
            ->mapWithKeys(fn (ImageOptimizationBatchItem $item) => [$item->disk.'|'.$item->source_path => true])
            ->all();
    }
}
