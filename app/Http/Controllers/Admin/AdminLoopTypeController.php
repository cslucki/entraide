<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Services\LoopTypeSettingsService;
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
    public function __construct(
        private LoopTypeRegistry $types,
        private LoopTypeSettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->is_admin, 403);

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
            'catalogue' => config('loop_cards.cards', []),
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

        // A type someone can choose must compose something. An empty preset
        // would hand a new Loop an empty workspace, which is not a product
        // decision anyone would make on purpose.
        if ($available && $cards === []) {
            return back()->withErrors([
                'cards' => __('loops.types_admin_error_empty'),
            ]);
        }

        $this->settings->save($type, $cards, $available);

        return redirect()
            ->route('admin.loop-types')
            ->with('success', __('loops.types_admin_saved', ['type' => $this->types->label($type)]));
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
