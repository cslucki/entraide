<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomLoopType;
use App\Models\Loop;
use App\Models\Organization;
use App\Services\Loops\LoopPresetSyncService;
use App\Services\Loops\LoopTypeCreationService;
use App\Services\LoopTypeSettingsService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * What each Loop type is made of — super-admin only.
 *
 * A type is a card composition, an availability and a **word**, and all three
 * are decided here rather than in a deployment. There is no per-Loop preset — a
 * Loop's own composition is edited from the Loop itself.
 *
 * **Deux portees, une seule grammaire : PLATEFORME -> ORGANIZATION.** L'ecran
 * lit et ecrit au niveau choisi, et `null` y signifie toujours « le niveau
 * au-dessus ». Une Organization peut donc appeler `training` « Parcours de
 * formation » sans que la **cle technique** bouge : `key = training` reste
 * `training`, c'est elle que portent `loops.type`, les socles et les
 * permissions. Renommer la cle aurait fait bouger sept ans de donnees pour
 * changer un mot.
 *
 * La **Boucle** est le troisieme niveau, et il n'est pas administre ici : la
 * composition d'une Boucle vit dans `loop_cards`, que ce service n'ecrit pas.
 *
 * Nothing this screen saves is retroactive. Changing a preset changes what
 * future Loops of that type are built with, and what a type change applies; it
 * never removes a card from a Loop that already has one. That rule is the whole
 * reason applyPreset() is additive.
 */
class AdminLoopTypeController extends Controller
{
    /** La valeur que prend le selecteur pour dire « pas d'Organization ». */
    private const SCOPE_PLATFORM = 'platform';

    /** @var array<int, string>|null Socle en vigueur avant l'enregistrement. */
    private ?array $presetBefore = null;

    public function __construct(
        private LoopTypeRegistry $types,
        private LoopTypeSettingsService $settings,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->is_admin, 403);

        $scope = $this->scope($request);
        $registry = app(LoopCardRegistry::class);
        $rows = [];

        // **Le catalogue de la portee affichee** : un type cree par cette
        // Organization doit y figurer, sinon il serait invisible la ou il a ete
        // cree.
        foreach ($this->types->all($scope) as $key => $definition) {
            $propres = $this->settings->ownTextsFor($key, $scope);

            $rows[$key] = [
                'key' => $key,
                // Le mot **en vigueur a cette portee**, pas la traduction du
                // fichier : sinon l'ecran qui renomme serait le seul a ne pas
                // voir le renommage.
                'label' => $this->types->label($key, $scope),
                'description' => $this->types->description($key, $scope),
                // Ce que ce niveau a lui-meme decide, pour que le champ n'affiche
                // pas ce dont il herite — le recopier figerait le type.
                'own_label' => $propres['label'],
                'own_description' => $propres['description'],
                // Et ce dont il heriterait si on vidait le champ.
                // Un type cree porte un mot ecrit, pas une cle de traduction.
                'inherited_label' => $scope
                    ? $this->types->label($key)
                    : (isset($definition['label_key']) ? __($definition['label_key']) : ($definition['label'] ?? $key)),
                'cards' => $this->settings->cardsFor($key, $scope),
                'available' => $this->settings->isAvailable($key, $scope),
                'customised' => $scope
                    ? $this->settings->hasOrganizationOverride($key, $scope)
                    : $this->settings->isCustomised($key),
                'loops' => $this->countLoopsOfType($key, $scope),
            ];
        }

