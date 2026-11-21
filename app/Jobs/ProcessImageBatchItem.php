<?php

namespace App\Jobs;

use App\Models\ImageOptimizationBatch;
use App\Models\ImageOptimizationBatchItem;
use App\Models\ImageVariant;
use App\Support\Images\ImageOptimizationBatches;
use App\Support\Images\ImagePresets;
use App\Support\Images\ImageProcessOutcome;
use App\Support\Images\ImageSource;
use App\Support\Images\ImageVariantService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * TASK-1873 (phase 2) — traiter UNE image d'un lot.
 *
 * Meme queue (`images`), meme connexion optionnelle et meme duree bornee que
 * `ProcessImageVariants` ; meme moteur (`ImageVariantService`).
 *
 *   1. lot en pause -> on ne touche a rien, l'image reste `pending` ;
 *   2. RESERVATION : `pending` -> `processing` par une mise a jour
 *      conditionnelle ; si elle ne gagne pas, un autre job a l'image ;
 *   3. derivation, puis statut final et poids ;
 *   4. recalcul de l'etat du lot.
 *
 * Un seul essai : un echec est inscrit (statut `failed`, message), et la
 * console propose de relancer les echecs, explicitement.
 */
class ProcessImageBatchItem implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Sous le `retry_after` (90 s) de la queue `database`. */
    public int $timeout = 75;

    public function __construct(public readonly string $itemId)
    {
        $this->onQueue(ProcessImageVariants::QUEUE);
        $this->afterCommit();

        if ($connection = config('image_variants.queue_connection')) {
            $this->onConnection($connection);
        }
    }

    public function handle(ImageVariantService $service, ImageOptimizationBatches $batches, ImagePresets $presets): void
    {
        $item = ImageOptimizationBatchItem::query()->with('batch')->find($this->itemId);

        if ($item === null || $item->batch === null || $item->batch->isPaused()) {
            return;
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
            return;
        }

        ImageOptimizationBatch::query()
            ->whereKey($item->batch_id)
            ->where('status', ImageOptimizationBatch::STATUS_PENDING)
            ->update(['status' => ImageOptimizationBatch::STATUS_RUNNING]);

        // Debut du lot = premiere image reellement traitee, quel que soit le
        // chemin (un lot mis en pause AVANT sa premiere image repart de
        // `paused`, pas de `pending` : sans cette ligne, pas de duree).
        ImageOptimizationBatch::query()
            ->whereKey($item->batch_id)
            ->whereNull('started_at')
            ->update(['started_at' => now()]);

        $outcome = $service->process(
            new ImageSource($item->surface, $item->source_path, $item->organization_id, $item->disk),
            $item->force,
        );

        if ($outcome->status === ImageProcessOutcome::LOCKED) {
            // Un upload traite la meme image a l'instant : on rend la place.
            $item->update(['status' => ImageOptimizationBatchItem::STATUS_PENDING]);
            static::dispatch($item->id)->delay(now()->addSeconds(15));

            return;
        }

        $item->update([
            'status' => $this->statusFor($outcome),
            'source_bytes' => $outcome->sourceBytes,
            'variant_bytes' => $outcome->variantBytes ?: null,
            'served_bytes' => $outcome->status === ImageProcessOutcome::PROCESSED
                ? $this->servedBytes($presets, $item)
                : null,
            'error' => $outcome->reason !== null ? mb_substr($outcome->reason, 0, 1000) : null,
            'finished_at' => now(),
        ]);

        $batches->refresh($item->batch->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        $item = ImageOptimizationBatchItem::query()->with('batch')->find($this->itemId);

        if ($item === null) {
            return;
        }

        $item->update([
            'status' => ImageOptimizationBatchItem::STATUS_FAILED,
            'error' => mb_substr('job_failed: '.($exception?->getMessage() ?? 'inconnu'), 0, 1000),
            'finished_at' => now(),
        ]);

        if ($item->batch !== null) {
            app(ImageOptimizationBatches::class)->refresh($item->batch->fresh());
        }
    }

    private function statusFor(ImageProcessOutcome $outcome): string
    {
        return match ($outcome->status) {
            ImageProcessOutcome::PROCESSED => ImageOptimizationBatchItem::STATUS_SUCCEEDED,
            // Deja a jour (un upload l'a traitee entre-temps), format non
            // traite, image trop grande : rien a faire, ce n'est pas un echec.
            ImageProcessOutcome::UP_TO_DATE, ImageProcessOutcome::SKIPPED => ImageOptimizationBatchItem::STATUS_SKIPPED,
            ImageProcessOutcome::MISSING => ImageOptimizationBatchItem::STATUS_MISSING,
            default => ImageOptimizationBatchItem::STATUS_FAILED,
        };
    }

    /** Poids de la variante que l'affichage telecharge reellement. */
    private function servedBytes(ImagePresets $presets, ImageOptimizationBatchItem $item): ?int
    {
        $preset = $presets->referencePreset($item->surface);

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
}
