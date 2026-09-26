<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScenarioManifestVersion;
use App\Support\ScenarioManager\ScenarioPreview;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1646 puis TASK-1648 — `/admin/outils/scenarios`.
 *
 * ## Deux routes, toutes deux en GET, et c'est le contrat
 *
 * T1648 livre la LECTURE : une bibliotheque et un Preview. Elle ne cree, ne
 * modifie ni ne supprime rien du domaine. Le CRUD, le Validate, le Load et la
 * Capture arrivent en T1649 et au-dela.
 *
 * Un test verifie qu'aucune route `admin.outils.scenarios*` n'accepte autre
 * chose qu'un GET, et un autre qu'ouvrir ces ecrans ne deplace aucune ligne
 * de `scenario_manifest_versions` ni de `scenario_pack_loads`, ni le nombre de
 * lignes d'aucune autre table.
 *
 * Ce qu'aucun test ne peut promettre ici : que le SERVEUR n'ecrive rien du
 * tout. En production `SESSION_DRIVER=database`, donc chaque GET touche une
 * ligne de session — le harnais de test, lui, force le pilote `array` et ne
 * peut pas le voir. « Lecture seule » porte sur le DOMAINE, pas sur
 * l'infrastructure.
 *
 * ## Le Validator ne tourne JAMAIS au rendu
 *
 * Le CDC 11.1 l'interdit : la bibliotheque lit les colonnes administratives
 * et `validation_summary`, calcule au save. Les compteurs affiches sur une
 * carte viennent donc de cette colonne, et pas d'une validation refaite par
 * ligne — ce qui, sur une bibliotheque de cent scenarios, serait cent
 * validations pour afficher une page.
 *
 * Le Preview d'UNE version parse son document une fois, parce que les
 * onglets ont besoin des objets eux-memes. Ce n'est pas ce que le CDC
 * interdit.
 *
 * Le droit vient du middleware `admin` (`users.is_admin`) et de lui seul :
 * le Scenario Manager est une surface SuperAdmin (CDC 4.1) qui ne depend
 * d'aucune Organization.
 */
class AdminScenarioManagerController extends Controller
{
    private const PER_PAGE = 24;

    /**
     * Au-dela, un onglet TRONQUE, en annoncant le total.
     *
     * Il ne pagine pas : l'onglet est une lecture metier synthetique, et
     * l'onglet « JSON brut » est la representation exhaustive. Une pagination
     * par famille appartiendrait a l'editeur, donc a T1649.
     */
    private const TAB_LIMIT = 200;

