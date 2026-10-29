<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\AssignData\Dataset;
use App\Support\AssignData\DatasetClassification;
use App\Support\AssignData\DatasetRegistry;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use App\Support\ScenarioManager\SandboxGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * TASK-1631 — `/admin/outils/assign-data`, reconstruit sur une source unique.
 *
 * ## Ce que l'outil fait, et ce qu'il ne fait plus
 *
 * Il diagnostique les lignes dont `organization_id` est NULL, et ne propose
 * de les rattacher que la ou un NULL est une ANOMALIE. Le registre dit
 * lesquelles : sur 38 tables nullables, 26 sont tenant, 10 sont globales (NULL
 * = « Plateforme »), 1 est un journal, 1 est un pivot ambigu. L'outil
 * historique en marquait 38 sur 40 « assignable », referentiels compris —
 * rattacher `translation_overrides` ou `loop_type_settings` aurait supprime
 * leur ligne globale, et viole l'index unique partiel qui la protege.
 *
 * ## Le detail est un LIEN, plus un `window.open`
 *
 * Les compteurs du tableau etaient des `<button>` qui fabriquaient une URL en
 * JavaScript et l'ouvraient dans une popup dimensionnee. Trois choses
 * pouvaient l'empecher, et aucune ne se voyait : un `return` place plus haut
 * dans le meme bloc pour une raison etrangere, un bloqueur de popup, et une
 * interpolation Blade rendant `organization_id=` vide. Un compteur qui ouvre
 * une vue filtree est une NAVIGATION : c'est un `<a href>`, vers une route
 * canonique par dataset, et cela se teste cote serveur.
 *
 * ## Une seule definition pour quatre usages
 *
 * COUNT, DETAIL, PREVIEW et UPDATE lisent tous `Dataset::query()` et la meme
 * condition `whereNull('organization_id')`. L'ancien controleur les
 * recalculait separement — et ils avaient diverge : l'UPDATE, lui, n'avait
 * aucun `whereNull` et reecrivait TOUTES les lignes du dataset.
 *
 * Le droit vient du middleware `admin` (`users.is_admin`) et de lui seul.
 */
class AdminAssignDataController extends Controller
{
    private const PER_PAGE = 50;

    private const FILTERS = ['all', 'with_organization', 'without_organization'];

    public function index(Request $request, DatasetRegistry $registry): View
    {
        $slug = trim((string) $request->query('organization', ''));
        $selected = $slug !== '' ? Organization::query()->where('slug', $slug)->first() : null;

        $rows = [];

        foreach ($registry->all() as $dataset) {
            $total = $dataset->query()->count();
            $without = $dataset->query()->whereNull('organization_id')->count();

            $rows[] = [
                'dataset' => $dataset,
                'total' => $total,
                'with_organization' => $total - $without,
                'without_organization' => $without,
                // Une action n'est proposee que si elle a un objet : la
                // classification l'autorise ET il y a quelque chose a
                // rattacher. Sinon l'ecran dit « aucune action », jamais un
                // bouton grise dont on ne sait pas s'il est casse.
                'actionable' => $dataset->isAssignable() && $without > 0,
            ];
        }

        return view('admin.outils.assign-data', [
            'rows' => $rows,
            'organizations' => Organization::query()->orderByDesc('is_default')->orderBy('name')->get(['id', 'slug', 'name']),
            'selected' => $selected,
            'unknownOrganization' => $slug !== '' && $selected === null ? $slug : null,
            'classifications' => DatasetClassification::cases(),
        ]);
    }

