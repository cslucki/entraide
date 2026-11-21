<?php

namespace App\Livewire\Admin;

use App\Models\ImageOptimizationBatch;
use App\Models\ImageOptimizationBatchItem;
use App\Support\Images\ActiveBatchExists;
use App\Support\Images\ImageOptimizationBatches;
use App\Support\Images\ImageWorkerHeartbeat;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * TASK-1873 (phase 2) — carte de progression d'UN lot.
 *
 * Se rafraichit toutes les 2,5 s tant que le lot avance (ou qu'une image
 * finit pendant une pause), puis s'arrete. Les chiffres viennent de la base
 * (`ImageOptimizationBatches::progress`) : recharger la page, ou revenir
 * plus tard, rend exactement la meme progression.
 */
class ImageBatchProgress extends Component
{
    #[Locked]
    public string $batchId;

    /** Pour prevenir la page une seule fois, au passage a « termine ». */
    public bool $wasActive = false;

    public function boot(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function mount(string $batchId): void
    {
        $this->batchId = $batchId;
        $this->wasActive = (bool) ImageOptimizationBatch::query()->find($batchId)?->isActive();
    }

    public function pause(ImageOptimizationBatches $batches): void
    {
        $batches->pause($this->batch());
    }

    public function resume(ImageOptimizationBatches $batches): void
    {
        $batches->resume($this->batch());
    }

    public function retryFailed(ImageOptimizationBatches $batches): void
    {
        try {
            $batches->retryFailed($this->batch());
            $this->wasActive = true;
        } catch (ActiveBatchExists) {
            $this->addError('retry', __('admin.image_optimizer.already_running'));
        }
    }

    public function render(ImageOptimizationBatches $batches): View
    {
        $batch = $this->batch();
        $progress = $batches->progress($batch);

        if ($this->wasActive && ! $batch->isActive()) {
            $this->wasActive = false;
            $this->dispatch('image-batch-finished');
        }

        return view('livewire.admin.image-batch-progress', [
            'batch' => $batch,
            'progress' => $progress,
            // Une pause laisse finir l'image en cours : on suit jusque-la.
            'polling' => $batch->isActive() && (! $batch->isPaused() || $progress['processing'] > 0),
            'workerAlive' => app(ImageWorkerHeartbeat::class)->isAlive(),
            'problems' => $batch->items()
                ->whereIn('status', [ImageOptimizationBatchItem::STATUS_FAILED, ImageOptimizationBatchItem::STATUS_MISSING])
                ->orderBy('status')
                ->limit(50)
                ->get(['surface', 'source_path', 'status', 'error']),
        ]);
    }

    private function batch(): ImageOptimizationBatch
    {
        return ImageOptimizationBatch::query()->findOrFail($this->batchId);
    }
}