    /**
     * Les colonnes que la bibliotheque affiche VRAIMENT.
     *
     * `json_source` en est exclu, et c'est tout l'objet de cette liste : le
     * modele autorise 2 MiB par document, donc un `select *` sur une page de
     * 24 cartes hydraterait jusqu'a 48 Mio de chaines que la page ne rend
     * jamais.
     *
     * Choisir des colonnes est un geste a risque — un etat CALCULE se met a
     * mentir des qu'une des colonnes dont il depend manque. `state` et
     * `scenario_pack_load_id` sont donc presents parce que `isLoaded()` et
     * `isValid()` les lisent, et `id` parce que la carte en fait un lien.
     *
     * @var list<string>
     */
    private const COLONNES_DE_LISTE = [
        'id',
        'scenario_key',
        'name',
        'version',
        'usage',
        'state',
        'scenario_pack_load_id',
        'validation_summary',
        'updated_at',
    ];

    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));
        $etat = (string) $request->query('etat', '');
        $usage = (string) $request->query('usage', '');
        $charge = (string) $request->query('charge', '');

        // Ce qui a ete RETENU, pas ce qui a ete envoye : `?etat=nimporte-quoi`
        // ne filtre rien, et l'ecran ne doit pas pretendre le contraire en
        // affichant « Tout afficher ».
        $etatRetenu = in_array($etat, ScenarioManifestVersion::STATES, true) ? $etat : '';
        $usageRetenu = in_array($usage, ScenarioManifestVersion::USAGES, true) ? $usage : '';
        $chargeRetenu = in_array($charge, ['oui', 'non'], true) ? $charge : '';

        $query = ScenarioManifestVersion::query()
            ->select(self::COLONNES_DE_LISTE)
            ->with('scenarioPackLoad.organization');

        if ($recherche !== '') {
            // Recherche sur ce qu'une personne retient : le nom affiche et la
            // cle de scenario. Pas sur `json_source` — chercher dans le
            // document entier ferait une analyse de texte sur plusieurs Mio
            // par ligne, pour un resultat que personne n'attend ici.
            $motif = '%'.self::echapperLike($recherche).'%';

            // `ESCAPE` est EXPLICITE : PostgreSQL traite le backslash comme
            // echappement par defaut dans un LIKE, SQLite n'en traite AUCUN.
            // Sans cette clause, la neutralisation des jokers fonctionnerait en
            // production et pas dans la suite de tests — ou l'inverse, selon
            // le moteur qu'on regarde.
            $colonnes = array_map(
                static fn (string $colonne): string => $query->getQuery()->getGrammar()->wrap($colonne),
                ['name', 'scenario_key']
            );

            $query->where(function ($q) use ($motif, $colonnes): void {
                $q->whereRaw($colonnes[0].' LIKE ? ESCAPE ?', [$motif, '\\'])
                    ->orWhereRaw($colonnes[1].' LIKE ? ESCAPE ?', [$motif, '\\']);
            });
        }

        if ($etatRetenu !== '') {
            $query->where('state', $etatRetenu);
        }

        if ($usageRetenu !== '') {
            $query->where('usage', $usageRetenu);
        }

        // `loaded` est DERIVE : le filtre porte le MEME predicat que
        // `isLoaded()`, sans quoi la bibliotheque dirait une chose et la carte
        // une autre.
        if ($chargeRetenu === 'oui') {
            $query->loaded();
        } elseif ($chargeRetenu === 'non') {
            $query->where(function ($q): void {
                $q->whereNull('scenario_pack_load_id')
                    ->orWhere('state', '!=', ScenarioManifestVersion::STATE_VALID);
            });
        }

        $versions = $query
            ->orderByDesc('updated_at')
            ->orderBy('scenario_key')
            // `updated_at` a une precision d'une SECONDE : sans cle unique en
            // dernier critere, deux pages successives pourraient montrer deux
            // fois la meme ligne et en oublier une autre.
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.outils.scenarios', [
            'versions' => $versions,
            'filtres' => [
                'q' => $recherche,
                'etat' => $etatRetenu,
                'usage' => $usageRetenu,
                'charge' => $chargeRetenu,
            ],
            'actif' => $recherche !== '' || $etatRetenu !== '' || $usageRetenu !== '' || $chargeRetenu !== '',
            'totals' => [
                'all' => ScenarioManifestVersion::query()->count(),
                'draft' => ScenarioManifestVersion::query()->draft()->count(),
                'valid' => ScenarioManifestVersion::query()->valid()->count(),
                'loaded' => ScenarioManifestVersion::query()->loaded()->count(),
                'scenarios' => ScenarioManifestVersion::query()->distinct()->count('scenario_key'),
            ],
        ]);
    }

    /**
     * Le Preview : comprendre un scenario AVANT qu'une Organization n'existe
     * (CDC 11.1).
     *
     * `json_source` est lu par {@see ScenarioPreview}, jamais par
     * `ScenarioManifest::fromApprovedJson()` : cette derniere est la porte du
     * Load, elle exige un digest approuve et refuse un document invalide. Un
     * brouillon invalide doit rester consultable — c'est meme a cela que sert
     * le Preview.
     *
     * Aucune relation n'est prechargee : cet ecran lit le DOCUMENT et les
     * colonnes administratives, et n'affiche ni auteur, ni approbateur, ni
     * parent, ni sandbox. Precharger ce qu'on ne rend pas, c'est payer des
     * requetes pour rien.
     */
    public function show(Request $request, ScenarioManifestVersion $version): View
    {
        $preview = ScenarioPreview::fromJson($version->json_source);

        $onglets = self::onglets();
        $onglet = (string) $request->query('onglet', 'resume');

        if (! array_key_exists($onglet, $onglets)) {
            $onglet = 'resume';
        }

        return view('admin.outils.scenario-detail', [
            'version' => $version,
            'preview' => $preview,
            'onglet' => $onglet,
            'onglets' => $onglets,
            'familles' => $onglets[$onglet]['familles'],
            'limite' => self::TAB_LIMIT,
            // Les compteurs viennent de la colonne, jamais d'un comptage du
            // document : c'est la regle du CDC 11.1.
            'compteurs' => is_array($version->validation_summary['counters'] ?? null)
                ? $version->validation_summary['counters']
                : [],
            'verdict' => $version->validation_summary['verdict'] ?? null,
            'erreurs' => is_array($version->validation_summary['errors'] ?? null)
                ? $version->validation_summary['errors']
                : [],
        ]);
    }

    /**
     * Les neuf onglets du CDC 11.3, dans l'ordre qu'il fixe, et les familles
     * que chacun montre.
     *
     * C'est l'AUTORITE UNIQUE : la vue ne redeclare pas cette carte. Trois
     * copies d'une meme liste divergent a la premiere correction, et un onglet
     * declare ici mais oublie ailleurs rendrait une page entierement vide sans
     * que rien ne proteste.
     *
     * Les cles de famille sont celles de `ManifestSchema` — `training.modules`
     * autant que `users` — ce qui permet a un test de verifier, contre le
     * schema lui-meme, qu'aucune famille n'est laissee de cote.
     *
     * Trois a cinq champs par famille (arbitrage produit) : l'onglet est une
     * lecture metier synthetique, l'onglet « JSON brut » porte l'exhaustivite.
     *
     * @return array<string, array{libelle: string, familles: array<string, list<string>>}>
     */
    public static function onglets(): array
    {
        return [
            'resume' => ['libelle' => 'tab_resume', 'familles' => []],
            'personnes' => ['libelle' => 'tab_personnes', 'familles' => [
                'users' => ['key', 'first_name', 'name', 'organization_role', 'avatar'],
            ]],
            'boucles' => ['libelle' => 'tab_boucles', 'familles' => [
                'loops' => ['key', 'name', 'type', 'visibility'],
                // `memberships` n'a pas de `key` : son identite est COMPOSEE
                // (spec 8.1), et afficher une colonne vide ferait croire a une
                // donnee manquante.
                'memberships' => ['loop', 'user', 'role'],
            ]],
            'dossiers' => ['libelle' => 'tab_dossiers', 'familles' => [
                'dossiers' => ['key', 'loop', 'name', 'visibility'],
                'articles' => ['key', 'dossier', 'author', 'title'],
                'files' => ['key', 'dossier', 'name', 'media_type'],
            ]],
            'chatloop' => ['libelle' => 'tab_chatloop', 'familles' => [
                'messages' => ['key', 'loop', 'author', 'order'],
            ]],
            'entraide' => ['libelle' => 'tab_entraide', 'familles' => [
                'categories' => ['key', 'name'],
                'skills' => ['key', 'category', 'name'],
                'service_requests' => ['key', 'author', 'title'],
                'services' => ['key', 'author', 'title'],
            ]],
            'collaboration' => ['libelle' => 'tab_collaboration', 'familles' => [
                'polls' => ['key', 'loop', 'question', 'status'],
                'events' => ['key', 'loop', 'title', 'status'],
                'decisions' => ['key', 'loop', 'title', 'message'],
                'roadmap_items' => ['key', 'loop', 'title', 'status'],
            ]],
            'formation' => ['libelle' => 'tab_formation', 'familles' => [
                'training.modules' => ['key', 'loop', 'title'],
                'training.sequences' => ['key', 'module', 'title'],
                'training.assignments' => ['key', 'sequence', 'title'],
                // Keyless, comme `memberships`.
                'training.submissions' => ['assignment', 'user', 'status'],
                'training.progress' => ['sequence', 'user', 'status'],
            ]],
            'json' => ['libelle' => 'tab_json', 'familles' => []],
        ];
    }

    /**
     * Neutralise les jokers d'un `LIKE`.
     *
     * Sans cela `?q=%` ramene TOUTE la bibliotheque tout en affichant « filtre
     * actif », et un scenario nomme `100_%_couverture` devient introuvable en
     * collant son propre nom dans la recherche.
     */
    private static function echapperLike(string $terme): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $terme);
    }
}
