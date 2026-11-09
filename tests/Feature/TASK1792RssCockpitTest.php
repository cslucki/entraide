<?php

namespace Tests\Feature;

use App\Livewire\Admin\RssEditorialHistory;
use App\Livewire\Admin\RssSourceArticles;
use App\Livewire\Admin\RssSources;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\RssSource;
use App\Models\RssSourceArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1792 — le cockpit RSS : gestion des sources (renommer / actualiser /
 * desactiver / archiver, JAMAIS de suppression physique) et historique
 * editorial (statut par Organization, filtres, recherche, pagination
 * serveur). Decisions MASTER du 09/11.
 *
 * Complement de TASK1785/1789/1790/1791 — services RSS, antidoublon,
 * soft-delete et securite Livewire non retestes ici (inchanges).
 */
class TASK1792RssCockpitTest extends TestCase
{
    use RefreshDatabase;

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

    private function source(string $url = 'https://cpformation.test/feed/', array $overrides = []): RssSource
    {
        return RssSource::create(array_merge(['url' => $url], $overrides));
    }

    private function article(RssSource $source, array $overrides = []): RssSourceArticle
    {
        return RssSourceArticle::create(array_merge([
            'rss_source_id' => $source->id,
            'source_identity' => 'guid:'.hash('sha256', (string) \Illuminate\Support\Str::uuid()),
            'guid' => 'un-guid',
            'url' => 'https://cpformation.com/un-article/',
            'titre' => 'Un article de demonstration',
            'auteur' => 'Camille',
            'resume' => 'Un resume court.',
            'contenu_html' => '<p>Premier paragraphe.</p>',
            'source_published_at' => now()->subDay(),
            'fetched_at' => now(),
        ], $overrides));
    }

