<?php

namespace App\Support\Images;

use App\Jobs\ProcessImageBatchItem;
use App\Models\ImageOptimizationBatch;
use App\Models\ImageOptimizationBatchItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1873 (phase 2) — cycle de vie d'un lot d'optimisation.
 *
 * La base fait foi : la progression se COMPTE sur les lignes du lot, jamais
 * dans un cache. Rien n'est converti ici : `launch()` et `resume()` mettent en
 * file un job par image en attente, et rendent la main aussitot — et ils
 * REFUSENT de le faire sur une connexion non asynchrone (`ImageQueue`).
 *
 * ## Garde-fous
 *
 *   - UN seul lot actif (en attente, en cours, en pause) : un double clic ou
 *     deux onglets ne lancent pas deux lots. Le verrou serialise la
 *     verification et la creation ;
 *   - chaque image n'est traitee qu'une fois : le job la RESERVE par une mise
 *     a jour conditionnelle (`pending` -> `processing`) ;
 *   - la pause laisse finir l'image en cours et suspend les suivantes ; la
 *     reprise remet en file les images encore en attente, et elles seules.
 */
final class ImageOptimizationBatches
{
    /** Une image `processing` sans nouvelles depuis ce delai a perdu son worker. */
    public const STALE_AFTER_SECONDS = 300;

    /** En cours, sans activite depuis ce delai : aucun worker ne consomme `images`. */
    public const WORKER_IDLE_SECONDS = 20;

    public function __construct(
        private readonly ImageOptimizationInventory $inventory,
        private readonly ImageQueue $queue,
    ) {}

    public function active(): ?ImageOptimizationBatch
    {
        return ImageOptimizationBatch::query()->active()->latest()->first();
    }

    /**
     * @throws ActiveBatchExists
     * @throws ImageQueueNotConfigured connexion des images non asynchrone : le
     *                                 lot s'executerait dans la requete (PROD
     *                                 `QUEUE_CONNECTION=sync`, mesure 21/11).
     */
    public function launch(?string $surface, ?string $organizationId, int $limit, bool $force = false): ?ImageOptimizationBatch
    {
        $this->queue->ensureAsync();

        $lock = Cache::lock('image-optimization:launch', 30);

        if (! $lock->get()) {
            throw new ActiveBatchExists($this->active());
        }

        try {
            if ($active = $this->active()) {
                throw new ActiveBatchExists($active);
            }

            $sources = $this->inventory->pending($surface, $organizationId, $limit, $force);

            if ($sources->isEmpty()) {
                return null;
            }

            $batch = DB::transaction(function () use ($sources, $surface, $organizationId, $limit, $force) {
                $batch = ImageOptimizationBatch::create([
                    'organization_id' => $organizationId,
                    'surface' => $surface,
                    'requested' => $limit,
                    'total' => $sources->count(),
                    'status' => ImageOptimizationBatch::STATUS_PENDING,
                    'force' => $force,
                ]);

                foreach ($sources as $source) {
                    $batch->items()->create([
                        'organization_id' => $source->organizationId,
                        'surface' => $source->surface,
                        'disk' => $source->disk,
                        'source_path' => $source->path,
                        'status' => ImageOptimizationBatchItem::STATUS_PENDING,
                        'force' => $force,
                    ]);
                }

                return $batch;
            });
        } finally {
            $lock->release();
        }

        $this->dispatchPending($batch);

        Log::info('[TASK-1873] lot d optimisation lance', [
            'batch' => $batch->id, 'total' => $batch->total, 'surface' => $surface, 'organization_id' => $organizationId,
        ]);

        return $batch;
    }

    public function pause(ImageOptimizationBatch $batch): void
    {
        ImageOptimizationBatch::query()
            ->whereKey($batch->id)
            ->whereIn('status', [ImageOptimizationBatch::STATUS_PENDING, ImageOptimizationBatch::STATUS_RUNNING])
            ->update(['status' => ImageOptimizationBatch::STATUS_PAUSED, 'paused_at' => now()]);
    }

    /** @throws ImageQueueNotConfigured */
    public function resume(ImageOptimizationBatch $batch): void
    {
        $this->queue->ensureAsync();

        $updated = ImageOptimizationBatch::query()
            ->whereKey($batch->id)
            ->where('status', ImageOptimizationBatch::STATUS_PAUSED)
            ->update(['status' => ImageOptimizationBatch::STATUS_RUNNING, 'paused_at' => null]);

        if ($updated === 0) {
            return;
        }

        $this->recoverStale($batch);
        $this->dispatchPending($batch->fresh());

        // Toutes les images avaient fini avant la pause : le lot se clot ici.
        $this->refresh($batch->fresh());
    }

    /**
     * Remet en attente les images en echec d'un lot termine, en FORCANT leur
     * retraitement (le registre les tiendrait sinon pour abandonnees).
     */
    public function retryFailed(ImageOptimizationBatch $batch): int
    {
        $this->queue->ensureAsync();

        if ($this->active() !== null && $this->active()->id !== $batch->id) {
            throw new ActiveBatchExists($this->active());
        }

        $count = $batch->items()
            ->where('status', ImageOptimizationBatchItem::STATUS_FAILED)
            ->update(['status' => ImageOptimizationBatchItem::STATUS_PENDING, 'force' => true, 'error' => null, 'finished_at' => null]);

        if ($count > 0) {
            $batch->update(['status' => ImageOptimizationBatch::STATUS_RUNNING, 'finished_at' => null]);
            $this->dispatchPending($batch->fresh());
        }

        return $count;
    }

