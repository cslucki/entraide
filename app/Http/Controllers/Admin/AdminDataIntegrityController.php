<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Integrity\DataIntegrityService;
use App\Support\Integrity\IntegrityCheck;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * TASK-1632 — `/admin/outils/integrite-donnees`.
 *
 * ## Deux routes, toutes deux en GET
 *
 * Il n'y a **aucune route de mutation** dans cette TASK : ni purge, ni
 * affectation, ni « corriger ». C'est ce qui permet d'ouvrir l'ecran sans se
 * demander ce qu'on risque, et un test verifie qu'aucune route nommee
 * `admin.outils.integrite*` n'accepte autre chose qu'un GET — parce que
 * « l'outil est en lecture seule » est une promesse qui doit se prouver, pas
 * se declarer dans un commentaire.
 *
 * Agir se fait ailleurs : `/admin/outils/assign-data` pour rattacher
 * (TASK-1631), `/admin/outils/dossiers` pour purger (TASK-1630). Le cockpit
 * y renvoie, il ne les reimplemente pas.
 *
 * Le droit vient du middleware `admin` (`users.is_admin`) et de lui seul :
 * un OrgAdmin (`organizations.admin_id`) n'entre pas — cet ecran voit toutes
 * les Organizations.
 */
class AdminDataIntegrityController extends Controller
{
    private const PER_PAGE = 50;

    public function index(DataIntegrityService $integrity): View
    {
        $checks = $integrity->all();

        return view('admin.outils.integrite-donnees', [
            'checks' => $checks,
            'groups' => $checks->groupBy(fn (IntegrityCheck $c) => $c->group),
            'summary' => $integrity->summary(),
            'worst' => $integrity->worstStatus(),
        ]);
    }

    /**
     * Les lignes derriere un controle — paginees, en lecture seule, et
     * limitees aux colonnes que la requete du service a selectionnees.
     *
     * Meme principe de liste blanche qu'en TASK-1631 : la vue n'affiche que
     * ce que la requete a nomme, jamais un `select *` dont une colonne
     * ajoutee demain se retrouverait a l'ecran.
     */
    public function detail(Request $request, string $check, DataIntegrityService $integrity): View
    {
        $definition = $integrity->all()->firstWhere(fn (IntegrityCheck $c) => $c->detailKey === $check);

        abort_if($definition === null, 404);

        $query = $integrity->detailQuery($check);

        abort_if($query === null, 404);

        $rows = $query->paginate(self::PER_PAGE)->withQueryString();

        return view('admin.outils.integrite-donnees-detail', [
            'check' => $definition,
            'rows' => $rows,
            'columns' => $this->columnsOf($rows->items()),
        ]);
    }

    /**
     * Les colonnes reellement rendues par la requete du service.
     *
     * @param  array<int, object>  $items
     * @return list<string>
     */
    private function columnsOf(array $items): array
    {
        if ($items === []) {
            return [];
        }

        return array_keys((array) $items[0]);
    }
}
