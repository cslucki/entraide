<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\RssSource;
use App\Models\RssSourceArticle;
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
 * TASK-1789 — la persistance durable des sources et des articles decouverts.
 *
 * Pose dans le groupe `['auth', 'admin']` (`routes/web.php:694`), donc
 * `AdminMiddleware` exige `is_admin` et rend `403` sinon — en LECTURE comme en
 * ECRITURE, ce que le CDC demande explicitement.
 *
 * ## Rien de ce que le navigateur renvoie n'est cru
 *
 * L'Organization et la Boucle sont RELUES en base a chaque soumission, et la
 * Boucle est reverifiee comme appartenant a cette Organization — cote serveur,
 * dans `RssArticleImporter`. Les articles selectionnes sont RELUS par id, et
 * leur appartenance a la source courante est reverifiee ici (`$source->articles()`),
 * jamais supposee depuis ce que le formulaire a renvoye.
 *
 * ## Pourquoi les flux et leurs articles vivent desormais en BASE (TASK-1789)
 *
 * Jusqu'a TASK-1785, un flux charge etait un brouillon de travail vivant en
 * SESSION — perdu a la deconnexion. Decision MASTER (precheck TASK-1789) :
 * memoire durable, independante des Organizations (`RssSource`,
 * `RssSourceArticle`) — une meme source peut nourrir plusieurs Organizations,
 * et deux admins qui consultent le meme flux partagent desormais le meme
 * cache. Seule reste en session la commodite d'interface « quelle source
 * cet onglet regardait-il en dernier » (`SESSION_URL`) — ce n'est pas une
 * donnee, juste un pointeur d'ecran, perdu sans consequence a la deconnexion.
 *
 * Un rafraichissement (`charger()`) met a jour les articles connus mais n'en
 * supprime JAMAIS : un flux WordPress ne sert que ses N derniers items, et un
 * article qui en sort ne doit pas disparaitre du cache qu'on a deja constitue.
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
    /** Pointeur d'ecran uniquement : quelle source cette session regarde. Pas une donnee. */
    private const SESSION_URL = 'rss_import.url';

    /** Plafond de l'historique de sources propose dans le datalist. */
    private const HISTORIQUE_MAX = 10;

    public function index(Request $request): View|\Illuminate\Contracts\View\View
    {
        $url = $request->session()->get(self::SESSION_URL);
        $source = $url !== null ? RssSource::where('url', $url)->first() : null;

        return view('admin.outils.import-rss', [
            'url' => $url,
            'rssSourceId' => $source?->id,
            'articles' => $source !== null
                ? $source->articles()->orderByDesc('source_published_at')->get()
                : collect(),
            'historique' => RssSource::query()
                ->orderByDesc('last_fetched_at')
                ->limit(self::HISTORIQUE_MAX)
                ->pluck('url'),
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

        $source = RssSource::firstOrCreate(
            ['url' => $valide['url']],
            ['created_by' => $request->user()->id],
        );

        $maintenant = now();

        foreach ($articles as $entree) {
            // UPSERT par (source, identite) — jamais de suppression des
            // articles absents de CETTE lecture : voir le docblock de classe.
            RssSourceArticle::query()->updateOrCreate(
                ['rss_source_id' => $source->id, 'source_identity' => $entree['identite']],
                [
                    'guid' => $entree['guid'],
                    'url' => $entree['url'],
                    'titre' => $entree['titre'],
                    'auteur' => $entree['auteur'],
                    'resume' => $entree['resume'],
                    'contenu_html' => $entree['contenu_html'],
                    'source_published_at' => RssArticleImporter::dateSource($entree['publie_le']),
                    'fetched_at' => $maintenant,
                ],
            );
        }

        $source->update(['last_fetched_at' => $maintenant]);

        $request->session()->put(self::SESSION_URL, $valide['url']);

        return back()->with('rss_charge', count($articles));
    }

    public function importer(Request $request, RssArticleImporter $importer): RedirectResponse
    {
        $valide = $request->validate([
            'rss_source_id' => ['required', 'uuid'],
            'organization_id' => ['required', 'string'],
            'loop_id' => ['nullable', 'string'],
            'selection' => ['required', 'array', 'min:1'],
            'selection.*' => ['string', 'uuid'],
            'publier' => ['nullable', 'boolean'],
            'droits_confirmes' => ['nullable', 'boolean'],
        ]);

        $source = RssSource::find($valide['rss_source_id']);

        if ($source === null) {
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

        // Appartenance reverifiee ICI : les id selectionnes doivent appartenir
        // a LA SOURCE COURANTE, jamais supposee depuis ce que le formulaire a
        // renvoye (TASK-1789, decision MASTER).
        // TASK-1790 : reconstruction factorisee sur `RssSourceArticle::versEntreeImport()`,
        // partagee avec la fiche individuelle — une seule fabrique.
        $choisis = $source->articles()
            ->whereIn('id', $valide['selection'])
            ->get()
            ->map(fn (RssSourceArticle $a) => $a->versEntreeImport())
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
    public function boucles(Request $request, string $organizationId): array
    {
        $org = Organization::query()
            ->where('is_active', true)
            ->whereKey($organizationId)
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
