<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Services\Loops\LoopPresetSyncService;
use App\Services\LoopTypeSettingsService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What each Loop type is made of — super-admin only.
 *
 * A type is a card composition and an availability, and both are decided here
 * rather than in a deployment. The screen is deliberately platform-level: an
 * Organization does not get to redefine what a Loop type *is*, and there is no
 * per-Loop preset — a Loop's own composition is edited from the Loop itself.
 *
 * Nothing this screen saves is retroactive. Changing a preset changes what
 * future Loops of that type are built with, and what a type change applies; it
 * never removes a card from a Loop that already has one. That rule is the whole
 * reason applyPreset() is additive.
 */
class AdminLoopTypeController extends Controller
{
    /** @var array<int, string>|null Socle en vigueur avant l'enregistrement. */
    private ?array $presetBefore = null;

    public function __construct(
        private LoopTypeRegistry $types,
        private LoopTypeSettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->is_admin, 403);

        $registry = app(LoopCardRegistry::class);
        $rows = [];

        foreach ($this->types->all() as $key => $definition) {
            $rows[$key] = [
                'key' => $key,
                'label' => __($definition['label_key']),
                'description' => __($definition['description_key']),
                'cards' => $this->settings->cardsFor($key),
                'available' => $this->settings->isAvailable($key),
                'customised' => $this->settings->isCustomised($key),
                'loops' => $this->countLoopsOfType($key),
            ];
        }

        return view('admin.loop-types.index', [
            'types' => $rows,
            // Meme registre que le workspace : un socle ne peut nommer qu'une
            // Card reellement rendue.
            'catalogue' => $registry->manageableCatalogue(),
        ]);
    }

    public function update(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless($this->types->exists($type), 404);

        $data = $request->validate([
            'cards' => 'nullable|array',
            'cards.*' => 'string',
            'available' => 'nullable|boolean',
        ]);

        $cards = $data['cards'] ?? [];
        $available = (bool) ($data['available'] ?? false);

        // Lu avant l'enregistrement, pour pouvoir dire ce qui quitte le socle.
        $this->presetBefore = $this->settings->cardsFor($type);

        // A type someone can choose must compose something. An empty preset
        // would hand a new Loop an empty workspace, which is not a product
        // decision anyone would make on purpose.
        if ($available && $cards === []) {
            return back()->withErrors([
                'cards' => __('loops.types_admin_error_empty'),
            ]);
        }

        $sync = app(LoopPresetSyncService::class);

        // L'impact est mesure *avant* d'enregistrer : apres, cardsFor() rendrait
        // le nouveau socle et la comparaison serait vide.
        $impact = $sync->previewForCards($type, $cards);

        $this->settings->save($type, $cards, $available);

        // Puis on l'applique. Sans cela, modifier un socle ne touchait aucune
        // Boucle existante et l'ecran ne le disait pas : le SuperAdmin repartait
        // en croyant avoir donne une Card qu'il n'avait donnee a personne.
        // Additif — aucune Card retiree, aucune Card eteinte rallumee.
        $applied = $sync->sync($type);

        $message = __('loops.types_admin_saved', ['type' => $this->types->label($type)]);

        if ($applied['loops_affected'] > 0) {
            $message .= ' '.__('loops.types_admin_synced', [
                'cards' => collect(array_keys($applied['cards_added']))
                    ->map(fn ($k) => $this->types->cardLabel($k))
                    ->implode(', '),
                'loops' => $applied['loops_affected'],
            ]);
        }

        // Une Card retiree du socle reste sur les Boucles qui l'ont : on le dit,
        // plutot que de laisser croire a une suppression silencieuse.
        $removed = array_diff($this->presetBefore ?? [], $cards);

        if ($removed !== []) {
            $message .= ' '.__('loops.types_admin_removed_kept', [
                'cards' => collect($removed)->map(fn ($k) => $this->types->cardLabel($k))->implode(', '),
            ]);
        }

        return redirect()
            ->route('admin.loop-types')
            ->with('success', $message);
    }

    public function reset(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless($this->types->exists($type), 404);

        $this->settings->reset($type);

        return redirect()
            ->route('admin.loop-types')
            ->with('success', __('loops.types_admin_reset_done', ['type' => $this->types->label($type)]));
    }

    /** Loops a change would reach from now on, legacy alias folded in. */
    private function countLoopsOfType(string $type): int
    {
        $aliases = array_keys(array_filter(
            config('loop_types.legacy_aliases', []),
            fn ($target) => $target === $type,
        ));

        return Loop::query()->whereIn('type', array_merge([$type], $aliases))->count();
    }
}
