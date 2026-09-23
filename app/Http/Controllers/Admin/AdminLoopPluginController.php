<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoopPluginAiModel;
use App\Models\Organization;
use App\Services\Ai\LoopPluginAiModels;
use App\Services\Ai\OpenRouterModelCatalog;
use App\Services\Loops\LoopPluginAvailabilityService;
use App\Support\Loops\LoopPluginRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ou chaque plugin de Boucle est disponible — super-admin uniquement.
 *
 * TASK-1614 / SLICE A. L'ecran repond a une seule question, pour chaque
 * couple :
 *
 *     « ce plugin est-il autorise dans cette Organization ? »
 *
 * Il ne l'active dans aucune Boucle, ne configure aucun assistant et ne
 * declenche aucun appel IA : ces trois choses appartiennent aux SLICES
 * suivantes. Ce que le SuperAdmin donne ici, c'est le droit pour une
 * Organization de voir arriver la suite.
 *
 * **C'est une surface PLATEFORME, pas une porte vers un tenant.** Elle liste
 * les Organizations par `id`, `name` et `slug` — de quoi les nommer et les
 * designer — et rien d'autre : aucune Boucle, aucun membre, aucun document.
 * Une route `/admin` globale qui se mettrait a servir des donnees metier
 * d'une Organization deviendrait un contournement du tenant, pas un ecran
 * d'administration.
 *
 * `is_admin` est verifie ici EN PLUS du groupe `['auth','admin']` : c'est la
 * meme double garde que `AdminLoopTypeController`, parce qu'un administrateur
 * d'Organization atteint ce layout sans etre SuperAdmin.
 */
class AdminLoopPluginController extends Controller
{
    public function __construct(
        private LoopPluginRegistry $plugins,
        private LoopPluginAvailabilityService $availability,
        private LoopPluginAiModels $models,
        private OpenRouterModelCatalog $catalogue,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->is_admin, 403);

        // Peu d'Organizations, et l'ecran est SuperAdmin : la liste entiere est
        // legitime ici. Trois colonnes, pas la ligne entiere — un ecran qui
        // n'affiche qu'un nom n'a pas besoin de charger le reste.
        $organizations = Organization::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $rows = [];

        foreach ($this->plugins->all() as $key => $definition) {
            // Une requete par plugin, pas une par Organization : l'ecran doit
            // rester plat quand le parc grandit.
            $etats = $this->availability->mapForPlugin($key, $organizations);
            $decisions = $this->availability->decisionsForPlugin($key);

            $rows[$key] = [
                'key' => $key,
                'label' => $this->plugins->label($key),
                'description' => $this->plugins->description($key),
                'status' => $this->plugins->status($key),
                'experimental' => $this->plugins->isExperimental($key),
                'availability' => $etats,
                'decisions' => $decisions,
                // Ce que le SuperAdmin veut lire d'un coup d'oeil : combien
                // d'Organizations l'ont, sur combien.
                'enabled_count' => count(array_filter($etats)),
            ];
        }

        // TASK-1617 — la configuration IA PLATEFORME. Le catalogue est lu
        // depuis le cache : ouvrir cet ecran ne doit pas appeler OpenRouter a
        // chaque affichage. Le geste explicite « Actualiser » existe pour ca.
        $releve = $this->catalogue->catalogue();