    /**
     * Recalcule l'etat du lot depuis ses lignes. Appele apres chaque image.
     */
    public function refresh(ImageOptimizationBatch $batch): void
    {
        $counts = $this->counts($batch);
        $open = $counts['pending'] + $counts['processing'];

        if ($open === 0 && $batch->status !== ImageOptimizationBatch::STATUS_PAUSED) {
            ImageOptimizationBatch::query()
                ->whereKey($batch->id)
                ->whereIn('status', [ImageOptimizationBatch::STATUS_PENDING, ImageOptimizationBatch::STATUS_RUNNING])
                ->update([
                    'status' => $counts['failed'] > 0
                        ? ImageOptimizationBatch::STATUS_COMPLETED_WITH_ERRORS
                        : ImageOptimizationBatch::STATUS_COMPLETED,
                    'finished_at' => now(),
                ]);
        }
    }

    /**
     * Progression calculee COTE SERVEUR, depuis les lignes du lot.
     *
     * @return array{total: int, done: int, percent: int, pending: int, processing: int, succeeded: int, skipped: int, missing: int, failed: int, source_bytes: int, variant_bytes: int, served_bytes: int, served_source_bytes: int, duration_seconds: int|null, waiting_for_worker: bool}
     */
    public function progress(ImageOptimizationBatch $batch): array
    {
        $counts = $this->counts($batch);

        $bytes = $batch->items()
            ->selectRaw('COALESCE(SUM(source_bytes), 0) AS source_bytes, COALESCE(SUM(variant_bytes), 0) AS variant_bytes')
            ->first();

        // Gain servi : seulement sur les images reussies (original contre
        // variante reellement telechargee par l'affichage).
        $served = $batch->items()
            ->where('status', ImageOptimizationBatchItem::STATUS_SUCCEEDED)
            ->selectRaw('COALESCE(SUM(source_bytes), 0) AS source_bytes, COALESCE(SUM(served_bytes), 0) AS served_bytes')
            ->first();

        $done = $counts['succeeded'] + $counts['skipped'] + $counts['missing'] + $counts['failed'];
        $total = max(1, (int) $batch->total);

        // Derniere activite : le plus recent des debuts et fins d'image, sinon
        // la creation du lot. (Carbon 3 : `a->diffInSeconds(b)` est SIGNE,
        // on mesure donc du passe vers maintenant.)
        $lastActivity = collect([
            $batch->items()->max('finished_at'),
            $batch->items()->max('started_at'),
            $batch->created_at,
        ])->filter()->map(fn ($at) => Carbon::parse($at))->max();
        $idle = $lastActivity->diffInSeconds(now()) > self::WORKER_IDLE_SECONDS;

        $waiting = $counts['processing'] === 0
            && $counts['pending'] > 0
            && in_array($batch->status, [ImageOptimizationBatch::STATUS_PENDING, ImageOptimizationBatch::STATUS_RUNNING], true)
            && $idle;

        $end = $batch->finished_at ?? ($batch->isActive() ? now() : null);

        return $counts + [
            'total' => (int) $batch->total,
            'done' => $done,
            'percent' => (int) floor(100 * $done / $total),
            'source_bytes' => (int) $bytes->source_bytes,
            'variant_bytes' => (int) $bytes->variant_bytes,
            'served_bytes' => (int) $served->served_bytes,
            'served_source_bytes' => (int) $served->source_bytes,
            'duration_seconds' => $batch->started_at && $end ? (int) $batch->started_at->diffInSeconds($end) : null,
            'waiting_for_worker' => $waiting,
        ];
    }

    /** @return array{pending: int, processing: int, succeeded: int, skipped: int, missing: int, failed: int} */
    public function counts(ImageOptimizationBatch $batch): array
    {
        $rows = $batch->items()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        $counts = [];
        foreach (['pending', 'processing', 'succeeded', 'skipped', 'missing', 'failed'] as $status) {
            $counts[$status] = (int) ($rows[$status] ?? 0);
        }

        return $counts;
    }

    /**
     * Une image restee `processing` (worker tue, deploiement) revient en
     * attente : elle sera reprise, et la reservation conditionnelle empeche
     * qu'elle le soit deux fois.
     */
    private function recoverStale(ImageOptimizationBatch $batch): void
    {
        $batch->items()
            ->where('status', ImageOptimizationBatchItem::STATUS_PROCESSING)
            ->where('started_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->update(['status' => ImageOptimizationBatchItem::STATUS_PENDING]);
    }

    private function dispatchPending(ImageOptimizationBatch $batch): void
    {
        $batch->items()
            ->where('status', ImageOptimizationBatchItem::STATUS_PENDING)
            ->orderBy('source_path')
            ->pluck('id')
            ->each(fn (string $id) => ProcessImageBatchItem::dispatch($id));
    }
}
