<?php

namespace App\Livewire\Admin;

use App\Models\ImageOptimizationBatch;
use App\Models\Organization;
use App\Support\Images\ActiveBatchExists;
use App\Support\Images\ImageOptimizationBatches;
use App\Support\Images\ImageOptimizationInventory;
use App\Support\Images\ImageQueue;
use App\Support\Images\ImageQueueNotConfigured;
use App\Support\Images\ImageSource;
use App\Support\Images\ImageWorkerHeartbeat;
use App\Support\Images\ManualImageOptimization;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * TASK-1873 (phase 2) — console SuperAdmin `/admin/images`.
 *
 * Synthese, lancement, et la carte de progression du lot actif (composant
 * enfant `ImageBatchProgress`, seul a se rafraichir : la synthese, plus
 * couteuse, n'est recalculee qu'a la fin d'un lot ou a un changement de
 * filtre).
 *
 * Rien n'est converti ici : « Optimiser » enregistre le lot et met en file un
 * job par image, puis rend la main. La progression vit en base et survit a la
 * fermeture du navigateur.
 */
#[Layout('layouts.admin')]
class ImageOptimizer extends Component
{
    public const LIMITS = [5, 25, 50, 100];

    public string $organizationId = '';

    public string $surface = '';

    public int $limit = 25;

    public bool $confirmed = false;

    /** @var array{count: int, bytes: int, items: list<array{surface: string, path: string, bytes: int|null}>}|null */
    public ?array $simulation = null;

    public ?string $notice = null;

    /** TASK-1875 — simulation du lot manuel (toutes surfaces, 25 images au plus). */
    public ?array $manualSimulation = null;

    public bool $manualConfirmed = false;

    public ?string $manualNotice = null;

    /**
     * Lot manuel lance DANS cette page : seul lui demarre sans clic
     * supplementaire. Rouvrir la console ne relance jamais rien.
     */
    public ?string $autostartBatchId = null;

    /**
     * Defense en profondeur : `AdminMiddleware` est deja persistant pour
     * Livewire (AppServiceProvider), cette garde couvre chaque requete du
     * composant quoi qu'il arrive a cette declaration.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['organizationId', 'surface', 'limit'], true)) {
            $this->simulation = null;
            $this->confirmed = false;
            $this->notice = null;
        }
    }

    public function simulate(ImageOptimizationInventory $inventory): void
    {
        $this->validateFilters($inventory);

        $sources = $inventory->pending($this->surface ?: null, $this->organizationId ?: null, $this->limit);
        $items = $sources->take(25)->map(fn (ImageSource $source) => [
            'surface' => $source->surface,
            'path' => $source->path,
            'bytes' => $inventory->sourceSize($source),
        ])->all();

        $this->simulation = [
            'count' => $sources->count(),
            'bytes' => array_sum(array_map(fn ($item) => (int) $item['bytes'], $items)),
            'items' => $items,
        ];
    }

    public function optimize(ImageOptimizationInventory $inventory, ImageOptimizationBatches $batches): void
    {
        $this->validateFilters($inventory);
        $this->validate(['confirmed' => ['accepted']], ['confirmed.accepted' => __('admin.image_optimizer.confirm_required')]);

        try {
            $batch = $batches->launch($this->surface ?: null, $this->organizationId ?: null, $this->limit);
        } catch (ActiveBatchExists) {
            $this->addError('launch', __('admin.image_optimizer.already_running'));

            return;
        } catch (ImageQueueNotConfigured) {
            // Garde metier : la couche Livewire ne fait que la relayer.
            $this->addError('launch', __('admin.image_optimizer.queue_not_configured'));

            return;
        }

        $this->confirmed = false;
        $this->simulation = null;
        $this->notice = $batch === null ? __('admin.image_optimizer.nothing_to_do') : null;
    }

    /**
     * TASK-1875 — simulation du lot manuel : nombre et volume estime, lecture
     * seule (une lecture de taille par image, 25 au plus).
     */
    public function simulateManual(ManualImageOptimization $manual, ImageOptimizationInventory $inventory): void
    {
        $sources = $manual->candidates(ManualImageOptimization::MAX_ITEMS);
        $items = $sources->map(fn (ImageSource $source) => [
            'surface' => $source->surface,
            'path' => $source->path,
            'bytes' => $inventory->sourceSize($source),
        ])->all();

        $this->manualSimulation = [
            'count' => $sources->count(),
            'bytes' => array_sum(array_map(fn ($item) => (int) $item['bytes'], $items)),
            'items' => $items,
        ];
        $this->manualConfirmed = false;
        $this->manualNotice = null;
    }

    /**
     * TASK-1875 — enregistre le lot manuel. AUCUNE conversion ici : les images
     * sont traitees une par une, chacune dans sa propre requete.
     */
    public function launchManual(ManualImageOptimization $manual): void
    {
        $this->validate(
            ['manualConfirmed' => ['accepted']],
            ['manualConfirmed.accepted' => __('admin.image_optimizer.confirm_required')],
        );

        try {
            $batch = $manual->launch(ManualImageOptimization::MAX_ITEMS);
        } catch (ActiveBatchExists) {
            $this->addError('manualLaunch', __('admin.image_optimizer.already_running'));

            return;
        }

        $this->manualConfirmed = false;
        $this->manualSimulation = null;
        $this->manualNotice = $batch === null ? __('admin.image_optimizer.nothing_to_do') : null;
        $this->autostartBatchId = $batch?->id;
    }

    #[On('image-batch-finished')]
    public function batchFinished(): void
    {
        // Re-rendu : la synthese et l'historique integrent le lot termine.
    }

    public function render(ImageOptimizationInventory $inventory, ImageOptimizationBatches $batches): View
    {
        $summary = $inventory->summary($this->organizationId ?: null);

        $totals = ['total' => 0, 'pending' => 0, 'ready' => 0, 'skipped' => 0, 'failed' => 0, 'missing' => 0, 'source_bytes' => 0, 'variant_bytes' => 0];
        foreach ($summary as $line) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $line[$key];
            }
        }

        $current = $batches->active()
            ?? ImageOptimizationBatch::query()->where('finished_at', '>=', now()->subHour())->latest('finished_at')->first();

        $history = ImageOptimizationBatch::query()
            ->with('organization:id,name')
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (ImageOptimizationBatch $batch) => ['batch' => $batch, 'progress' => $batches->progress($batch)]);

        return view('livewire.admin.image-optimizer', [
            'summary' => $summary,
            'totals' => $totals,
            'surfaces' => $inventory->surfaces(),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'limits' => self::LIMITS,
            'manualMax' => ManualImageOptimization::MAX_ITEMS,
            'current' => $current,
            'history' => $history,
            'workerAlive' => app(ImageWorkerHeartbeat::class)->isAlive(),
            'queueReady' => app(ImageQueue::class)->isAsync(),
            'queueConnection' => app(ImageQueue::class)->connection(),
            'queueDriver' => app(ImageQueue::class)->driver(),
            'workerSeen' => app(ImageWorkerHeartbeat::class)->lastSeen(),
        ]);
    }

    private function validateFilters(ImageOptimizationInventory $inventory): void
    {
        $this->validate([
            'surface' => ['nullable', Rule::in(array_merge([''], $inventory->surfaces()))],
            'organizationId' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== '' && ! Organization::query()->whereKey($value)->exists()) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));
                }
            }],
            'limit' => ['required', 'integer', Rule::in(self::LIMITS)],
        ]);
    }
}
