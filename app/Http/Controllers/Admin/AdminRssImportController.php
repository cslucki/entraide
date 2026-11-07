<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Services\Blog\RssArticleImporter;
use App\Services\Blog\RssFeedFetcher;
use App\Services\Blog\RssFeedReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * TASK-1785 — l'ecran SuperAdmin d'import d'articles depuis un flux RSS.
 *
 * Pose dans le groupe `['auth', 'admin']` (`routes/web.php:694`), donc
 * `AdminMiddleware` exige `is_admin` et rend `403` sinon — en LECTURE comme en
 * ECRITURE, ce que le CDC demande explicitement.
 *
 * ## Rien de ce que le navigateur renvoie n'est cru
 *
 * L'Organization et la Boucle sont RELUES en base a chaque soumission, et la
 * Boucle est reverifiee comme appartenant a cette Organization — cote serveur,
 * dans `RssArticleImporter`. Les identifiants d'articles selectionnes ne
 * designent pas des lignes : ce sont des index dans le flux relu depuis la
 * session, donc rien a forger.
 *
 * ## Pourquoi les articles charges vivent en SESSION
 *
 * Le CDC demande « le minimum de persistance necessaire ». Un flux charge est
 * un brouillon de travail, pas une donnee : le stocker en base aurait demande
 * une seconde table, et une politique de purge, pour un etat qui ne survit pas
 * a la journee de travail.
 *
 * Consequence assumee, et dite a MASTER : les URL de flux memorisees le sont
 * **par session**. Elles se perdent a la deconnexion. Une memoire durable
 * tiendrait en une table de deux colonnes si Cyril la veut.
 *
 * ## Le rafraichissement ne publie rien
 *
 * `charger()` LIT, point. Aucune ecriture d'article n'a lieu hors de
 * `importer()`, et `importer()` n'est atteignable qu'en POST avec selection
 * explicite. Le CDC l'exige : « ne jamais publier automatiquement a la simple
 * consultation ou au rafraichissement ».
 */
class AdminRssImportController extends Controller
{
    private const SESSION_ARTICLES = 'rss_import.articles';

    private const SESSION_URL = 'rss_import.url';

    private const SESSION_HISTORIQUE = 'rss_import.historique';

    /** Plafond de l'historique des URL memorisees. */
    private const HISTORIQUE_MAX = 10;

    public function index(Request $request): View|\Illuminate\Contracts\View\View
    {
        return view('admin.outils.import-rss', [
            'url' => $request->session()->get(self::SESSION_URL),
            'articles' => $request->session()->get(self::SESSION_ARTICLES, []),
            'historique' => $request->session()->get(self::SESSION_HISTORIQUE, []),
            'organizations' => Organization::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug']),
        ]);
    }

    public function charger(Request $request, RssFeedFetcher $fetcher, RssFeedReader $reader): RedirectResponse
    {
        $valide = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url'],
        ]);

        try {
            $recupere = $fetcher->recuperer($valide['url']);
            $articles = $reader->lire($recupere['xml']);
        } catch (RuntimeException $e) {
            // Le code est STABLE et traduit a l'ecran ; le message d'un
            // transport n'a rien a y faire, il fuirait des details d'infra.
            return back()->with('rss_erreur', $e->getMessage());
        }

        $historique = collect($request->session()->get(self::SESSION_HISTORIQUE, []))
            ->prepend($valide['url'])
            ->unique()
            ->take(self::HISTORIQUE_MAX)
            ->values()
            ->all();

        $request->session()->put(self::SESSION_URL, $valide['url']);
        $request->session()->put(self::SESSION_ARTICLES, $articles);
        $request->session()->put(self::SESSION_HISTORIQUE, $historique);

        return back()->with('rss_charge', count($articles));
    }

    public function importer(Request $request, RssArticleImporter $importer): RedirectResponse
    {
        $valide = $request->validate([
            'organization_id' => ['required', 'string'],
            'loop_id' => ['nullable', 'string'],
            'selection' => ['required', 'array', 'min:1'],
            'selection.*' => ['integer', 'min:0'],
            'publier' => ['nullable', 'boolean'],
            'droits_confirmes' => ['nullable', 'boolean'],
        ]);

        $articles = $request->session()->get(self::SESSION_ARTICLES, []);

        if ($articles === []) {
            return back()->with('rss_erreur', 'aucun_flux_charge');
        }

        $organization = Organization::query()
            ->where('is_active', true)
            ->whereKey($valide['organization_id'])
            ->first();

        if ($organization === null) {
            return back()->with('rss_erreur', 'organization_inconnue');
        }

        $boucle = null;

        if (! empty($valide['loop_id'])) {
            // Relue SANS la portee globale puis bornee a la main : l'ecran
            // tourne hors contexte d'Organization, et la portee refuserait
            // tout. La frontiere est le `where` explicite, pas la portee.
            $boucle = Loop::query()
                ->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->whereKey($valide['loop_id'])
                ->where('organization_id', $organization->id)
                ->first();
        }

        // La publication demande les DEUX : l'intention, et la confirmation
        // des droits de republication. L'une sans l'autre reste un brouillon.
        $publier = (bool) ($valide['publier'] ?? false) && (bool) ($valide['droits_confirmes'] ?? false);

        $choisis = collect($valide['selection'])
            ->map(fn ($i) => $articles[$i] ?? null)
            ->filter()
            ->values()
            ->all();

        if ($choisis === []) {
            return back()->with('rss_erreur', 'selection_vide');
        }

        $resultat = $importer->importer(
            $choisis,
            $organization,
            $request->user(),
            $boucle,
            $publier,
        );

        return back()
            ->with('rss_resultat', $resultat)
            ->with('rss_organization', $organization->slug);
    }

    /**
     * Les Boucles d'une Organization, pour le selecteur.
     *
     * Rendues par une route a part plutot que toutes chargees d'avance : le
     * CDC exige de n'afficher QUE les Boucles de l'Organization choisie, et
     * envoyer tout le reste au navigateur serait une fuite inter-tenant — meme
     * masquee a l'ecran.
     */
    public function boucles(Request $request, string $organization): array
    {
        $org = Organization::query()
            ->where('is_active', true)
            ->whereKey($organization)
            ->first();

        if ($org === null) {
            return [];
        }

        return Loop::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('organization_id', $org->id)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Loop $l) => ['id' => (string) $l->id, 'name' => $l->name])
            ->all();
    }
}
