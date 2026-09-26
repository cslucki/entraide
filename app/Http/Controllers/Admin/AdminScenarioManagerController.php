<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScenarioManifestVersion;
use Illuminate\View\View;

/**
 * TASK-1646 — `/admin/outils/scenarios`, la surface de la FONDATION.
 *
 * ## Une seule route, en GET, et c'est voulu
 *
 * T1646 livre la fondation administrative : la table de versions, le modele
 * d'etat DRAFT/VALID et la derivation de LOADED. Elle ne livre NI la
 * bibliotheque riche, NI le Preview, NI le CRUD, NI le Load, NI la Capture —
 * ce sont T1648 a T1653. Cet ecran existe pour qu'on puisse CONSTATER que la
 * fondation est la, pas pour s'en servir.
 *
 * Il n'y a donc aucune route de mutation, et un test verifie qu'aucune route
 * nommee `admin.outils.scenarios*` n'accepte autre chose qu'un GET : « cet
 * ecran ne fait rien » est une promesse qui se prouve.
 *
 * ## LOADED n'est pas lu, il est derive
 *
 * L'ecran n'affiche pas une colonne d'etat : il affiche `state` pour DRAFT et
 * VALID, et derive LOADED via `ScenarioManifestVersion::isLoaded()`. Le
 * `withCount` sur la relation de chargement n'existe pas : la presence du
 * lien suffit, et la FK `nullOnDelete` garantit qu'un lien present designe un
 * chargement vivant.
 *
 * Le droit vient du middleware `admin` (`users.is_admin`) et de lui seul :
 * le Scenario Manager est une surface SuperAdmin (CDC 4.1), et cet ecran ne
 * depend d'aucune Organization.
 */
class AdminScenarioManagerController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): View
    {
        // `scenarioPackLoad` est chargee pour que la vue puisse nommer la
        // sandbox d'une version chargee sans une requete par ligne.
        $versions = ScenarioManifestVersion::query()
            ->with(['scenarioPackLoad.organization', 'author', 'approver'])
            ->orderBy('scenario_key')
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE);

        return view('admin.outils.scenarios', [
            'versions' => $versions,
            'totals' => [
                'all' => ScenarioManifestVersion::query()->count(),
                'draft' => ScenarioManifestVersion::query()->draft()->count(),
                'valid' => ScenarioManifestVersion::query()->valid()->count(),
                'loaded' => ScenarioManifestVersion::query()->loaded()->count(),
                'scenarios' => ScenarioManifestVersion::query()->distinct()->count('scenario_key'),
            ],
        ]);
    }
}
