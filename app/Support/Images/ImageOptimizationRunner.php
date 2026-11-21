<?php

namespace App\Support\Images;

use App\Jobs\ProcessImageVariants;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1873 — lancer un LOT BORNE de rattrapage. Un seul chemin pour la
 * commande et pour la console : memes sources (inventaire), meme moteur
 * (service), memes bornes, meme pause.
 *
 *   `queueBatch()` met en file un job par source, sur la queue `images`
 *                  (console, ou `images:optimize --execute --queue`) : rien
 *                  n'est traite dans la requete HTTP ;
 *   `runBatch()`   traite sur place, une image apres l'autre (CLI seulement),
 *                  en s'arretant a la borne de temps, de nombre, ou a la pause.
 */
final class ImageOptimizationRunner
{
    public function __construct(
        private readonly ImageOptimizationInventory $inventory,
        private readonly ImageVariantService $service,
        private readonly ImageOptimizationControl $control,
    ) {}

    /**
     * @return int nombre de sources mises en file (0 si la pause est active)
     */
    public function queueBatch(?string $surface, ?string $organizationId, int $limit, bool $force = false): int
    {
        if ($this->control->paused()) {
            return 0;
        }

        $sources = $this->inventory->pending($surface, $organizationId, $limit, $force);

        foreach ($sources as $source) {
            ProcessImageVariants::dispatch($source->surface, $source->path, $source->organizationId, $source->disk, true, $force);
        }

        Log::info('[TASK-1873] lot de rattrapage mis en file', [
            'surface' => $surface, 'organization_id' => $organizationId, 'queued' => $sources->count(), 'force' => $force,
        ]);

        return $sources->count();
    }

    /**
     * @param  callable(ImageSource, ImageProcessOutcome): void|null  $onItem
     * @return array{processed: int, up_to_date: int, skipped: int, failed: int, missing: int, locked: int, source_bytes: int, variant_bytes: int, stopped_by: string|null}
     */
    public function runBatch(?string $surface, ?string $organizationId, int $limit, int $maxSeconds, bool $force = false, ?callable $onItem = null): array
    {
        $stats = ['processed' => 0, 'up_to_date' => 0, 'skipped' => 0, 'failed' => 0, 'missing' => 0, 'locked' => 0, 'source_bytes' => 0, 'variant_bytes' => 0, 'stopped_by' => null];
        $deadline = microtime(true) + $maxSeconds;

        foreach ($this->inventory->pending($surface, $organizationId, $limit, $force) as $source) {
            if ($this->control->paused()) {
                $stats['stopped_by'] = 'pause';
                break;
            }

            if (microtime(true) >= $deadline) {
                $stats['stopped_by'] = 'max_time';
                break;
            }

            $outcome = $this->service->process($source, $force);

            $key = match ($outcome->status) {
                ImageProcessOutcome::PROCESSED => 'processed',
                ImageProcessOutcome::UP_TO_DATE => 'up_to_date',
                ImageProcessOutcome::SKIPPED => 'skipped',
                ImageProcessOutcome::MISSING => 'missing',
                ImageProcessOutcome::LOCKED => 'locked',
                default => 'failed',
            };
            $stats[$key]++;

            if ($outcome->status === ImageProcessOutcome::PROCESSED) {
                $stats['source_bytes'] += (int) $outcome->sourceBytes;
                $stats['variant_bytes'] += $outcome->variantBytes;
            }

            if ($onItem !== null) {
                $onItem($source, $outcome);
            }
        }

        Log::info('[TASK-1873] lot de rattrapage traite', $stats + ['surface' => $surface, 'organization_id' => $organizationId]);

        return $stats;
    }
}
