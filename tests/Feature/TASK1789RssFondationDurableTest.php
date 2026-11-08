<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\RssSource;
use App\Models\RssSourceArticle;
use App\Models\User;
use App\Services\Blog\RssArticleImporter;
use App\Services\Blog\RssFeedReader;
use App\Services\Blog\RssFeedUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1789 — la fondation durable de l'import RSS : persistance des sources
 * et des articles decouverts, et l'angle mort soft-delete corrige dans
 * `RssArticleImporter::dejaImporte()`.
 *
 * Complement de `TASK1785RssImportTest` (rejoue sans modification de contrat,
 * hors adaptation mecanique de la selection par id — voir son propre fichier).
 * Ce fichier ne reteste pas SSRF/XXE/sanitisation : deja couverts, et les
 * services concernes ne sont pas touches par TASK-1789.
 */
class TASK1789RssFondationDurableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function flux(): string
    {
        return file_get_contents(base_path('tests/Fixtures/Rss/wordpress.xml'));
    }

    /** Le meme flux, mais le premier item seulement — pour simuler sa rotation. */
    private function fluxReduit(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
        <channel>
          <title>CP Formation</title>
          <link>https://cpformation.com</link>
          <item>
            <title>Vigilance face aux demarchages commerciaux</title>
            <link>https://cpformation.com/vigilance-demarchages/</link>
            <guid isPermaLink="false">https://cpformation.com/?p=1234</guid>
            <pubDate>Tue, 03 Nov 2026 09:15:00 +0000</pubDate>
          </item>
        </channel>
        </rss>
        XML;
    }

    /**
     * Un flux dont `guid`, `url`, `titre` et `auteur` depassent largement les
     * anciennes longueurs `string()` (500 / 2048 / 500 / 255) — exactement le
     * cas que la revue MASTER (commit `f4953aea`) a fait passer en `text()`.
     *
     * @return array{xml: string, guid: string, url: string, titre: string, auteur: string}
     */
    private function fluxMetadonneesLongues(): array
    {
        $guid = 'guid-'.str_repeat('x', 600);
        $url = 'https://cpformation.test/article?'.str_repeat('q=1&', 600);
        $titre = str_repeat('Un titre tres long ', 40);
        $auteur = str_repeat('Camille-Durand ', 30);

        // Le `&` de l'URL doit etre ECHAPPE dans le XML (`&amp;`), sinon le
        // document n'est pas bien forme — mesure directement ici : le premier
        // jet de ce test echouait en `xml_illisible` pour cette raison. Les
        // assertions, elles, comparent contre `$url` DECODE, exactement ce
        // que `textContent` rend a la lecture.
        $urlEchappe = str_replace('&', '&amp;', $url);

        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"
          xmlns:content="http://purl.org/rss/1.0/modules/content/"
          xmlns:dc="http://purl.org/dc/elements/1.1/">
        <channel>
          <title>CP Formation</title>
          <link>https://cpformation.com</link>
          <item>
            <title>{$titre}</title>
            <link>{$urlEchappe}</link>
            <guid isPermaLink="false">{$guid}</guid>
            <pubDate>Tue, 03 Nov 2026 09:15:00 +0000</pubDate>
            <dc:creator>{$auteur}</dc:creator>
            <description>Resume court.</description>
          </item>
        </channel>
        </rss>
        XML;

        return ['xml' => $xml, 'guid' => $guid, 'url' => $url, 'titre' => trim($titre), 'auteur' => trim($auteur)];
    }

    private function organization(string $slug): Organization
    {
        return Organization::factory()->create([
            'slug' => $slug,
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    private function superadmin(Organization $org): User
    {
        return User::factory()->create(['organization_id' => $org->id, 'is_admin' => true]);
    }

    /** Une garde dont le resolveur est une TABLE, donc sans DNS. */
    private function garde(array $table): RssFeedUrlGuard
    {
        return new class($table) extends RssFeedUrlGuard
        {
            public function __construct(private array $table) {}

            protected function resoudre(string $nom): array
            {
                return $this->table[$nom] ?? [];
            }
        };
    }

    // =====================================================================
    // 1. Persistance : plus de session, une table qui survit a la requete
    // =====================================================================

    public function test_charger_deux_fois_la_meme_url_ne_duplique_aucune_ligne(): void
    {
        $org = $this->organization('persistance');
        $admin = $this->superadmin($org);

        // Une CLOSURE, pas une reponse statique : chaque requete recoit un
        // flux frais. Une reponse unique partagee entre deux requetes voit
        // son flux (stream PSR) deja epuise a la seconde lecture — mesure
        // directement ici, cf. `_local/howto/HOWTO-http-fake-response-override.md`
        // pour un autre piege du meme `Http::fake()`.
        Http::fake(['*' => fn () => Http::response($this->flux(), 200, ['Content-Type' => 'application/rss+xml'])]);
        $this->app->instance(RssFeedUrlGuard::class, $this->garde(['cpformation.test' => ['93.184.216.34']]));

        $this->actingAs($admin)->post(route('admin.outils.import-rss.charger'), [
            'url' => 'https://cpformation.test/feed/',
        ])->assertSessionHas('rss_charge', 3);

        $this->actingAs($admin)->post(route('admin.outils.import-rss.charger'), [
            'url' => 'https://cpformation.test/feed/',
        ])->assertSessionHas('rss_charge', 3);

        $this->assertSame(1, RssSource::where('url', 'https://cpformation.test/feed/')->count());
        $this->assertSame(3, RssSourceArticle::count());
    }

    public function test_le_rafraichissement_ne_supprime_pas_un_article_disparu_du_flux(): void
    {
        $org = $this->organization('rotation-flux');
        $admin = $this->superadmin($org);

        // TASK-1785 — decision MASTER (precheck TASK-1789) : un flux
        // WordPress ne sert que ses N derniers items, et un article qui en
        // sort ne doit pas disparaitre du cache deja constitue.
        Http::fake(['*' => Http::sequence()
            ->push($this->flux(), 200, ['Content-Type' => 'application/rss+xml'])
            ->push($this->fluxReduit(), 200, ['Content-Type' => 'application/rss+xml'])]);
        $this->app->instance(RssFeedUrlGuard::class, $this->garde(['cpformation.test' => ['93.184.216.34']]));

        $this->actingAs($admin)->post(route('admin.outils.import-rss.charger'), [
            'url' => 'https://cpformation.test/feed/',
        ])->assertSessionHas('rss_charge', 3);

        $this->assertSame(3, RssSourceArticle::count());

        $this->actingAs($admin)->post(route('admin.outils.import-rss.charger'), [
            'url' => 'https://cpformation.test/feed/',
        ])->assertSessionHas('rss_charge', 1);

        // Le flux relu n'en porte plus qu'un, mais les trois restent en cache.
        $this->assertSame(3, RssSourceArticle::count());
    }

    /**
     * Revue MASTER (commit `f4953aea`) : `guid`/`url`/`titre`/`auteur` sont
     * passes de `string()` borne a `text()`. Ce test envoie des valeurs qui
     * depassaient les anciennes limites, et verifie deux choses : le
     * rafraichissement REUSSIT (aucune troncature ni erreur de contrainte),
     * et l'identite — un hash SHA-256 du guid, deja borne par construction —
     * reste STABLE d'une lecture a l'autre.
     */
    public function test_le_rafraichissement_reussit_avec_des_metadonnees_externes_longues(): void
    {
        $org = $this->organization('metadonnees-longues');
        $admin = $this->superadmin($org);
        $flux = $this->fluxMetadonneesLongues();

        Http::fake(['*' => fn () => Http::response($flux['xml'], 200, ['Content-Type' => 'application/rss+xml'])]);
        $this->app->instance(RssFeedUrlGuard::class, $this->garde(['cpformation.test' => ['93.184.216.34']]));

        $this->actingAs($admin)->post(route('admin.outils.import-rss.charger'), [
            'url' => 'https://cpformation.test/feed/',
        ])->assertSessionHas('rss_charge', 1);

        $article = RssSourceArticle::first();
        $this->assertNotNull($article);
        $this->assertSame($flux['guid'], $article->guid);
        $this->assertSame($flux['url'], $article->url);
        $this->assertSame($flux['titre'], $article->titre);
        $this->assertSame($flux['auteur'], $article->auteur);

        $identiteInitiale = $article->source_identity;

        // Rafraichissement : doit reussir de nouveau, mettre a jour LA MEME
        // ligne (upsert par source_identity), jamais en creer une seconde.
        $this->actingAs($admin)->post(route('admin.outils.import-rss.charger'), [
            'url' => 'https://cpformation.test/feed/',
        ])->assertSessionHas('rss_charge', 1);

        $this->assertSame(1, RssSourceArticle::count());
        $article->refresh();
        $this->assertSame($identiteInitiale, $article->source_identity);
        $this->assertSame($flux['guid'], $article->guid);
    }

    // =====================================================================
    // 2. Selection par id : appartenance reverifiee a la source courante
    // =====================================================================

    public function test_un_id_d_une_autre_source_dans_la_selection_est_ignore(): void
    {
        $org = $this->organization('fuite-source');
        $admin = $this->superadmin($org);

        $sourceA = RssSource::create(['url' => 'https://a.test/feed/']);
        $sourceB = RssSource::create(['url' => 'https://b.test/feed/']);

        $articleA = RssSourceArticle::create([
            'rss_source_id' => $sourceA->id,
            'source_identity' => 'guid:aaa',
            'titre' => 'Article A',
            'fetched_at' => now(),
        ]);

        $articleB = RssSourceArticle::create([
            'rss_source_id' => $sourceB->id,
            'source_identity' => 'guid:bbb',
            'titre' => 'Article B',
            'fetched_at' => now(),
        ]);

        // On annonce travailler sur la source A, mais on glisse l'id de
        // l'article de la source B dans la selection — jamais cru tel quel.
        $import = $this->actingAs($admin)->post(route('admin.outils.import-rss.importer'), [
            'rss_source_id' => $sourceA->id,
            'organization_id' => $org->id,
            'selection' => [$articleB->id],
        ]);

        $import->assertSessionHas('rss_erreur', 'selection_vide');
        $this->assertSame(0, BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->count());
    }

    public function test_un_id_valide_de_la_source_courante_s_importe_normalement(): void
    {
        $org = $this->organization('selection-valide');
        $admin = $this->superadmin($org);

        $source = RssSource::create(['url' => 'https://valide.test/feed/']);
        $article = RssSourceArticle::create([
            'rss_source_id' => $source->id,
            'source_identity' => 'guid:valide',
            'titre' => 'Article valide',
            'fetched_at' => now(),
        ]);

        $import = $this->actingAs($admin)->post(route('admin.outils.import-rss.importer'), [
            'rss_source_id' => $source->id,
            'organization_id' => $org->id,
            'selection' => [$article->id],
        ]);

        $import->assertSessionHas('rss_resultat');
        $this->assertCount(1, session('rss_resultat')['crees']);
        $this->assertSame(1, BlogPost::withoutGlobalScopes()->where('organization_id', $org->id)->count());
    }

    // =====================================================================
    // 3. L'angle mort soft-delete : lecture honnete, pas de recreation
    // =====================================================================

    public function test_un_article_supprime_est_rapporte_comme_doublon_supprime(): void
    {
        $org = $this->organization('article-supprime');
        $admin = $this->superadmin($org);
        $articlesDuFlux = app(RssFeedReader::class)->lire($this->flux());
        $importer = app(RssArticleImporter::class);

        // Un premier import cree l'article...
        $importer->importer([$articlesDuFlux[0]], $org, $admin);
        $article = BlogPost::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('source_guid', $articlesDuFlux[0]['identite'])
            ->first();
        $this->assertNotNull($article);

        // ...puis il est supprime (soft delete), comme un SuperAdmin le ferait
        // depuis `/admin/blog`.
        $article->delete();

        // Avant TASK-1789 : ceci rendait `erreurs: echec_enregistrement`, un
        // message faux. La lecture de `dejaImporte()` doit desormais trouver
        // la ligne supprimee et la rapporter pour ce qu'elle est.
        $resultat = $importer->importer([$articlesDuFlux[0]], $org, $admin);

        $this->assertCount(0, $resultat['crees']);
        $this->assertCount(0, $resultat['erreurs']);
        $this->assertCount(1, $resultat['doublons']);
        $this->assertTrue($resultat['doublons'][0]['supprime']);

        // Aucune recreation automatique : l'index unique reste la seule
        // garantie, et elle n'est pas contournee.
        $this->assertSame(1, BlogPost::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('source_guid', $articlesDuFlux[0]['identite'])
            ->count());
    }

    public function test_un_doublon_ordinaire_n_est_jamais_marque_supprime(): void
    {
        $org = $this->organization('doublon-ordinaire');
        $admin = $this->superadmin($org);
        $articlesDuFlux = app(RssFeedReader::class)->lire($this->flux());
        $importer = app(RssArticleImporter::class);

        $importer->importer([$articlesDuFlux[0]], $org, $admin);
        $resultat = $importer->importer([$articlesDuFlux[0]], $org, $admin);

        $this->assertCount(1, $resultat['doublons']);
        $this->assertFalse($resultat['doublons'][0]['supprime']);
    }

    // =====================================================================
    // 4. La vue n'offre jamais de lien « Modifier » vers un article supprime
    // =====================================================================

    public function test_la_vue_n_affiche_pas_de_lien_modifier_pour_un_doublon_supprime(): void
    {
        $org = $this->organization('vue-supprime');
        $admin = $this->superadmin($org);
        $articlesDuFlux = app(RssFeedReader::class)->lire($this->flux());
        $importer = app(RssArticleImporter::class);

        // Un premier article reste normal (temoin : son lien DOIT apparaitre,
        // sinon l'assertion d'absence ci-dessous ne prouverait rien).
        $importer->importer([$articlesDuFlux[1]], $org, $admin);

        $importer->importer([$articlesDuFlux[0]], $org, $admin);
        $supprime = BlogPost::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('source_guid', $articlesDuFlux[0]['identite'])
            ->first();
        $supprime->delete();

        $resultat = $importer->importer([$articlesDuFlux[0], $articlesDuFlux[1]], $org, $admin);

        $page = $this->actingAs($admin)
            ->withSession(['rss_resultat' => $resultat, 'rss_organization' => $org->slug])
            ->get(route('admin.outils.import-rss'));

        $page->assertSee(__('admin.import_rss.supprime'));
        // Le temoin : le second article, lui, N'EST PAS supprime, et son lien
        // « Modifier » doit bien etre rendu — preuve que l'assertion ci-dessus
        // sait distinguer un lien present d'un lien absent.
        $vivant = BlogPost::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('source_guid', $articlesDuFlux[1]['identite'])
            ->first();
        $page->assertSee(route('admin.blog.edit', ['post' => $vivant->id]), escape: false);
        $page->assertDontSee(route('admin.blog.edit', ['post' => $supprime->id]), escape: false);
    }
}
