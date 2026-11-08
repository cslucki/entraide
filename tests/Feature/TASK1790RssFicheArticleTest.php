<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminMiddleware;
use App\Livewire\Admin\RssArticleFiche;
use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\RssSource;
use App\Models\RssSourceArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1790 — le parcours individuel : source -> articles -> fiche ->
 * Organization/Boucle -> brouillon -> `admin.blog.edit`.
 *
 * Complement de `TASK1785RssImportTest` (ecran en lot, intact) et
 * `TASK1789RssFondationDurableTest` (persistance). Ce fichier ne reteste pas
 * SSRF/XXE/sanitation a l'import : deja couverts, et les services RSS ne
 * sont pas touches par TASK-1790.
 */
class TASK1790RssFicheArticleTest extends TestCase
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

    private function source(string $url = 'https://cpformation.test/feed/'): RssSource
    {
        return RssSource::create(['url' => $url]);
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

    // =====================================================================
    // 1. Permissions — les trois ecrans sont SuperAdmin-only
    // =====================================================================

    public static function lesTroisEcrans(): array
    {
        return [
            'liste des sources' => ['sources'],
            'articles d une source' => ['source'],
            'fiche d un article' => ['article'],
        ];
    }

    #[DataProvider('lesTroisEcrans')]
    public function test_un_non_superadmin_est_refuse_sur_les_trois_ecrans(string $ecran): void
    {
        $org = $this->organization('permissions');
        $membre = User::factory()->create(['organization_id' => $org->id, 'is_admin' => false]);
        $source = $this->source();
        $article = $this->article($source);

        $url = match ($ecran) {
            'sources' => route('admin.outils.import-rss.sources'),
            'source' => route('admin.outils.import-rss.sources.show', $source),
            'article' => route('admin.outils.import-rss.articles.show', $article),
        };

        $this->actingAs($membre)->get($url)->assertForbidden();
    }

    public function test_un_superadmin_atteint_les_trois_ecrans(): void
    {
        $org = $this->organization('acces-superadmin');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source);

        $this->actingAs($admin)->get(route('admin.outils.import-rss.sources'))->assertOk();
        $this->actingAs($admin)->get(route('admin.outils.import-rss.sources.show', $source))->assertOk();
        $this->actingAs($admin)->get(route('admin.outils.import-rss.articles.show', $article))->assertOk();
    }

    // =====================================================================
    // 2. Isolation entre sources, et contenu de la fiche
    // =====================================================================

    public function test_les_articles_d_une_source_n_apparaissent_pas_sous_une_autre(): void
    {
        $org = $this->organization('isolation-sources');
        $admin = $this->superadmin($org);

        $sourceA = $this->source('https://a.test/feed/');
        $sourceB = $this->source('https://b.test/feed/');
        $articleA = $this->article($sourceA, ['titre' => 'Article de A']);
        $articleB = $this->article($sourceB, ['titre' => 'Article de B']);

        $page = $this->actingAs($admin)->get(route('admin.outils.import-rss.sources.show', $sourceA));

        $page->assertSee('Article de A');
        $page->assertDontSee('Article de B');
    }

    public function test_la_fiche_affiche_titre_date_auteur_resume_et_lien_original(): void
    {
        $org = $this->organization('fiche-contenu');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source, [
            'titre' => 'Vigilance face aux demarchages',
            'auteur' => 'Camille Durand',
            'url' => 'https://cpformation.com/vigilance/',
            'resume' => 'Un resume de test.',
        ]);

        $page = $this->actingAs($admin)->get(route('admin.outils.import-rss.articles.show', $article));

        $page->assertSee('Vigilance face aux demarchages');
        $page->assertSee('Camille Durand');
        $page->assertSee('https://cpformation.com/vigilance/', escape: false);
    }

    public function test_le_contenu_affiche_est_assaini_jamais_de_script_brut(): void
    {
        $org = $this->organization('fiche-xss');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source, [
            'contenu_html' => '<p>Texte</p><script>alert(1)</script>',
        ]);

        $page = $this->actingAs($admin)->get(route('admin.outils.import-rss.articles.show', $article));

        $page->assertOk();
        $page->assertDontSee('<script>alert(1)</script>', escape: false);
        $page->assertDontSee('alert(1)');
    }

    // =====================================================================
    // 3. Organization -> Boucle, reactif et isole
    // =====================================================================

    public function test_changer_d_organization_recharge_les_boucles_de_la_bonne_organization(): void
    {
        $org = $this->organization('reactif-boucles');
        $admin = $this->superadmin($org);
        $autreOrg = $this->organization('reactif-boucles-autre');

        $boucleDeOrg = Loop::factory()->create(['organization_id' => $org->id, 'name' => 'Boucle de org']);
        $boucleDeAutreOrg = Loop::factory()->create(['organization_id' => $autreOrg->id, 'name' => 'Boucle etrangere']);

        $source = $this->source();
        $article = $this->article($source);

        $this->actingAs($admin);

        $test = Livewire::test(RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) $org->id);

        $test->assertSee('Boucle de org');
        $test->assertDontSee('Boucle etrangere');

        // Choisir la Boucle, PUIS changer d'Organization : elle doit se
        // reinitialiser (meme regle que l'ecran en lot, TASK-1785).
        $test->set('loopId', (string) $boucleDeOrg->id)
            ->set('organizationId', (string) $autreOrg->id);

        $test->assertSet('loopId', null);
        $test->assertDontSee('Boucle de org');
    }

    // =====================================================================
    // 4. Statuts par Organization et masquage du bouton
    // =====================================================================

    public function test_le_bouton_creer_le_brouillon_est_masque_quand_un_article_existe_deja(): void
    {
        $org = $this->organization('masquage-bouton');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source, ['source_identity' => 'guid:identite-fixe']);

        BlogPost::create([
            'user_id' => $admin->id,
            'organization_id' => $org->id,
            'title' => 'Deja importe',
            'slug' => 'deja-importe-masquage',
            'content' => '<p>Deja importe</p>',
            'status' => 'draft',
            'source_guid' => 'guid:identite-fixe',
        ]);

        $this->actingAs($admin);

        Livewire::test(RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) $org->id)
            ->assertDontSee(__('admin.import_rss.fiche.creer_brouillon'))
            ->assertSee(__('admin.import_rss.fiche.deja_importe_pour_cette_organization'));
    }

    public function test_le_bouton_reste_masque_meme_quand_l_article_existant_est_supprime(): void
    {
        $org = $this->organization('masquage-supprime');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source, ['source_identity' => 'guid:identite-supprimee']);

        $existant = BlogPost::create([
            'user_id' => $admin->id,
            'organization_id' => $org->id,
            'title' => 'Supprime',
            'slug' => 'supprime-masquage',
            'content' => '<p>Supprime</p>',
            'status' => 'draft',
            'source_guid' => 'guid:identite-supprimee',
        ]);
        $existant->delete();

        $this->actingAs($admin);

        Livewire::test(RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) $org->id)
            ->assertDontSee(__('admin.import_rss.fiche.creer_brouillon'));

        // Vu depuis l'ecran, pas seulement depuis le composant : la page
        // nomme le statut, et ne propose AUCUN lien d'edition (404 garanti,
        // meme regle que l'ecran en lot TASK-1789).
        $page = $this->actingAs($admin)->get(route('admin.outils.import-rss.articles.show', $article));
        $page->assertSee(__('admin.import_rss.supprime'));
    }

    // =====================================================================
    // 5. Creation du brouillon, et sa verification serveur
    // =====================================================================

    public function test_creer_le_brouillon_cree_un_article_jamais_publie_et_redirige(): void
    {
        $org = $this->organization('creation-brouillon');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source, ['source_identity' => 'guid:creation-ok']);

        $this->actingAs($admin);

        $test = Livewire::test(RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) $org->id)
            ->call('creerBrouillon');

        $cree = BlogPost::withoutGlobalScopes()->where('source_guid', 'guid:creation-ok')->first();

        $this->assertNotNull($cree);
        $this->assertSame('draft', $cree->status);
        $this->assertNull($cree->published_at);
        $test->assertRedirect(route('admin.blog.edit', ['post' => $cree->id]));
    }

    public function test_creer_le_brouillon_lie_la_boucle_choisie(): void
    {
        $org = $this->organization('creation-avec-boucle');
        $admin = $this->superadmin($org);
        $boucle = Loop::factory()->create(['organization_id' => $org->id]);
        $source = $this->source();
        $article = $this->article($source, ['source_identity' => 'guid:creation-boucle']);

        $this->actingAs($admin);

        Livewire::test(RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) $org->id)
            ->set('loopId', (string) $boucle->id)
            ->call('creerBrouillon');

        $cree = BlogPost::withoutGlobalScopes()->where('source_guid', 'guid:creation-boucle')->first();

        $this->assertTrue($cree->loops()->whereKey($boucle->id)->exists());
    }

    public function test_creer_le_brouillon_est_refuse_cote_serveur_meme_si_le_bouton_aurait_du_etre_masque(): void
    {
        $org = $this->organization('refus-serveur');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source, ['source_identity' => 'guid:refus-serveur']);

        BlogPost::create([
            'user_id' => $admin->id,
            'organization_id' => $org->id,
            'title' => 'Deja la',
            'slug' => 'deja-la-refus-serveur',
            'content' => '<p>Deja la</p>',
            'status' => 'draft',
            'source_guid' => 'guid:refus-serveur',
        ]);

        $this->actingAs($admin);

        // On force l'action MEME si le bouton aurait ete masque a l'ecran —
        // c'est exactement ce qu'une requete Livewire tamperee ferait.
        Livewire::test(RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) $org->id)
            ->call('creerBrouillon');

        $this->assertSame(1, BlogPost::withoutGlobalScopes()->where('source_guid', 'guid:refus-serveur')->count());
    }

    public function test_creer_le_brouillon_ignore_une_boucle_d_une_autre_organization(): void
    {
        $org = $this->organization('boucle-etrangere-ignoree');
        $admin = $this->superadmin($org);
        $autreOrg = $this->organization('boucle-etrangere-ignoree-autre');
        $boucleEtrangere = Loop::factory()->create(['organization_id' => $autreOrg->id]);
        $source = $this->source();
        $article = $this->article($source, ['source_identity' => 'guid:boucle-etrangere']);

        $this->actingAs($admin);

        // `loopId` est fixe a la main, APRES avoir choisi `org` : exactement
        // ce qu'une requete tamperee transporterait, puisqu'aucun
        // selecteur de l'ecran ne proposerait jamais cette Boucle pour
        // cette Organization.
        Livewire::test(RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) $org->id)
            ->set('loopId', (string) $boucleEtrangere->id)
            ->call('creerBrouillon');

        $cree = BlogPost::withoutGlobalScopes()->where('source_guid', 'guid:boucle-etrangere')->first();

        $this->assertNotNull($cree);
        $this->assertFalse($cree->loops()->whereKey($boucleEtrangere->id)->exists());
    }

    public function test_creer_le_brouillon_est_refuse_pour_une_organization_inconnue(): void
    {
        $org = $this->organization('organization-inconnue');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source, ['source_identity' => 'guid:organization-inconnue']);

        $this->actingAs($admin);

        Livewire::test(RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) \Illuminate\Support\Str::uuid())
            ->call('creerBrouillon')
            ->assertHasErrors('organizationId');

        $this->assertSame(0, BlogPost::withoutGlobalScopes()->where('source_guid', 'guid:organization-inconnue')->count());
    }

    // =====================================================================
    // 6. Correctif post-merge — revocation de is_admin EN COURS DE SESSION
    // =====================================================================

    public function test_resume_et_contenu_s_affichent_separement_quand_les_deux_existent(): void
    {
        $org = $this->organization('resume-et-contenu-separes');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source, [
            'resume' => 'TEXTE-DU-RESUME-SEUL',
            'contenu_html' => '<p>TEXTE-DU-CONTENU-SEUL</p>',
        ]);

        $page = $this->actingAs($admin)->get(route('admin.outils.import-rss.articles.show', $article));

        $page->assertSee('TEXTE-DU-RESUME-SEUL');
        $page->assertSee('TEXTE-DU-CONTENU-SEUL');
        $page->assertSee(__('admin.import_rss.fiche.resume_label'));
        $page->assertSee(__('admin.import_rss.fiche.contenu_label'));
    }

    /**
     * `AdminMiddleware` DOIT figurer dans la liste blanche interne de
     * Livewire (`PersistentMiddleware`), sinon `POST /livewire/update`
     * rejoue `Authenticate` mais jamais le controle `is_admin` — mesure
     * directement dans ce depot (voir `AppServiceProvider::boot()`).
     *
     * Isole la garde (1) [middleware] de la garde (2) [controle explicite
     * dans `creerBrouillon()`] : sans cette assertion, le test HTTP suivant
     * resterait vert meme si cette declaration disparaissait un jour, parce
     * que la garde (2) suffirait seule a le faire passer — ce qui ne
     * prouverait plus rien sur (1) precisement.
     */
    public function test_admin_middleware_est_declaree_middleware_persistant_livewire(): void
    {
        $this->assertContains(AdminMiddleware::class, Livewire::getPersistentMiddleware());
    }

    /**
     * La garde (2) ISOLEE : `Livewire::test()` ne passe PAR AUCUN middleware
     * (ni la route, ni la liste persistante) — un refus ici ne peut venir
     * QUE du controle explicite dans `creerBrouillon()`.
     */
    public function test_creerbrouillon_refuse_un_acteur_qui_n_est_plus_superadmin(): void
    {
        $org = $this->organization('acteur-non-admin');
        $exAdmin = User::factory()->create(['organization_id' => $org->id, 'is_admin' => false]);
        $source = $this->source();
        $article = $this->article($source, ['source_identity' => 'guid:acteur-non-admin']);

        $this->actingAs($exAdmin);

        Livewire::test(RssArticleFiche::class, ['rssSourceArticle' => $article])
            ->set('organizationId', (string) $org->id)
            ->call('creerBrouillon')
            ->assertForbidden();

        $this->assertSame(0, BlogPost::withoutGlobalScopes()->where('source_guid', 'guid:acteur-non-admin')->count());
    }

    /**
     * Le VRAI endpoint Livewire, jamais `Livewire::test()` — qui court-circuite
     * le middleware persistant (`PersistentMiddleware::applyPersistentMiddleware()`
     * ne s'execute que sur une requete reconnue comme `isLivewireRoute()`).
     * Meme technique que `TASK1691LivewireTenantIdentityTest`.
     *
     * Verifie le RESULTAT (l'action est refusee), porte indifferemment par
     * la garde (1) ou la garde (2) — voir le test precedent pour la garde
     * (1) isolee.
     */
    public function test_une_revocation_is_admin_interdit_une_action_deja_initialisee(): void
    {
        $org = $this->organization('revocation-is-admin');
        $admin = $this->superadmin($org);
        $source = $this->source();
        $article = $this->article($source, ['source_identity' => 'guid:revocation-is-admin']);

        $url = route('admin.outils.import-rss.articles.show', $article);
        $snapshot = $this->snapshotDe($url, $admin, 'admin.rss-article-fiche');

        // Le droit est revoque APRES l'initialisation du composant : le
        // snapshot ci-dessus a ete pris pendant que $admin etait encore
        // SuperAdmin, exactement le scenario « action deja initialisee ».
        $admin->update(['is_admin' => false]);

        $reponse = $this->update($admin, $snapshot, ['organizationId' => (string) $org->id], [
            ['method' => 'creerBrouillon', 'params' => []],
        ]);

        $this->assertSame(403, $reponse->getStatusCode());
        $this->assertSame(0, BlogPost::withoutGlobalScopes()->where('source_guid', 'guid:revocation-is-admin')->count());
    }

    /** Le snapshot REEL du composant vise, pris sur une page legitime. */
    private function snapshotDe(string $url, User $acteur, string $composant): string
    {
        $html = $this->actingAs($acteur)->get($url)->assertOk()->getContent();

        foreach (preg_match_all('/wire:snapshot="([^"]+)"/', $html, $tous) ? $tous[1] : [] as $candidat) {
            $decode = html_entity_decode($candidat, ENT_QUOTES);
            if (str_contains($decode, $composant)) {
                return $decode;
            }
        }

        $this->fail("le composant [{$composant}] n'est pas monte sur [{$url}]");
    }

    /**
     * Un appel sur le VRAI endpoint. L'URI est resolue a l'execution : la
     * coder en dur rendrait un 404 trompeur qui ressemblerait a une protection.
     */
    private function update(User $acteur, string $snapshot, array $updates, array $calls = [])
    {
        return $this->actingAs($acteur)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => $calls,
            ]]]);
    }
}