    /**
     * La vue detail d'UN dataset — la route canonique que les compteurs
     * ouvrent, avec leur filtre dans l'URL.
     */
    public function detail(Request $request, string $dataset, DatasetRegistry $registry): View
    {
        $definition = $registry->find($dataset);

        abort_if($definition === null, 404);

        $filter = in_array($request->query('filter'), self::FILTERS, true)
            ? $request->query('filter')
            : 'all';

        $query = $this->filtered($definition, $filter);

        // La meme requete que le compteur du tableau, filtree de la meme
        // maniere : c'est ce qui garantit que « 14 » ouvre bien 14 lignes.
        $total = $definition->query()->count();
        $without = $definition->query()->whereNull('organization_id')->count();

        $rows = $query
            ->select($definition->columns)
            ->orderBy($definition->columns[0])
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.outils.assign-data-detail', [
            'dataset' => $definition,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'rows' => $rows,
            'total' => $total,
            'with_organization' => $total - $without,
            'without_organization' => $without,
            'organizationNames' => $this->organizationNames($rows->getCollection()),
        ]);
    }

    /**
     * Le temps intermediaire : ce que l'affectation ferait, avant de la faire.
     */
    public function preview(Request $request, DatasetRegistry $registry): View|RedirectResponse
    {
        $data = $request->validate([
            'dataset' => ['required', 'string'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
        ]);

        $definition = $registry->find($data['dataset']);

        if ($definition === null || ! $definition->isAssignable()) {
            return $this->refuse($definition);
        }

        $query = $definition->query()->whereNull('organization_id');

        return view('admin.outils.assign-data-preview', [
            'dataset' => $definition,
            'organization' => Organization::query()->findOrFail($data['organization_id']),
            'count' => $query->count(),
            'sample' => (clone $query)->select($definition->columns)->limit(20)->get(),
        ]);
    }

    /**
     * La seule ecriture de l'outil.
     *
     * Trois gardes, dans cet ordre : la classification (une table globale ou
     * historique est refusee meme par un POST forge), la confirmation
     * explicite, et — pour un dataset marque critique — une phrase a recopier.
     */
    public function assign(Request $request, DatasetRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'dataset' => ['required', 'string'],
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'confirm' => ['required', 'accepted'],
        ]);

        $definition = $registry->find($data['dataset']);

        // LA garde. L'absence de bouton dans l'ecran n'en est pas une : une
        // classification non assignable est refusee ici, quoi qu'envoie le
        // client.
        if ($definition === null || ! $definition->isAssignable()) {
            return $this->refuse($definition);
        }

        if ($definition->critical) {
            $request->validate([
                'confirmation' => ['required', 'string', 'in:REASSIGN USERS'],
            ]);
        }

        $organization = Organization::query()->findOrFail($data['organization_id']);

        // TASK-1650 — jamais vers une sandbox de scenario.
        //
        // Trouve a la TROISIEME relecture, et c'est le plus grave des trois :
        // cet ecran affecte EN MASSE toutes les lignes sans Organization.
        // Sur `users`, cela versait d'un coup dans la sandbox tous les comptes
        // reels orphelins — la population que la suppression d'Organization
        // fabrique justement.
        //
        // Et depuis que T1650 a ferme les SORTIES, l'effet n'est plus une
        // fuite mais un PIEGE : ces comptes ne peuvent plus ressortir par
        // `/admin/users`, la sandbox ne peut plus etre supprimee par l'ecran
        // generique, et le preflight refuse Reset comme Remove parce qu'elle
        // contient desormais du contenu etranger. Fermer les sorties sans
        // fermer les entrees enferme les gens.
        if (SandboxGuard::estUneSandbox($organization)) {
            return back()->with('error', SandboxGuard::messageDestination($organization));
        }

        // TASK-1729 (A4) — refus AVANT ecriture quand le rattachement creerait
        // un doublon d'adresse dans l'Organization cible.
        //
        // ## Le cas SYMETRIQUE de celui que D1 ferme sur la suppression
        //
        // Cet ecran rattache d'un coup TOUS les comptes orphelins a
        // l'Organization choisie. Depuis T1729, une adresse est unique par
        // Organization : si un orphelin porte la meme adresse qu'un membre
        // DEJA present dans la cible, l'UPDATE de masse viole
        // `users_organization_email_unique`.
        //
        // Mesure avant correctif : **statut 500**, et aucun message. Or
        // l'arbitrage D1 exige « aucune erreur 500 » — il le disait de la
        // suppression d'Organization, mais c'est le meme defaut, cree par la
        // meme migration, sur un ecran d'administration voisin. Le refermer
        // d'un cote et le laisser de l'autre n'aurait eu aucun sens.
        //
        // Deux collisions possibles, et les deux sont couvertes : un orphelin
        // contre un MEMBRE de la cible, et deux orphelins entre eux — qui
        // atterriraient tous deux dans la meme Organization.
        if ($definition->table === 'users') {
            $conflits = $this->adressesEnConflitAvecLaCible($organization);

            if ($conflits->isNotEmpty()) {
                return back()->with('error', sprintf(
                    'Rattachement refuse : %d adresse(s) existeraient en double dans « %s » (%s). '
                    .'Une adresse est unique par organisation. Traitez ces comptes, puis recommencez.',
                    $conflits->count(),
                    $organization->name,
                    $conflits->take(3)->implode(', ')
                ));
            }
        }

        $updated = DB::transaction(
            // `whereNull` : on ne touche QUE les lignes sans Organization.
            // L'ancien UPDATE n'avait pas cette condition et reecrivait tout
            // le dataset, y compris les lignes deja rattachees ailleurs.
            // Seule `organization_id` est ecrite — aucune autre colonne.
            fn () => $definition->query()
                ->whereNull('organization_id')
                ->update(['organization_id' => $organization->getKey()])
        );

        Log::info('assign-data executed', [
            'admin_id' => $request->user()?->id,
            'dataset' => $definition->key,
            'table' => $definition->table,
            'organization_id' => $organization->getKey(),
            'affected_rows' => $updated,
        ]);

        return redirect()
            ->route('admin.outils.assign-data')
            ->with('success', __('admin.assign_data.assigned', [
                'count' => $updated,
                'dataset' => __($definition->labelKey()),
                'organization' => $organization->name,
            ]));
    }

    private function refuse(?Dataset $definition): RedirectResponse
    {
        return redirect()
            ->route('admin.outils.assign-data')
            ->with('error', $definition === null
                ? __('admin.assign_data.unknown_dataset')
                : __('admin.assign_data.not_assignable', [
                    'dataset' => __($definition->labelKey()),
                    'classification' => __($definition->classification->labelKey()),
                ]));
    }

    private function filtered(Dataset $dataset, string $filter): Builder
    {
        $query = $dataset->query();

        return match ($filter) {
            'with_organization' => $query->whereNotNull('organization_id'),
            'without_organization' => $query->whereNull('organization_id'),
            default => $query,
        };
    }

    /**
     * Le nom des Organizations citees par la page, en UNE requete.
     *
     * Sans cela la vue resoudrait un nom par ligne — cinquante requetes pour
     * une page de cinquante lignes.
     *
     * @return array<string, string>
     */
    private function organizationNames(Collection $rows): array
    {
        $ids = $rows->pluck('organization_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Organization::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * Les adresses qui seraient en double si l'on rattachait tous les
     * orphelins a cette Organization.
     *
     * Deux sources de collision, comptees ensemble parce qu'elles produisent la
     * meme violation : un orphelin dont l'adresse est deja portee par un membre
     * de la cible, et deux orphelins qui partagent la leur. La comparaison est
     * en `LOWER()`, comme les index de TASK-1729 — la garde et la base doivent
     * repondre a la meme question.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function adressesEnConflitAvecLaCible(Organization $organization)
    {
        $orphelines = DB::table('users')
            ->selectRaw('LOWER(email) as adresse')
            ->whereNull('organization_id')
            ->pluck('adresse');

        if ($orphelines->isEmpty()) {
            return collect();
        }

        $dejaPresentes = DB::table('users')
            ->selectRaw('LOWER(email) as adresse')
            ->where('organization_id', $organization->getKey())
            ->pluck('adresse');

        $entreOrphelins = $orphelines->duplicates()->values();

        return $orphelines
            ->intersect($dejaPresentes)
            ->merge($entreOrphelins)
            ->unique()
            ->values();
    }
}
