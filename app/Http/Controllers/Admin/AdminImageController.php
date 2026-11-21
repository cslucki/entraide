<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImageVariants;
use App\Models\ImageVariant;
use App\Models\Organization;
use App\Support\Images\ImageOptimizationControl;
use App\Support\Images\ImageOptimizationInventory;
use App\Support\Images\ImageOptimizationRunner;
use App\Support\Images\ImageSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * TASK-1873 — console SuperAdmin `/admin/images`.
 *
 * Dans le groupe `['auth', 'admin']` : `AdminMiddleware` exige `is_admin` et
 * rend 403 sinon, en LECTURE comme en ECRITURE.
 *
 * ## Ce qu'elle ne fait jamais
 *
 *   - traiter une image DANS la requete : un lot est MIS EN FILE (queue
 *     `images`), via le meme `ImageOptimizationRunner` que la commande ;
 *   - lancer un lot sans confirmation explicite, ni au-dela du plafond
 *     `image_variants.console_max_batch` (25) ;
 *   - ecrire quoi que ce soit en GET : la simulation lit le registre et le
 *     poids des originaux, rien d'autre.
 *
 * En local, la queue est `sync` : un « lot » s'execute alors dans la requete
 * meme. Le plafond tombe a 5 pour que l'essai reste un essai.
 */
class AdminImageController extends Controller
{
    private const SYNC_MAX_BATCH = 5;

    public function __construct(
        private readonly ImageOptimizationInventory $inventory,
        private readonly ImageOptimizationControl $control,
    ) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $summary = $this->inventory->summary($filters['organization_id']);
        $totals = collect($summary)->reduce(function (array $carry, array $line) {
            foreach ($line as $key => $value) {
                $carry[$key] = ($carry[$key] ?? 0) + $value;
            }

            return $carry;
        }, []);

        $simulation = null;
        if ($request->boolean('simulate')) {
            $simulation = $this->inventory
                ->pending($filters['surface'], $filters['organization_id'], $this->maxBatch())
                ->map(fn (ImageSource $source) => [
                    'source' => $source,
                    'bytes' => $this->inventory->sourceSize($source),
                ]);
        }

        $recentQuery = ImageVariant::query()
            ->with('organization:id,name')
            ->when($filters['surface'], fn ($q, $s) => $q->where('surface', $s))
            ->when($filters['organization_id'], fn ($q, $o) => $q->where('organization_id', $o))
            ->latest('processed_at');

        return view('admin.outils.images', [
            'summary' => $summary,
            'totals' => $totals,
            'surfaces' => $this->inventory->surfaces(),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
            'simulation' => $simulation,
            'recent' => (clone $recentQuery)->where('status', ImageVariant::STATUS_READY)->limit(15)->get(),
            // `failures` et non `errors` : ce nom est le sac d'erreurs de
            // validation partage par Laravel avec toutes les vues.
            'failures' => (clone $recentQuery)->whereIn('status', [ImageVariant::STATUS_FAILED, ImageVariant::STATUS_MISSING, ImageVariant::STATUS_SKIPPED])->limit(15)->get(),
            'paused' => $this->control->paused(),
            'queue' => $this->queueState(),
            'maxBatch' => $this->maxBatch(),
        ]);
    }

    public function launch(Request $request, ImageOptimizationRunner $runner): RedirectResponse
    {
        $data = $request->validate([
            'surface' => ['nullable', Rule::in($this->inventory->surfaces())],
            'organization_id' => ['nullable', 'uuid', 'exists:organizations,id'],
            'limit' => ['required', 'integer', 'min:1', 'max:'.$this->maxBatch()],
            'confirm' => ['accepted'],
        ], [
            'confirm.accepted' => __('admin.image_optimizer.confirm_required'),
        ]);

        $back = redirect()->route('admin.images', array_filter([
            'surface' => $data['surface'] ?? null,
            'organization_id' => $data['organization_id'] ?? null,
        ]));

        if ($this->control->paused()) {
            return $back->with('error', __('admin.image_optimizer.refused_paused'));
        }

        $queued = $runner->queueBatch($data['surface'] ?? null, $data['organization_id'] ?? null, (int) $data['limit']);

        return $back->with('success', __('admin.image_optimizer.launched', ['count' => $queued]));
    }

    public function pause(): RedirectResponse
    {
        $this->control->pause();

        return redirect()->route('admin.images')->with('success', __('admin.image_optimizer.paused_flash'));
    }

    public function resume(): RedirectResponse
    {
        $this->control->resume();

        return redirect()->route('admin.images')->with('success', __('admin.image_optimizer.resumed_flash'));
    }

    /** @return array{surface: string|null, organization_id: string|null} */
    private function filters(Request $request): array
    {
        $surface = $request->query('surface');
        $organizationId = $request->query('organization_id');

        return [
            'surface' => in_array($surface, $this->inventory->surfaces(), true) ? $surface : null,
            'organization_id' => is_string($organizationId) && Str::isUuid($organizationId) ? $organizationId : null,
        ];
    }

    private function maxBatch(): int
    {
        $max = (int) config('image_variants.console_max_batch', 25);

        return $this->isSync() ? min($max, self::SYNC_MAX_BATCH) : $max;
    }

    private function isSync(): bool
    {
        return config('queue.default') === 'sync';
    }

    /**
     * Jobs `images` en attente — lisible seulement avec le pilote `database`.
     *
     * @return array{connection: string, pending: int|null}
     */
    private function queueState(): array
    {
        $connection = (string) config('queue.default');
        $pending = null;

        if (config("queue.connections.{$connection}.driver") === 'database') {
            try {
                $pending = DB::table((string) config("queue.connections.{$connection}.table", 'jobs'))
                    ->where('queue', ProcessImageVariants::QUEUE)
                    ->count();
            } catch (\Throwable) {
                $pending = null;
            }
        }

        return ['connection' => $connection, 'pending' => $pending];
    }
}
