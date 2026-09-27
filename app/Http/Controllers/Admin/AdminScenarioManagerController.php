<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScenarioManifestVersion;
use App\Support\ScenarioManifest\ManifestSchema;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManager\ScenarioPreview;
use App\Support\ScenarioManager\ScenarioVersionRefused;
use App\Support\ScenarioManager\ScenarioVersionWriter;
use App\Support\ScenarioManager\ScenarioVisualEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * TASK-1646 puis TASK-1648 — `/admin/outils/scenarios`.
 *
 * ## Ce qui LIT et ce qui ECRIT
 *
 * T1648 avait livre la lecture seule. T1649 lui donne la main : creer,
 * importer, coller, dupliquer, editer le JSON, valider techniquement,
 * exporter, supprimer.
 *
 * La bibliotheque et le Preview RESTENT en GET, et un test le prouve encore —
 * il ne dit plus « aucune route ne mute » mais « ces deux routes-la ne mutent
 * pas, et l'ensemble des routes mutantes est exactement celui qu'on a
 * declare ». Une garantie qui devient fausse se reecrit, elle ne se supprime
 * pas.
 *
 * **Aucune donnee metier n'est creee ici.** Le CRUD ecrit des lignes
 * administratives, et rien d'autre : ni Organization, ni User, ni Loop. Le
 * monde d'un scenario ne naitra qu'au Load, en T1650. Un test compare le
 * nombre de lignes des 68 tables avant et apres chaque geste.
 *
 * Toutes les ecritures passent par {@see ScenarioVersionWriter} : c'est ce qui
 * garde les huit attributs systeme hors de portee d'une requete. Le controleur
 * n'en touche aucun.
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
     * Une seule relation est prechargee, et seulement par CET ecran quand la
     * version est chargee : la sandbox, parce que le CDC 13.5 demande
     * d'afficher l'Organization creee, son slug REEL, sa date et son digest.
     * Precharger ce qu'on ne rend pas reste payer des requetes pour rien.
     */
    public function show(Request $request, ScenarioManifestVersion $version): View
    {
        $preview = ScenarioPreview::fromJson($version->json_source);

        $onglets = self::onglets();
        $onglet = (string) $request->query('onglet', 'resume');

        if (! array_key_exists($onglet, $onglets)) {
            $onglet = 'resume';
        }

        if ($version->isLoaded()) {
            $version->load('scenarioPackLoad.organization');
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
     * Le formulaire de creation (CDC 9.1 a 9.3) : scenario vide, import de
     * fichier, ou collage de texte. Les trois aboutissent a un DRAFT.
     */
    public function create(): View
    {
        return view('admin.outils.scenario-nouveau');
    }

    public function store(Request $request, ScenarioVersionWriter $writer): RedirectResponse
    {
        $donnees = $request->validate([
            'scenario_key' => self::reglesDeCle(),
            'name' => ['required', 'string', 'max:120'],
            'locale' => ['required', 'in:fr,en'],
            'usage' => ['required', Rule::in(ScenarioManifestVersion::USAGES)],
            'mode' => ['required', 'in:vide,coller,fichier'],
            // Exiger le texte SELON le mode : sans cela, « coller » sans rien
            // coller creerait un document vide en se taisant.
            'json' => ['nullable', 'string', 'required_if:mode,coller'],
            'fichier' => ['nullable', 'file', 'max:2048', 'required_if:mode,fichier'],
        ]);

        return $this->enRepondantAuxRefus(function () use ($donnees, $request, $writer) {
            $auteur = $request->user();

            $version = match ($donnees['mode']) {
                'vide' => $writer->createBlank($donnees['scenario_key'], $donnees['name'], $donnees['locale'], $auteur, $donnees['usage']),
                // Import et Paste ont le MEME contrat (CDC 9.3) : seule la
                // provenance du texte change, pas ce qu'on en fait.
                'fichier' => $writer->import(
                    $donnees['scenario_key'],
                    $donnees['name'],
                    (string) file_get_contents($request->file('fichier')->getRealPath()),
                    $auteur,
                    $donnees['usage']
                ),
                'coller' => $writer->import($donnees['scenario_key'], $donnees['name'], (string) ($donnees['json'] ?? ''), $auteur, $donnees['usage']),
            };

            return redirect()
                ->route('admin.outils.scenarios.edit', $version)
                ->with('status', __('admin.scenario_manager.flash_created'));
        });
    }

    public function duplicate(Request $request, ScenarioManifestVersion $version, ScenarioVersionWriter $writer): RedirectResponse
    {
        $donnees = $request->validate([
            'scenario_key' => self::reglesDeCle(),
            'name' => ['required', 'string', 'max:120'],
        ]);

        return $this->enRepondantAuxRefus(function () use ($donnees, $request, $version, $writer) {
            $copie = $writer->duplicate($version, $donnees['scenario_key'], $donnees['name'], $request->user());

            return redirect()
                ->route('admin.outils.scenarios.edit', $copie)
                ->with('status', __('admin.scenario_manager.flash_duplicated'));
        });
    }

    /**
     * Le mode JSON du CDC 10.10 : edition texte, validation, erreurs
     * localisees, formatage, copie, export.
     *
     * Une version CHARGEE s'ouvre quand meme — en lecture. L'ecran dit
     * pourquoi elle ne se modifie pas et vers quoi se tourner (CDC 8.6) : un
     * champ grise sans explication laisserait croire a une panne.
     */
    public function edit(ScenarioManifestVersion $version): View
    {
        return view('admin.outils.scenario-editeur', [
            'version' => $version,
            'modifiable' => ! $version->isLoaded(),
            'erreurs' => is_array($version->validation_summary['errors'] ?? null)
                ? $version->validation_summary['errors']
                : [],
            'verdict' => $version->validation_summary['verdict'] ?? null,
            'limite' => self::TAB_LIMIT,
        ]);
    }

    public function update(Request $request, ScenarioManifestVersion $version, ScenarioVersionWriter $writer): RedirectResponse
    {
        // `json_source` est conserve MEME invalide (CDC 9.2) : la seule borne
        // est la taille. Valider la syntaxe ici reviendrait a refuser
        // d'enregistrer un brouillon en cours de correction.
        $donnees = $request->validate(['json' => ['required', 'string']]);

        return $this->enRepondantAuxRefus(function () use ($donnees, $version, $writer) {
            $writer->updateDocument($version, $donnees['json']);

            return redirect()
                ->route('admin.outils.scenarios.edit', $version)
                ->with('status', __('admin.scenario_manager.flash_saved'));
        });
    }

    /**
     * L'etape TECHNIQUE du CDC 12.1. Elle n'approuve rien : l'approbation
     * humaine, seule porte vers un Load, arrive en T1650.
     */
    public function validateDocument(ScenarioManifestVersion $version, ScenarioVersionWriter $writer): RedirectResponse
    {
        return $this->enRepondantAuxRefus(function () use ($version, $writer) {
            $writer->validate($version);

            return redirect()
                ->route('admin.outils.scenarios.edit', $version)
                ->with('status', $version->isValid()
                    ? __('admin.scenario_manager.flash_valid')
                    : __('admin.scenario_manager.flash_invalid'));
        });
    }

    /**
     * Export (CDC 10.10) : le document TEL QU'IL EST ENREGISTRE, sans
     * reformatage ni recalcul. Exporter un texte different de celui qu'on
     * edite ferait deux verites.
     */
    public function export(ScenarioManifestVersion $version): Response
    {
        return response($version->json_source, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'
                .$version->scenario_key.'-'.$version->version.'.json"',
        ]);
    }

    public function destroy(ScenarioManifestVersion $version, ScenarioVersionWriter $writer): RedirectResponse
    {
        return $this->enRepondantAuxRefus(function () use ($version, $writer) {
            $writer->delete($version);

            return redirect()
                ->route('admin.outils.scenarios')
                ->with('status', __('admin.scenario_manager.flash_deleted'));
        });
    }

    /**
     * Un refus metier revient a l'ecran comme une PHRASE.
     *
     * Sans ce filtre, refuser de modifier une version chargee produirait une
     * 500 : l'utilisateur verrait une panne la ou le produit a simplement dit
     * non, et pour une raison qu'il peut comprendre et contourner.
     */
    // =====================================================================
    // TASK-1651 — l'editeur visuel borne
    // =====================================================================

    /**
     * L'editeur visuel : General, Personnes, Boucles, Membres, JSON avance.
     *
     * Le mode visuel se DESACTIVE quand le document n'est pas lisible, au lieu
     * d'en inventer une representation : un formulaire construit sur un
     * document casse ferait perdre le texte a la premiere sauvegarde.
     */
    public function visual(ScenarioManifestVersion $version): View
    {
        try {
            $document = ScenarioVisualEditor::pour((string) $version->json_source)->document();
            $lisible = true;
        } catch (ScenarioVersionRefused) {
            $document = [];
            $lisible = false;
        }

        return view('admin.outils.scenario-visuel', [
            'version' => $version,
            'document' => $document,
            'lisible' => $lisible,
            'modifiable' => ! $version->isLoaded(),
            'personnes' => $document['users'] ?? [],
            'boucles' => $document['loops'] ?? [],
            'memberships' => $document['memberships'] ?? [],
            'erreurs' => is_array($version->validation_summary['errors'] ?? null)
                ? $version->validation_summary['errors']
                : [],
            'verdict' => $version->validation_summary['verdict'] ?? null,
        ]);
    }

    public function updateGeneral(Request $request, ScenarioManifestVersion $version, ScenarioVersionWriter $writer): RedirectResponse
    {
        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'version' => ['required', 'string', 'max:32'],
            'description' => ['required', 'string', 'max:2000'],
            'purpose' => ['required', 'string', 'max:500'],
            'locale' => ['required', 'in:fr,en'],
            'organization_name' => ['required', 'string', 'max:120'],
            'organization_proposed_slug' => ['required', 'string', 'max:64'],
            'organization_description' => ['required', 'string', 'max:2000'],
        ]);

        return $this->enModifiantLeDocument($version, $writer, static fn (ScenarioVisualEditor $editeur) => $editeur->majGeneral($donnees), 'general');
    }

    public function storePerson(Request $request, ScenarioManifestVersion $version, ScenarioVersionWriter $writer): RedirectResponse
    {
        $donnees = $this->reglesDePersonne($request);

        return $this->enModifiantLeDocument($version, $writer, static fn (ScenarioVisualEditor $editeur) => $editeur->ajouterPersonne($donnees), 'personnes');
    }

    public function updatePerson(Request $request, ScenarioManifestVersion $version, string $cle, ScenarioVersionWriter $writer): RedirectResponse
    {
        $donnees = $this->reglesDePersonne($request);

        return $this->enModifiantLeDocument($version, $writer, static fn (ScenarioVisualEditor $editeur) => $editeur->modifierPersonne($cle, $donnees), 'personnes');
    }

    public function destroyPerson(ScenarioManifestVersion $version, string $cle, ScenarioVersionWriter $writer): RedirectResponse
    {
        return $this->enModifiantLeDocument($version, $writer, static fn (ScenarioVisualEditor $editeur) => $editeur->supprimerPersonne($cle), 'personnes');
    }

    public function storeLoop(Request $request, ScenarioManifestVersion $version, ScenarioVersionWriter $writer): RedirectResponse
    {
        $donnees = $this->reglesDeBoucle($request);

        return $this->enModifiantLeDocument($version, $writer, static fn (ScenarioVisualEditor $editeur) => $editeur->ajouterBoucle($donnees), 'boucles');
    }

    public function updateLoop(Request $request, ScenarioManifestVersion $version, string $cle, ScenarioVersionWriter $writer): RedirectResponse
    {
        $donnees = $this->reglesDeBoucle($request);

        return $this->enModifiantLeDocument($version, $writer, static fn (ScenarioVisualEditor $editeur) => $editeur->modifierBoucle($cle, $donnees), 'boucles');
    }

    public function destroyLoop(ScenarioManifestVersion $version, string $cle, ScenarioVersionWriter $writer): RedirectResponse
    {
        return $this->enModifiantLeDocument($version, $writer, static fn (ScenarioVisualEditor $editeur) => $editeur->supprimerBoucle($cle), 'boucles');
    }

    public function updateMembership(Request $request, ScenarioManifestVersion $version, ScenarioVersionWriter $writer): RedirectResponse
    {
        $donnees = $request->validate([
            'loop' => ['required', 'string', 'max:64'],
            'user' => ['required', 'string', 'max:64'],
            'role' => ['nullable', 'in:owner,facilitator,member'],
        ]);

        $role = $donnees['role'] ?? null;

        return $this->enModifiantLeDocument(
            $version,
            $writer,
            static fn (ScenarioVisualEditor $editeur) => $editeur->definirRole($donnees['loop'], $donnees['user'], $role === '' ? null : $role),
            'membres'
        );
    }

    /**
     * LE chemin d'ecriture de l'editeur visuel, partage par les neuf gestes.
     *
     * Un seul endroit fait la sequence complete : lire le document, le muter,
     * le repasser par la porte d'ecriture unique, puis le REVALIDER
     * entierement. Neuf copies de cette sequence auraient fini par diverger —
     * et c'est precisement la divergence qui fabrique un document que personne
     * ne sait plus expliquer.
     *
     * La revalidation n'est pas cosmetique : `updateDocument()` repasse la
     * version en DRAFT et efface le resume, donc sans elle l'ecran afficherait
     * un document sans verdict apres chaque clic.
     */
    private function enModifiantLeDocument(
        ScenarioManifestVersion $version,
        ScenarioVersionWriter $writer,
        \Closure $mutation,
        string $onglet
    ): RedirectResponse {
        return $this->enRepondantAuxRefus(function () use ($version, $writer, $mutation, $onglet) {
            $editeur = ScenarioVisualEditor::pour((string) $version->json_source);

            $mutation($editeur);

            // La porte d'ecriture unique : elle refuse une version chargee,
            // repasse en DRAFT et efface l'approbation (CDC 12.3).
            $writer->updateDocument($version, $editeur->json());

            // Le MEME Validator complet que le mode JSON. Aucun mini-validator
            // parallele : l'ecran ne juge jamais un document lui-meme.
            $writer->validate($version);

            return redirect()
                ->route('admin.outils.scenarios.visual', $version)
                ->withFragment($onglet)
                ->with('status', __('admin.scenario_manager.flash_visual_saved'));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesDePersonne(Request $request): array
    {
        $donnees = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'max:160'],
            'organization_role' => ['required', 'in:admin,member'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:160'],
            'avatar' => ['nullable', 'string', 'max:120'],
        ]);

        $donnees['available'] = $request->boolean('available');

        return $donnees;
    }

    /**
     * @return array<string, mixed>
     */
    private function reglesDeBoucle(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:2000'],
            'type' => ['required', 'in:general,project,coaching,training'],
            'owner' => ['required', 'string', 'max:64'],
            'visibility' => ['required', 'in:private,public'],
            'access_mode' => ['required', 'in:open,request,invitation'],
        ]);
    }

    private function enRepondantAuxRefus(\Closure $geste): RedirectResponse
    {
        try {
            return $geste();
        } catch (ScenarioVersionRefused $refus) {
            return back()
                ->withInput()
                ->withErrors(['scenario' => __($refus->translationKey(), $refus->parametres)]);
        }
    }

    /**
     * L'ecran d'approbation (CDC 12.2).
     *
     * « VALID techniquement ne signifie pas encore autorise a Load. Le
     * SuperAdmin doit confirmer le contenu EXACT. » L'ecran montre donc ce
     * qu'il y a DANS le monde — compteurs, digest, zero erreur — et pas
     * seulement le nom du scenario. On approuve un contenu, pas un titre.
     */
    public function approval(ScenarioManifestVersion $version): View
    {
        return view('admin.outils.scenario-approbation', [
            'version' => $version,
            'compteurs' => is_array($version->validation_summary['counters'] ?? null)
                ? $version->validation_summary['counters']
                : [],
            'verdict' => $version->validation_summary['verdict'] ?? null,
            'erreurs' => is_array($version->validation_summary['errors'] ?? null)
                ? $version->validation_summary['errors']
                : [],
            'dejaApprouve' => $version->approvalMatchesCurrentDigest(),
        ]);
    }

    public function approve(Request $request, ScenarioManifestVersion $version, ScenarioLifecycleService $cycle): RedirectResponse
    {
        return $this->enRepondantAuxRefus(function () use ($request, $version, $cycle) {
            $cycle->approve($version, $request->user());

            return redirect()
                ->route('admin.outils.scenarios.approval', $version)
                ->with('status', __('admin.scenario_manager.flash_approved'));
        });
    }

    /**
     * Load (CDC 13.4 et 13.5).
     *
     * Le resultat dit s'il s'agit d'un REJEU : meme `(pack_id,
     * manifest_digest)` rend le chargement existant, et l'ecran doit alors
     * dire « deja charge », pas une erreur (CDC 13.3).
     */
    public function load(ScenarioManifestVersion $version, ScenarioLifecycleService $cycle): RedirectResponse
    {
        return $this->enRepondantAuxRefus(function () use ($version, $cycle) {
            $resultat = $cycle->load($version);

            return redirect()
                ->route('admin.outils.scenarios.show', $version)
                ->with('status', __(
                    $resultat->wasReplay
                        ? 'admin.scenario_manager.flash_already_loaded'
                        : 'admin.scenario_manager.flash_loaded',
                    ['slug' => $resultat->sandboxSlug()]
                ));
        });
    }

    public function reset(ScenarioManifestVersion $version, ScenarioLifecycleService $cycle): RedirectResponse
    {
        return $this->enRepondantAuxRefus(function () use ($version, $cycle) {
            $sandbox = $cycle->reset($version);

            return redirect()
                ->route('admin.outils.scenarios.show', $version)
                ->with('status', __('admin.scenario_manager.flash_reset', ['slug' => $sandbox->slug]));
        });
    }

    public function removeSandbox(ScenarioManifestVersion $version, ScenarioLifecycleService $cycle): RedirectResponse
    {
        return $this->enRepondantAuxRefus(function () use ($version, $cycle) {
            $cycle->remove($version);

            return redirect()
                ->route('admin.outils.scenarios.show', $version)
                ->with('status', __('admin.scenario_manager.flash_removed'));
        });
    }

    /**
     * Ce qu'une cle de scenario doit etre pour produire un document VALIDABLE.
     *
     * `min:3` et les slugs reserves ne sont pas du zele : la cle devient le
     * `proposed_slug` du document, que le Validator refuse en dessous de trois
     * caracteres ou s'il vaut `admin`, `main`, `api`... Sans ces regles, le
     * formulaire fabriquerait des scenarios qui n'atteindront JAMAIS VALID,
     * sans un mot au moment de la saisie.
     *
     * `\z` plutot que `$` : `$` accepte un saut de ligne final, et la cle est
     * interpolee dans l'en-tete `Content-Disposition` de l'export. Une garde ne
     * doit pas dependre du fait qu'un autre middleware rogne avant elle.
     *
     * @return list<mixed>
     */
    private static function reglesDeCle(): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:64',
            'regex:/\A[a-z0-9]+(-[a-z0-9]+)*\z/',
            Rule::notIn(ManifestSchema::RESERVED_SLUGS),
        ];
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