    private function blogPost(Organization $org, string $identite, array $overrides = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'organization_id' => $org->id,
            'user_id' => $this->superadmin($org)->id,
            'title' => 'Article '.$identite,
            'slug' => 'article-'.md5($identite),
            'content' => '<p>Contenu</p>',
            'status' => 'draft',
            'source_guid' => $identite,
        ], $overrides));
    }

    // =====================================================================
    // B. Gestion des sources — jamais de suppression physique
    // =====================================================================

    public function test_renommer_une_source(): void
    {
        $org = $this->organization('renommer-source');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $this->article($source);

        $this->actingAs($admin);

        Livewire::test(RssSourceArticles::class, ['rssSource' => $source])
            ->set('label', 'Mon flux prefere')
            ->call('renommer')
            ->assertSee('Mon flux prefere');

        $this->assertSame('Mon flux prefere', $source->refresh()->label);
    }

    public function test_renommer_avec_une_chaine_vide_retire_le_label(): void
    {
        $org = $this->organization('renommer-vide');
        $admin = $this->superadmin($org);
        $source = $this->source(overrides: ['label' => 'Ancien nom']);
        $this->article($source);

        $this->actingAs($admin);

        Livewire::test(RssSourceArticles::class, ['rssSource' => $source])
            ->set('label', '   ')
            ->call('renommer');

        $this->assertNull($source->refresh()->label);
    }

    public function test_actualiser_decouvre_les_articles_du_flux(): void
    {
        $org = $this->organization('actualiser-source');
        $admin = $this->superadmin($org);
        $source = $this->source('https://actualiser.test/feed/');

        Http::fake(['*' => fn () => Http::response($this->flux(), 200, ['Content-Type' => 'application/rss+xml'])]);
        $this->app->instance(\App\Services\Blog\RssFeedUrlGuard::class, $this->garde(['actualiser.test' => ['93.184.216.34']]));

        $this->actingAs($admin);

        Livewire::test(RssSourceArticles::class, ['rssSource' => $source])
            ->call('actualiser')
            ->assertSee(__('admin.import_rss.sources.actualiser_succes', ['nombre' => 1]));

        $this->assertSame(1, $source->articles()->count());
        $this->assertNotNull($source->refresh()->last_fetched_at);
    }

    public function test_actualiser_est_refuse_quand_la_source_est_desactivee(): void
    {
        $org = $this->organization('actualiser-desactivee');
        $admin = $this->superadmin($org);
        $source = $this->source('https://desactivee.test/feed/', ['disabled_at' => now()]);

        // Aucune requete HTTP ne doit meme etre tentee : si le refus cote
        // serveur echouait, `Http::fake()` sans route configuree pour cette
        // URL ferait echouer le test par une exception (`preventStrayRequests`
        // n'est pas actif ici, mais aucune reponse ne serait fournie non plus).
        Http::fake(['*' => fn () => Http::response($this->flux(), 200)]);

        $this->actingAs($admin);

        Livewire::test(RssSourceArticles::class, ['rssSource' => $source])
            ->call('actualiser');

        $this->assertSame(0, $source->articles()->count());
        $this->assertNull($source->refresh()->last_fetched_at);
    }

    public function test_basculer_activation_est_reversible(): void
    {
        $org = $this->organization('bascule-activation');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $this->article($source);

        $this->actingAs($admin);

        $test = Livewire::test(RssSourceArticles::class, ['rssSource' => $source]);

        $test->call('basculerActivation');
        $this->assertTrue($source->refresh()->isDisabled());

        $test->call('basculerActivation');
        $this->assertFalse($source->refresh()->isDisabled());
    }

    public function test_basculer_archivage_est_reversible(): void
    {
        $org = $this->organization('bascule-archivage');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $this->article($source);

        $this->actingAs($admin);

        $test = Livewire::test(RssSourceArticles::class, ['rssSource' => $source]);

        $test->call('basculerArchivage');
        $this->assertTrue($source->refresh()->isArchived());

        $test->call('basculerArchivage');
        $this->assertFalse($source->refresh()->isArchived());
    }

    public function test_les_sources_archivees_sont_masquees_par_defaut_et_affichables(): void
    {
        $org = $this->organization('archivees-masquees');
        $admin = $this->superadmin($org);
        $visible = $this->source('https://visible.test/feed/', ['label' => 'Source visible']);
        $archivee = $this->source('https://archivee.test/feed/', ['label' => 'Source archivee', 'archived_at' => now()]);

        $this->actingAs($admin);

        $test = Livewire::test(RssSources::class);
        $test->assertSee('Source visible');
        $test->assertDontSee('Source archivee');

        $test->set('afficherArchivees', true);
        $test->assertSee('Source archivee');
    }

    // =====================================================================
    // C+D. Historique editorial — filtres, recherche, pagination serveur
    // =====================================================================

    public function test_filtre_par_source(): void
    {
        $org = $this->organization('historique-filtre-source');
        $admin = $this->superadmin($org);
        $sourceA = $this->source('https://a.test/feed/');
        $sourceB = $this->source('https://b.test/feed/');
        $this->article($sourceA, ['titre' => 'Article de A']);
        $this->article($sourceB, ['titre' => 'Article de B']);

        $this->actingAs($admin);

        Livewire::test(RssEditorialHistory::class)
            ->set('sourceId', (string) $sourceA->id)
            ->assertSee('Article de A')
            ->assertDontSee('Article de B');
    }

    public function test_filtre_statut_non_importe_sans_organization(): void
    {
        $org = $this->organization('historique-non-importe');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $jamaisImporte = $this->article($source, ['titre' => 'Jamais importe', 'source_identity' => 'guid:jamais-importe']);
        $dejaImporte = $this->article($source, ['titre' => 'Deja importe', 'source_identity' => 'guid:deja-importe']);
        $this->blogPost($org, 'guid:deja-importe');

        $this->actingAs($admin);

        Livewire::test(RssEditorialHistory::class)
            ->set('statut', 'non_importe')
            ->assertSee('Jamais importe')
            ->assertDontSee('Deja importe');
    }

    public function test_filtre_statut_publie_et_supprime_sans_organization(): void
    {
        $org = $this->organization('historique-publie-supprime');
        $admin = $this->superadmin($org);
        $source = $this->source();

        $articlePublie = $this->article($source, ['titre' => 'Article publie partout', 'source_identity' => 'guid:publie']);
        $this->blogPost($org, 'guid:publie', ['status' => 'published', 'published_at' => now()]);

        $articleSupprime = $this->article($source, ['titre' => 'Article supprime partout', 'source_identity' => 'guid:supprime']);
        $postSupprime = $this->blogPost($org, 'guid:supprime');
        $postSupprime->delete();

        $this->actingAs($admin);

        Livewire::test(RssEditorialHistory::class)
            ->set('statut', 'publie')
            ->assertSee('Article publie partout')
            ->assertDontSee('Article supprime partout');

        Livewire::test(RssEditorialHistory::class)
            ->set('statut', 'supprime')
            ->assertSee('Article supprime partout')
            ->assertDontSee('Article publie partout');
    }

    public function test_filtre_par_organization_precise_le_statut(): void
    {
        $orgA = $this->organization('historique-org-a');
        $orgB = $this->organization('historique-org-b');
        $admin = $this->superadmin($orgA);
        $source = $this->source();
        $article = $this->article($source, ['titre' => 'Article partage', 'source_identity' => 'guid:partage']);
        $this->blogPost($orgA, 'guid:partage'); // brouillon chez A, rien chez B

        $this->actingAs($admin);

        // Chez A : brouillon -> apparait avec le filtre "brouillon".
        Livewire::test(RssEditorialHistory::class)
            ->set('organizationId', (string) $orgA->id)
            ->set('statut', 'brouillon')
            ->assertSee('Article partage');

        // Chez B : non importe -> apparait avec le filtre "non importe", pas "brouillon".
        Livewire::test(RssEditorialHistory::class)
            ->set('organizationId', (string) $orgB->id)
            ->set('statut', 'non_importe')
            ->assertSee('Article partage');

        Livewire::test(RssEditorialHistory::class)
            ->set('organizationId', (string) $orgB->id)
            ->set('statut', 'brouillon')
            ->assertDontSee('Article partage');
    }

    public function test_filtre_par_date(): void
    {
        $org = $this->organization('historique-date');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $ancien = $this->article($source, ['titre' => 'Article ancien', 'source_published_at' => now()->subYear()]);
        $recent = $this->article($source, ['titre' => 'Article recent', 'source_published_at' => now()->subDay()]);

        $this->actingAs($admin);

        Livewire::test(RssEditorialHistory::class)
            ->set('dateDepuis', now()->subWeek()->toDateString())
            ->assertSee('Article recent')
            ->assertDontSee('Article ancien');
    }

    public function test_recherche_par_titre(): void
    {
        $org = $this->organization('historique-recherche');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $this->article($source, ['titre' => 'Vigilance face aux arnaques']);
        $this->article($source, ['titre' => 'Autre sujet sans rapport']);

        $this->actingAs($admin);

        // Recherche sur un SOUS-MOT, dans la MEME casse que le titre : la
        // casse de `like` n'est pas la meme sur SQLite (insensible) et
        // PostgreSQL (sensible, sans ILIKE) — convention deja en vigueur sur
        // toutes les recherches admin existantes (ex. `AdminBlogController`),
        // non revisee par cette TASK.
        Livewire::test(RssEditorialHistory::class)
            ->set('recherche', 'Vigilance')
            ->assertSee('Vigilance face aux arnaques')
            ->assertDontSee('Autre sujet sans rapport');
    }

    public function test_aucun_lien_invalide_pour_un_article_supprime(): void
    {
        $org = $this->organization('historique-lien-invalide');
        $admin = $this->superadmin($org);
        $source = $this->source();

        $articleVivant = $this->article($source, ['titre' => 'Article vivant', 'source_identity' => 'guid:vivant']);
        $vivant = $this->blogPost($org, 'guid:vivant');

        $articleSupprime = $this->article($source, ['titre' => 'Article mort', 'source_identity' => 'guid:mort']);
        $mort = $this->blogPost($org, 'guid:mort');
        $mort->delete();

        $this->actingAs($admin);

        $page = $this->actingAs($admin)->get(route('admin.outils.import-rss.historique'));

        // Temoin : le lien d'edition de l'article VIVANT doit bien etre
        // rendu, preuve que l'assertion d'absence ci-dessous sait distinguer
        // un lien present d'un lien absent.
        $page->assertSee(route('admin.blog.edit', ['post' => $vivant->id]), escape: false);
        $page->assertDontSee(route('admin.blog.edit', ['post' => $mort->id]), escape: false);
        $page->assertSee(__('admin.import_rss.supprime'));
    }

    public function test_pagination_cote_serveur(): void
    {
        $org = $this->organization('historique-pagination');
        $admin = $this->superadmin($org);
        $source = $this->source();

        for ($i = 0; $i < 25; $i++) {
            $this->article($source, ['titre' => "Article pagine {$i}", 'source_published_at' => now()->subDays($i)]);
        }

        $this->actingAs($admin);

        $page = $this->actingAs($admin)->get(route('admin.outils.import-rss.historique'));

        // 20 par page (meme plafond que les listes T1790) : le plus ancien
        // (jour 24) n'est pas sur la premiere page, le plus recent (jour 0) l'est.
        $page->assertSee('Article pagine 0');
        $page->assertDontSee('Article pagine 24');
    }

    // =====================================================================
    // Aides reprises de TASK1789RssFondationDurableTest (meme fixture)
    // =====================================================================

    private function flux(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
        <channel>
          <title>Flux de test</title>
          <link>https://actualiser.test</link>
          <item>
            <title>Article decouvert</title>
            <link>https://actualiser.test/article/</link>
            <guid isPermaLink="false">https://actualiser.test/?p=1</guid>
            <pubDate>Tue, 03 Nov 2026 09:15:00 +0000</pubDate>
            <description>Resume.</description>
          </item>
        </channel>
        </rss>
        XML;
    }

    private function garde(array $table): \App\Services\Blog\RssFeedUrlGuard
    {
        return new class($table) extends \App\Services\Blog\RssFeedUrlGuard
        {
            public function __construct(private array $table) {}

            protected function resoudre(string $nom): array
            {
                return $this->table[$nom] ?? [];
            }
        };
    }
}
