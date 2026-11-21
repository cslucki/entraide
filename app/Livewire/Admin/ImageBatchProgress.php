<?php

namespace App\Livewire\Admin;

use App\Models\ImageOptimizationBatch;
use App\Models\ImageOptimizationBatchItem;
use App\Support\Images\ActiveBatchExists;
use App\Support\Images\ImageOptimizationBatches;
use App\Support\Images\ImageQueue;
use App\Support\Images\ImageQueueNotConfigured;
use App\Support\Images\ImageWorkerHeartbeat;
use App\Support\Images\ManualImageOptimization;
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

    /**
     * TASK-1875 — lot manuel lance a l'instant dans cette page : le navigateur
     * enchaine ses images sans autre clic. Jamais vrai a la reouverture.
     */
    #[Locked]
    public bool $autostart = false;

    public function boot(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function mount(string $batchId, bool $autostart = false): void
    {
        $this->batchId = $batchId;
        $this->autostart = $autostart;
        $this->wasActive = (bool) ImageOptimizationBatch::query()->find($batchId)?->isActive();
    }

    public function pause(ImageOptimizationBatches $batches): void
    {
        $batches->pause($this->batch());
    }

    public function resume(ImageOptimizationBatches $batches, ManualImageOptimization $manual): void
    {
        $batch = $this->batch();

        // TASK-1875 : un lot manuel reprend sans queue ; c'est le navigateur
        // qui enchainera ses images, une requete chacune.
        if ($batch->isManual()) {
            $manual->resume($batch);

            return;
        }

        try {
            $batches->resume($this->batch());
        } catch (ImageQueueNotConfigured) {
            $this->addError('retry', __('admin.image_optimizer.queue_not_configured'));
        }
    }

    public function retryFailed(ImageOptimizationBatches $batches): void
    {
        try {
            $batches->retryFailed($this->batch());
            $this->wasActive = true;
        } catch (ActiveBatchExists) {
            $this->addError('retry', __('admin.image_optimizer.already_running'));
        } catch (ImageQueueNotConfigured) {
            $this->addError('retry', __('admin.image_optimizer.queue_not_configured'));
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
            // Une pause laisse finir l'image en cours : on suit jusque-la. Un
            // lot manuel se rafraichit apres chaque image, a l'initiative du
            // navigateur qui le pilote : pas de sondage periodique.
            'polling' => ! $batch->isManual() && $batch->isActive() && (! $batch->isPaused() || $progress['processing'] > 0),
            'stepUrl' => $batch->isManual() ? route('admin.images.manual.step', $batch) : null,
            'workerAlive' => app(ImageWorkerHeartbeat::class)->isAlive(),
            'queueReady' => app(ImageQueue::class)->isAsync(),
            'problems' => $batch->items()
                ->where(fn ($q) => $q
                    ->whereIn('status', [ImageOptimizationBatchItem::STATUS_FAILED, ImageOptimizationBatchItem::STATUS_MISSING])
                    // TASK-1875 : une image ecartee par le mode requete, avec sa raison.
                    ->orWhere(fn ($q) => $q->where('status', ImageOptimizationBatchItem::STATUS_SKIPPED)
                        ->where('error', 'like', ManualImageOptimization::DEFERRED_PREFIX.'%')))
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