        return view('admin.loop-types.index', [
            'types' => $rows,
            'scope' => $scope,
            // Le selecteur de portee. Peu d'Organizations, et l'ecran est
            // SuperAdmin : la liste entiere est legitime ici.
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'slug']),
            // Meme registre que le workspace : un socle ne peut nommer qu'une
            // Card reellement rendue.
            'catalogue' => $registry->manageableCatalogue(),
            // Les types crees, pour pouvoir les retirer : seuls ceux-la se
            // suppriment, ceux du fichier n'existent pas en base.
            'created' => CustomLoopType::query()
                ->when($scope, fn ($q) => $q->where('organization_id', $scope->id))
                ->when(! $scope, fn ($q) => $q->whereNull('organization_id'))
                ->get()->keyBy('key'),
        ]);
    }

    /**
     * La portee demandee, **resolue** — jamais recue telle quelle.
     *
     * L'identifiant arrive d'un parametre de requete : il est confronte a la
     * table avant de servir de portee, faute de quoi n'importe quelle valeur
     * forgee deviendrait un `organization_id` d'ecriture. Le SuperAdmin a le
     * droit d'administrer n'importe quelle Organization — c'est deja verifie par
     * l'appelant — donc la resolution suffit, mais elle ne se saute pas.
     *
     * `null` signifie la Plateforme, et c'est le defaut.
     *
     * **La forme est verifiee avant la table.** `organizations.id` est une
     * colonne `uuid` : PostgreSQL refuse la comparaison avec une chaine qui n'en
     * est pas une et leve `22P02`, soit une 500 la ou il faut une 404. SQLite,
     * lui, compare comme du texte et ne trouve rien — le defaut ne se voit donc
     * qu'en PostgreSQL, c'est-a-dire en production.
     */
    private function scope(Request $request): ?Organization
    {
        $id = $request->input('scope');

        if (! is_string($id) || $id === '' || $id === self::SCOPE_PLATFORM) {
            return null;
        }

        abort_unless(Str::isUuid($id), 404);

        return Organization::query()->findOrFail($id);
    }

    public function update(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless($this->types->exists($type), 404);

        $data = $request->validate([
            'cards' => 'nullable|array',
            'cards.*' => 'string',
            'available' => 'nullable|boolean',
            // Le mot, pas la cle. `max` cadre la saisie ; le service tronque de
            // toute façon, pour que l'API ne depende pas de cette validation.
            'label' => 'nullable|string|max:80',
            'description' => 'nullable|string|max:2000',
        ]);

        $scope = $this->scope($request);
        $cards = $data['cards'] ?? [];
        $available = (bool) ($data['available'] ?? false);

        // Lu avant l'enregistrement, pour pouvoir dire ce qui quitte le socle.
        $this->presetBefore = $this->settings->cardsFor($type, $scope);

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
        // le nouveau socle et la comparaison serait vide. Et il se compte **dans
        // la portee reglee** : regler une Organization n'annonce pas un chiffre
        // pris sur tout le parc.
        $impact = $sync->previewForCards($type, $cards, $scope);

        $this->settings->save($type, $cards, $available, $scope);

        // Le mot se range sur la meme ligne, et les deux ecritures ne se
        // marchent pas dessus : chacune ne touche que ses colonnes.
        $this->settings->rename($type, $data['label'] ?? null, $data['description'] ?? null, $scope);

        // Puis on l'applique. Sans cela, modifier un socle ne touchait aucune
        // Boucle existante et l'ecran ne le disait pas : le SuperAdmin repartait
        // en croyant avoir donne une Card qu'il n'avait donnee a personne.
        // Additif — aucune Card retiree, aucune Card eteinte rallumee.
        $applied = $sync->sync($type, $scope);

        $message = __('loops.types_admin_saved', ['type' => $this->types->label($type, $scope)]);

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
            ->route('admin.loop-types', $this->scopeParam($scope))
            ->with('success', $message);
    }

    /**
     * Creer un type dans la portee affichee.
     *
     * La cle n'est pas saisie : elle est **forgee** depuis le mot, prefixee par
     * l'Organization, et ne changera plus. Renommer le type plus tard ne la
     * touchera pas — c'est ce que TASK-1116 a rendu possible, et c'est pourquoi
     * on peut se permettre de la deriver d'un mot qui, lui, bougera.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $data = $request->validate([
            'label' => 'required|string|max:80',
            'description' => 'nullable|string|max:2000',
            'based_on' => 'nullable|string',
        ]);

        $scope = $this->scope($request);

        try {
            $type = app(LoopTypeCreationService::class)->create(
                organization: $scope,
                label: $data['label'],
                description: $data['description'] ?? null,
                basedOn: $data['based_on'] ?: null,
                author: $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['label' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.loop-types', $this->scopeParam($scope))
            ->with('success', __('loops.types_admin_created', [
                'type' => $type->label,
                'key' => $type->key,
            ]));
    }

    /**
     * Retirer un type cree.
     *
     * Refuse tant qu'une Boucle le porte : le service dit pourquoi, et l'ecran
     * le repete plutot que d'echouer en silence.
     */
    public function destroy(Request $request, CustomLoopType $customLoopType): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        $scope = $this->scope($request);

        try {
            app(LoopTypeCreationService::class)->delete($customLoopType);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['type' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.loop-types', $this->scopeParam($scope))
            ->with('success', __('loops.types_admin_deleted'));
    }

    /**
     * Revenir aux reglages du niveau au-dessus.
     *
     * **Ne defait que la portee affichee.** Reinitialiser une Organization la
     * remet a l'heritage de la Plateforme ; elle ne touche ni sa voisine, ni le
     * niveau Plateforme, ni la composition d'aucune Boucle existante — celle-ci
     * vit dans `loop_cards`, que ce chemin n'ecrit pas.
     */
    public function reset(Request $request, string $type): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless($this->types->exists($type), 404);

        $scope = $this->scope($request);

        $this->settings->reset($type, $scope);

        return redirect()
            ->route('admin.loop-types', $this->scopeParam($scope))
            ->with('success', __($scope ? 'loops.types_admin_reset_done_org' : 'loops.types_admin_reset_done', [
                'type' => $this->types->label($type, $scope),
                'organization' => $scope?->name,
            ]));
    }

    /** @return array<string, string> */
    private function scopeParam(?Organization $scope): array
    {
        return $scope ? ['scope' => $scope->id] : [];
    }

    /**
     * Loops a change would reach from now on, legacy alias folded in.
     *
     * Compte **dans la portee** : le nombre affiche a cote d'un type doit etre
     * celui que le geste toucherait, pas celui du parc entier.
     */
    private function countLoopsOfType(string $type, ?Organization $scope = null): int
    {
        $aliases = array_keys(array_filter(
            config('loop_types.legacy_aliases', []),
            fn ($target) => $target === $type,
        ));

        return Loop::query()
            ->whereIn('type', array_merge([$type], $aliases))
            ->when($scope, fn ($q) => $q->where('organization_id', $scope->id))
            ->count();
    }
}
