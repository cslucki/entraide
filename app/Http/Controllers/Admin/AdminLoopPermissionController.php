<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Models\Organization;
use App\Services\LoopPermissionSettingsService;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The permission matrices, global and Organization-scoped.
 *
 * One controller for both because the screens differ only in which layer they
 * write to and how much they may change — the way a cell is built, the value it
 * shows and where that value comes from are identical, and duplicating that
 * would let the two drift.
 *
 * Neither screen ever configures an individual Loop: there is no loop_id
 * anywhere in this flow.
 */
class AdminLoopPermissionController extends Controller
{
    public function __construct(
        private LoopTypeRegistry $types,
        private LoopRoleRegistry $roles,
        private LoopPermissionSettingsService $settings,
        private LoopPermissionResolver $resolver,
    ) {}

    // ── Matrice globale (super-admin) ───────────────────────────────────────

    public function index(Request $request): View
    {
        abort_unless($request->user()?->is_admin, 403);

        $type = $this->resolveType($request);

        return view('admin.loop-permissions.index', $this->matrixData($type, null) + [
            // Only meaningful for the global screen: how many Loops a change
            // would reach. Counted with the legacy alias folded in.
            'affectedLoops' => $this->countLoopsOfType($type, null),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $type = $this->resolveType($request);

        $this->applyChanges($request, fn (string $role, string $permission, ?bool $value) => $value === null
            ? $this->settings->clearGlobal($type, $role, $permission)
            : $this->settings->setGlobal($type, $role, $permission, $value));

        return redirect()
            ->route('admin.loop-permissions', ['type' => $type])
            ->with('success', __('loops.permissions_saved'));
    }

    // ── Matrice Organization ────────────────────────────────────────────────

    public function orgIndex(Request $request, Organization $organization): View
    {
        $this->assertOrganizationAdmin($request, $organization);

        $type = $this->resolveType($request);

        return view('admin.loop-permissions.organization', $this->matrixData($type, $organization) + [
            'organization' => $organization,
            'affectedLoops' => $this->countLoopsOfType($type, $organization),
        ]);
    }

    public function orgUpdate(Request $request, Organization $organization): RedirectResponse
    {
        $this->assertOrganizationAdmin($request, $organization);

        $type = $this->resolveType($request);

        $this->applyChanges($request, fn (string $role, string $permission, ?bool $value) => $value === null
            ? $this->settings->clearOrganization($organization, $type, $role, $permission)
            : $this->settings->setOrganization($organization, $type, $role, $permission, $value));

        return redirect()
            ->route('organization.admin.loop-permissions', ['organization' => $organization->slug, 'type' => $type])
            ->with('success', __('loops.permissions_saved'));
    }

    // ── Construction partagée ───────────────────────────────────────────────

    /**
     * Everything both screens need, grouped by module.
     *
     * `effective` comes from the resolver itself rather than being recomputed
     * here, so what the matrix shows is exactly what the application applies.
     *
     * @return array<string, mixed>
     */
    private function matrixData(string $type, ?Organization $organization): array
    {
        $rows = [];

        foreach ($this->settings->permissions() as $key => $definition) {
            $cells = [];

            foreach (LoopRoleRegistry::CANONICAL as $role) {
                $orgOverride = $organization
                    ? $this->settings->organizationOverride($organization, $type, $role, $key)
                    : null;
                $globalOverride = $this->settings->globalOverride($type, $role, $key);

                // Which layer this screen may write, and which it inherits.
                $own = $organization ? $orgOverride : $globalOverride;
                $inherited = $organization
                    ? ($globalOverride ?? $this->resolver->systemValue($type, $role, $key))
                    : $this->resolver->systemValue($type, $role, $key);

                $cells[$role] = [
                    'state' => $own === null ? 'inherited' : ($own ? 'allowed' : 'denied'),
                    'effective' => $this->resolver->resolveForRole($organization, $type, $role, $key),
                    'inherited' => $inherited,
                    'source' => $own !== null
                        ? ($organization ? 'organization' : 'global')
                        : ($organization && $globalOverride !== null ? 'global' : 'system'),
                ];
            }

            $rows[$definition['module']][$key] = [
                'key' => $key,
                'label' => app()->getLocale() === 'en' ? $definition['label_en'] : $definition['label_fr'],
                'description' => $definition['description'],
                'locked' => (bool) $definition['locked'],
                'requires_card' => $definition['requires_card'] ?? null,
                'cells' => $cells,
            ];
        }

        return [
            'type' => $type,
            'types' => $this->types->all(),
            'roles' => LoopRoleRegistry::CANONICAL,
            'modules' => $rows,
            'invariants' => config('loop_permissions.invariants', []),
        ];
    }

    /**
     * Applies a posted matrix.
     *
     * Only cells whose state actually moved are written: a value left inherited
     * writes nothing at all, which is what keeps both stores sparse.
     */
    private function applyChanges(Request $request, callable $write): void
    {
        $posted = $request->input('cells', []);

        if (! is_array($posted)) {
            return;
        }

        foreach ($posted as $permission => $roles) {
            if (! is_array($roles) || $this->settings->isLocked((string) $permission)) {
                continue;
            }

            foreach ($roles as $role => $state) {
                if (! $this->roles->isCanonical((string) $role)) {
                    continue;
                }

                $value = match ($state) {
                    'allowed' => true,
                    'denied' => false,
                    default => null,
                };

                $write((string) $role, (string) $permission, $value);
            }
        }
    }

    private function resolveType(Request $request): string
    {
        $type = (string) $request->input('type', $this->types->default());

        return $this->types->exists($type) ? $type : $this->types->default();
    }

    /** Loops a change would reach, legacy alias folded in. */
    private function countLoopsOfType(string $type, ?Organization $organization): int
    {
        $aliases = array_keys(array_filter(
            config('loop_types.legacy_aliases', []),
            fn ($target) => $target === $type,
        ));

        return Loop::query()
            ->when($organization, fn ($q) => $q->where('organization_id', $organization->id))
            ->whereIn('type', array_merge([$type], $aliases))
            ->count();
    }

    private function assertOrganizationAdmin(Request $request, Organization $organization): void
    {
        $user = $request->user();

        // Tenant strict: an Organization admin only ever reaches their own.
        abort_unless(
            $user && ! $user->isDeactivated()
                && ($user->is_admin || ($user->organization_id === $organization->id && $organization->admin_id === $user->id)),
            403,
        );
    }
}
