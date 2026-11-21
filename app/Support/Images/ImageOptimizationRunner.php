<?php

namespace App\Support\Images;

use Illuminate\Support\Facades\Log;

/**
 * TASK-1873 — traiter SUR PLACE un lot borne, en ligne de commande seulement
 * (`images:optimize --execute`). Pas de requete HTTP, pas de suivi en base :
 * c'est l'outil de l'operateur au terminal.
 *
 * La console, et `--execute --queue`, passent par `ImageOptimizationBatches` :
 * un lot SUIVI en base, mis en file, pausable.
 */
final class ImageOptimizationRunner
{
    public function __construct(
        private readonly ImageOptimizationInventory $inventory,
        private readonly ImageVariantService $service,
    ) {}

    /**
     * @param  callable(ImageSource, ImageProcessOutcome): void|null  $onItem
     * @return array{processed: int, up_to_date: int, skipped: int, failed: int, missing: int, locked: int, source_bytes: int, variant_bytes: int, stopped_by: string|null}
     */
    public function runBatch(?string $surface, ?string $organizationId, int $limit, int $maxSeconds, bool $force = false, ?callable $onItem = null): array
    {
        $stats = ['processed' => 0, 'up_to_date' => 0, 'skipped' => 0, 'failed' => 0, 'missing' => 0, 'locked' => 0, 'source_bytes' => 0, 'variant_bytes' => 0, 'stopped_by' => null];
        $deadline = microtime(true) + $maxSeconds;

        foreach ($this->inventory->pending($surface, $organizationId, $limit, $force) as $source) {
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
