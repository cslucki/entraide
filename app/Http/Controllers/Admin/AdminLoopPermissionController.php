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
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * La matrice des permissions — **SuperAdmin uniquement**, avec selecteur de
 * portee : PLATEFORME -> ORGANIZATION.
 *
 * Un ecran administre *par* une Organization avait existe et avait ete retire en
 * revue : redefinir ses propres permissions n'appartient pas au locataire. Cette
 * decision tient toujours. Ce qui revient ici n'est pas cet ecran : c'est le
 * **SuperAdmin** qui regle une Organization depuis la Plateforme. La question
 * « qui administre » recoit donc la meme reponse qu'a l'epoque ; seule « d'ou
 * l'on peut regler » change.
 *
 * La couche d'override par Organization etait restee intacte dans le resolver et
 * son stockage — `organizationOverride()`, `setOrganization()`,
 * `clearOrganization()` — et n'est pas reecrite : cet ecran s'y branche.
 *
 * **Trois etats, une grammaire** : Herite / Autorise / Refuse, ou « herite »
 * signifie l'absence de valeur, donc le niveau au-dessus. La meme que les types
 * et les socles.
 *
 * La matrice ne configure jamais une Boucle : il n'y a aucun `loop_id` dans ce
 * flux.
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

        $scope = $this->scope($request);
        $type = $this->resolveType($request, $scope);

        return view('admin.loop-permissions.index', $this->matrixData($type, $scope) + [
            // Le nombre de Boucles qu'un changement atteindrait, **dans la
            // portee reglee** : regler une Organization n'annonce pas un chiffre
            // pris sur tout le parc.
            'affectedLoops' => $this->countLoopsOfType($type, $scope),
            'scope' => $scope,
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }

    /**
     * La portee demandee, **resolue** — jamais recue telle quelle.
     *
     * Meme garde qu'a l'ecran des types : la forme est verifiee avant la table,
     * parce que `organizations.id` est une colonne `uuid` et que PostgreSQL leve
     * `22P02` sur une chaine qui n'en est pas une — soit une 500 la ou il faut
     * une 404, invisible sous SQLite.
     */
    private function scope(Request $request): ?Organization
    {
        $id = $request->input('scope');

        if (! is_string($id) || $id === '' || $id === 'platform') {
            return null;
        }

        abort_unless(Str::isUuid($id), 404);

        return Organization::query()->findOrFail($id);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $scope = $this->scope($request);
        $type = $this->resolveType($request, $scope);

        // **La portee decide de la couche ecrite**, et d'elle seule : regler une
        // Organization ne touche jamais le niveau Plateforme ni sa voisine.
        $this->applyChanges($request, fn (string $role, string $permission, ?bool $value) => match (true) {
            $scope !== null && $value === null => $this->settings->clearOrganization($scope, $type, $role, $permission),
            $scope !== null => $this->settings->setOrganization($scope, $type, $role, $permission, $value),
            $value === null => $this->settings->clearGlobal($type, $role, $permission),
            default => $this->settings->setGlobal($type, $role, $permission, $value),
        });

        return redirect()
            ->route('admin.loop-permissions', array_filter(['type' => $type, 'scope' => $scope?->id]))
            ->with('success', __('loops.permissions_saved'));
    }

    /**
     * Revenir aux permissions du niveau au-dessus, pour ce type.
     *
     * **Ne defait que la portee affichee.** Sur une Organization, chaque cellule
     * repasse en « herite » et c'est la Plateforme qui reprend la main ; sa
     * voisine et le niveau Plateforme ne bougent pas. Aucun droit reel n'est
     * perdu : ce qui disparait, ce sont des exceptions locales.
     */
    public function reset(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $scope = $this->scope($request);
        $type = $this->resolveType($request, $scope);

        foreach (array_keys($this->settings->permissions()) as $permission) {
            if ($this->settings->isLocked((string) $permission)) {
                continue;
            }

            foreach (LoopRoleRegistry::CANONICAL as $role) {
                $scope !== null
                    ? $this->settings->clearOrganization($scope, $type, $role, (string) $permission)
                    : $this->settings->clearGlobal($type, $role, (string) $permission);
            }
        }

        return redirect()
            ->route('admin.loop-permissions', array_filter(['type' => $type, 'scope' => $scope?->id]))
            ->with('success', __($scope ? 'loops.permissions_reset_org' : 'loops.permissions_reset_platform'));
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
            'types' => $this->types->all($organization),
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

    private function resolveType(Request $request, ?Organization $scope = null): string
    {
        $type = (string) $request->input('type', $this->types->default());

        // `exists()` connait aussi les types crees — y compris ceux d'une autre
        // Organization. On verifie donc qu'il est bien **offert dans la portee
        // affichee**, sans quoi le SuperAdmin pourrait regler depuis LaunchPals
        // les permissions d'un type qui n'appartient qu'a une voisine.
        return isset($this->types->all($scope)[$type]) ? $type : $this->types->default();
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
}