        return view('admin.loop-plugins.index', [
            'plugins' => $rows,
            'organizations' => $organizations,
            'assistantModels' => $this->models->describe(),
            'freeModels' => $this->catalogue->verifiedFreeModels(),
            // TASK-1622 — la shortlist payante, avec le tarif statique de
            // chaque slug. Un slug de shortlist SANS tarif est projete avec
            // `rate = null` : l'ecran le montre desactive plutot que de le
            // cacher — un SuperAdmin doit voir qu'une entree attend son
            // releve, pas croire que la shortlist a retreci.
            'paidModels' => collect($this->models->paidShortlist())
                ->map(fn (string $label, string $slug): array => [
                    'slug' => $slug,
                    'label' => $label,
                    'rate' => $this->models->paidRateFor($slug),
                ])->all(),
            'catalogState' => [
                'ok' => $releve['ok'],
                'fetched_at' => $releve['fetched_at'],
                'error' => $releve['error'],
            ],
        ]);
    }

    /**
     * Affecter un modele a un assistant — gratuit verifie OU payant approuve
     * (TASK-1622).
     *
     * Le service refuse tout slug hors contrat au moment du geste — non
     * verifie gratuit pour le type `free_verified`, hors shortlist ou sans
     * tarif statique pour `paid_approved` : l'ecran ne propose que des
     * modeles eligibles, mais un POST forge ne doit pas pouvoir en
     * enregistrer un autre. Le refus est un message, pas une exception qui
     * fuit. Le type par DEFAUT est le gratuit : un formulaire d'avant
     * TASK-1622 qui ne poste pas `model_type` garde exactement son sens.
     */
    public function updateModel(Request $request, string $plugin): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless($this->plugins->exists($plugin), 404);

        $data = $request->validate([
            'assistant_key' => 'required|string',
            'model_slug' => 'required|string|max:200',
            'model_type' => 'sometimes|string|in:'.LoopPluginAiModel::TYPE_FREE_VERIFIED.','.LoopPluginAiModel::TYPE_PAID_APPROVED,
        ]);

        $paye = ($data['model_type'] ?? LoopPluginAiModel::TYPE_FREE_VERIFIED)
            === LoopPluginAiModel::TYPE_PAID_APPROVED;

        try {
            $paye
                ? $this->models->assignPaid($data['assistant_key'], $data['model_slug'], $request->user())
                : $this->models->assign($data['assistant_key'], $data['model_slug'], $request->user());
        } catch (\InvalidArgumentException) {
            return back()->with('error', __($paye ? 'loops.plugins_models_paid_rejected' : 'loops.plugins_models_rejected', [
                'model' => $data['model_slug'],
            ]));
        }

        return back()->with('success', __('loops.plugins_models_assigned', [
            'assistant' => $data['assistant_key'],
            'model' => $data['model_slug'],
        ]));
    }

    /**
     * Relever le catalogue OpenRouter, maintenant.
     *
     * Le seul endroit de cette TASK qui sort sur le reseau, et il le fait sur
     * un GESTE. Un echec ne vide rien et ne modifie aucune affectation : il le
     * dit.
     */
    public function refreshModels(Request $request, string $plugin): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless($this->plugins->exists($plugin), 404);

        $releve = $this->catalogue->catalogue(forceRefresh: true);

        if (! $releve['ok']) {
            // Catalogue en erreur : AUCUNE preuve n'est renouvelee. Un releve
            // rate ne prouve rien, et fabriquer une fraicheur ici ferait
            // exactement ce que la garde de TASK-1617 interdit.
            return back()->with('error', __('loops.plugins_models_refresh_failed', [
                'reason' => (string) $releve['error'],
            ]));
        }

        $libres = $this->catalogue->verifiedFreeModels();

        // TASK-1621 — le geste tient enfin sa promesse.
        //
        // La campagne humaine a trouve le defaut : l'ecran affichait « Preuve
        // expiree » sur les trois assistants, l'admin cliquait le seul bouton
        // disponible, obtenait « Catalogue actualise » — et rien ne changeait.
        // `refreshModels()` rafraichissait le CACHE du catalogue sans jamais
        // toucher aux lignes d'affectation. Un bandeau de succes qui ne
        // resout pas le probleme affiche juste a cote est pire qu'aucun bouton.
        //
        // La regle de renouvellement reste STRICTE, et c'est le point : une
        // preuve n'est reconduite que si le releve qui vient d'avoir lieu
        // montre le slug ENCORE present et ENCORE verifie gratuit. Un modele
        // disparu ou redevenu payant garde sa preuve perimee et reste
        // inelligible. Fail closed, inchange.
        $renouvelees = 0;

        foreach ($this->models->describe() as $assistant) {
            $slug = $assistant['model_slug'];

            if ($slug === null || ! array_key_exists($slug, $libres)) {
                continue;
            }

            $ligne = $this->models->lineFor((string) $assistant['assistant_key']);

            if ($ligne === null) {
                continue;
            }

            $this->models->renewProof($ligne);
            $renouvelees++;
        }

        return back()->with('success', __('loops.plugins_models_refreshed', [
            'count' => count($libres),
            'renewed' => $renouvelees,
        ]));
    }

    /**
     * Allumer ou eteindre un plugin pour UNE Organization.
     *
     * Les deux identifiants arrivent d'un formulaire, et aucun des deux n'est
     * recu tel quel :
     *
     * - le plugin est confronte au CATALOGUE — une cle forgee ecrirait sinon
     *   des lignes pour un plugin qui n'existe pas ;
     * - l'Organization est confrontee a la TABLE, et sa FORME est verifiee
     *   avant. `organizations.id` est une colonne `uuid` : PostgreSQL refuse
     *   la comparaison avec une chaine qui n'en est pas une et leve `22P02`,
     *   soit une 500 la ou il faut une 404. SQLite, lui, compare comme du
     *   texte et ne trouve rien — le defaut ne se verrait donc qu'en
     *   production.
     *
     * L'ecriture ne porte QUE l'Organization designee : rien dans ce chemin ne
     * touche une deuxieme ligne, et il n'existe aucun geste « pour toutes les
     * Organizations ».
     */
    public function update(Request $request, string $plugin): RedirectResponse
    {
        abort_unless($request->user()?->is_admin, 403);
        abort_unless($this->plugins->exists($plugin), 404);

        $data = $request->validate([
            'organization_id' => 'required|string',
            'available' => 'required|boolean',
        ]);

        abort_unless(Str::isUuid($data['organization_id']), 404);

        $organization = Organization::query()->findOrFail($data['organization_id']);

        $this->availability->setAvailability(
            pluginKey: $plugin,
            organization: $organization,
            available: (bool) $data['available'],
            actor: $request->user(),
        );

        return redirect()
            ->route('admin.loop-plugins')
            ->with('success', __($data['available']
                ? 'loops.plugins_admin_enabled'
                : 'loops.plugins_admin_disabled', [
                    'plugin' => $this->plugins->label($plugin),
                    'organization' => $organization->name,
                ]));
    }
}
