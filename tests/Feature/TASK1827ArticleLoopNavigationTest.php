<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Blog\LoopArticleLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1827 — navigation reciproque Articles <-> Boucles.
 *
 * Reutilise exclusivement les deux chemins deja ecrits par
 * `BlogPost::linkedLoops()` (TASK-1826) : pivot `blog_post_loop` et Dossier
 * racine d'une Boucle. Aucun nouveau systeme de relation.
 */
class TASK1827ArticleLoopNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_public' => true,
            'is_active' => true,
            'loops_enabled' => true,
        ]);
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'approved_at' => now(),
        ]);

        app()->instance('current_organization', $this->organization);
    }

    private function loop(array $surcharges = []): Loop
    {
        $loop = (new LoopService)->createLoop($this->owner, 'Boucle '.uniqid(), null, $surcharges['visibility'] ?? 'private');

        return $loop;
    }

    private function rootDossier(Loop $loop): Dossier
    {
        return Dossier::firstOrCreate(
            ['loop_id' => $loop->id],
            ['organization_id' => $this->organization->id, 'owner_id' => null, 'name' => 'Racine']
        );
    }

    /** Aucune factory pour BlogPost dans ce depot : creation directe, meme idiome que TASK1826LoopContentPreviewsTest. */
    private function article(array $surcharges = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'organization_id' => $this->organization->id,
            'user_id' => $this->owner->id,
            'title' => 'Article de test '.uniqid(),
            'slug' => 'article-test-'.uniqid(),
            'content' => '<p>Contenu de test.</p>',
            'status' => 'published',
            'published_at' => now(),
            'listed_in_blog' => true,
        ], $surcharges));
    }

    private function sameOrgNonMember(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'approved_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // 1. Article sans boucle
    // -----------------------------------------------------------------

    public function test_un_article_sans_boucle_ne_montre_rien(): void
    {
        $post = $this->article();

        $links = app(LoopArticleLinks::class)->forPost($post, $this->organization);

        $this->assertTrue($links->isEmpty());

        $this->actingAs($this->owner)
            ->get(route('organization.blog.show', ['organization' => $this->organization->slug, 'post' => $post]))
            ->assertOk()
            ->assertDontSee('data-marqueur="partage-boucle"', false);
    }

    // -----------------------------------------------------------------
    // 2. Article lie par pivot
    // -----------------------------------------------------------------

    public function test_un_article_lie_par_pivot_montre_la_banniere_et_le_badge(): void
    {
        $loop = $this->loop();
        $post = $this->article();
        $post->loops()->attach($loop->id);

        $viewer = $this->sameOrgNonMember();

        $this->actingAs($viewer)
            ->get(route('organization.blog.show', ['organization' => $this->organization->slug, 'post' => $post]))
            ->assertOk()
            ->assertSee($loop->name, false)
            ->assertSee(__('blog.shared_in_loop_open'), false);

        $this->actingAs($viewer)
            ->get(route('organization.blog.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->assertSee(__('loops.articles_badge'), false);
    }

    // -----------------------------------------------------------------
    // 3. Article lie par Dossier racine
    // -----------------------------------------------------------------

    public function test_un_article_lie_par_dossier_racine_montre_la_banniere(): void
    {
        $loop = $this->loop();
        $dossier = $this->rootDossier($loop);
        $post = $this->article();
        DossierBlogPost::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->owner->id,
            'position' => 1,
        ]);

        $viewer = $this->sameOrgNonMember();

        $this->actingAs($viewer)
            ->get(route('organization.blog.show', ['organization' => $this->organization->slug, 'post' => $post]))
            ->assertOk()
            ->assertSee($loop->name, false);

        $links = app(LoopArticleLinks::class)->forPost($post->fresh(), $this->organization);
        $this->assertCount(1, $links);
        $this->assertSame($loop->id, $links->first()->id);
    }

    // -----------------------------------------------------------------
    // 4. Article associe a plusieurs boucles
    // -----------------------------------------------------------------

    public function test_un_article_associe_a_plusieurs_boucles_propose_un_selecteur(): void
    {
        $loopA = $this->loop();
        $loopB = $this->loop();
        $post = $this->article();
        $post->loops()->attach([$loopA->id, $loopB->id]);

        $viewer = $this->sameOrgNonMember();

        $response = $this->actingAs($viewer)
            ->get(route('organization.blog.show', ['organization' => $this->organization->slug, 'post' => $post]))
            ->assertOk();

        $response->assertSee(__('blog.shared_in_loop_intro_multiple'), false);
        $response->assertSee($loopA->name, false);
        $response->assertSee($loopB->name, false);
    }

    // -----------------------------------------------------------------
    // 5. Boucle privee / non-membre : degrade vers la presentation, jamais un 404
    // -----------------------------------------------------------------

    public function test_un_non_membre_de_la_boucle_privee_voit_le_lien_et_atterrit_sur_la_presentation(): void
    {
        $loop = $this->loop(['visibility' => 'private']);
        $post = $this->article();
        $post->loops()->attach($loop->id);

        $viewer = $this->sameOrgNonMember();
        $this->assertFalse(
            LoopMember::where('loop_id', $loop->id)->where('user_id', $viewer->id)->exists(),
            'ce scenario exige explicitement un non-membre'
        );

        $this->actingAs($viewer)
            ->get(route('organization.blog.show', ['organization' => $this->organization->slug, 'post' => $post]))
            ->assertOk()
            ->assertSee($loop->name, false);

        // Le CTA mene a la MEME route que pour un membre : c'est
        // `LoopController::show()` qui degrade deja vers la presentation
        // (TASK-1075) — on ne cree pas une 2e route de presentation.
        $this->actingAs($viewer)
            ->get(route('organization.loops.show', ['organization' => $this->organization->slug, 'loop' => $loop->slug ?: $loop->id]))
            ->assertOk()
            ->assertDontSee('data-loop-workspace', false);
    }

    // -----------------------------------------------------------------
    // 6. Article non publie : jamais de banniere, jamais au catalogue
    // -----------------------------------------------------------------

    public function test_un_article_non_publie_ne_montre_jamais_la_banniere_ni_le_catalogue(): void
    {
        $loop = $this->loop();
        $post = $this->article(['status' => 'draft', 'published_at' => null, 'listed_in_blog' => false]);
        $post->loops()->attach($loop->id);

        $catalogIds = app(LoopArticleLinks::class)->catalogQuery($this->organization)->pluck('id');
        $this->assertNotContains($post->id, $catalogIds);

        $this->actingAs($this->owner)
            ->get(route('organization.loops.articles', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->assertDontSee($post->title, false);
    }

    // -----------------------------------------------------------------
    // 7. Organization differente : isolation totale
    // -----------------------------------------------------------------

    public function test_un_visiteur_d_une_autre_organization_ne_voit_jamais_la_boucle(): void
    {
        $loop = $this->loop();
        $post = $this->article();
        $post->loops()->attach($loop->id);

        $otherOrg = Organization::factory()->create(['is_public' => true, 'is_active' => true]);
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id, 'approved_at' => now()]);

        $links = app(LoopArticleLinks::class)->forPost($post, $this->organization);
        $this->assertCount(1, $links, 'le lien existe bien cote donnees');

        // L'article d'une AUTRE Organization est de toute facon hors de
        // portee (`BlogController::show()` 404 avant meme d'atteindre la
        // banniere) : le test porte sur l'invariant lui-meme, pas sur ce 404
        // deja couvert ailleurs.
        $this->actingAs($otherUser)
            ->get(route('organization.blog.show', ['organization' => $this->organization->slug, 'post' => $post]))
            ->assertNotFound();
    }

    public function test_un_lecteur_sans_organization_commune_ne_recoit_jamais_de_lien_boucle(): void
    {
        $loop = $this->loop();
        $post = $this->article();
        $post->loops()->attach($loop->id);

        // Un visiteur non authentifie n'a aucun chemin existant vers le
        // catalogue des Boucles : lui montrer un nom de Boucle serait une
        // fuite neuve. La route org-scopee exige deja `auth` (302 vers
        // login) : c'est la route LEGACY publique qui expose reellement ce
        // cas, `organization.*` n'etant pas dans la route.
        $response = $this->get(route('blog.show', ['post' => $post]));
        $response->assertOk();
        $response->assertDontSee($loop->name, false);
    }

    // -----------------------------------------------------------------
    // 8. Pagination sans doublon
    // -----------------------------------------------------------------

    public function test_un_article_lie_a_deux_boucles_n_apparait_qu_une_fois_au_catalogue(): void
    {
        $loopA = $this->loop();
        $loopB = $this->loop();
        $post = $this->article();
        $post->loops()->attach([$loopA->id, $loopB->id]);

        $ids = app(LoopArticleLinks::class)->catalogQuery($this->organization)->pluck('id');

        $this->assertSame(1, $ids->filter(fn ($id) => $id === $post->id)->count());

        $this->actingAs($this->owner)
            ->get(route('organization.loops.articles', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->assertSeeInOrder([$post->title], false);
    }

    // -----------------------------------------------------------------
    // 9. Route directe et filtre manipule
    // -----------------------------------------------------------------

    public function test_un_filtre_loop_d_une_autre_organization_est_silencieusement_ignore(): void
    {
        $loop = $this->loop();
        $post = $this->article();
        $post->loops()->attach($loop->id);

        $otherOrg = Organization::factory()->create(['is_public' => true, 'is_active' => true]);
        $otherOwner = User::factory()->create(['organization_id' => $otherOrg->id, 'approved_at' => now()]);
        $otherLoop = (new LoopService)->createLoop($otherOwner, 'Boucle etrangere');

        $withForeignFilter = $this->actingAs($this->owner)
            ->get(route('organization.loops.articles', [
                'organization' => $this->organization->slug,
                'loop' => $otherLoop->id,
            ]))
            ->assertOk();

        // Jamais d'erreur, jamais un resultat distinct de l'absence de
        // filtre : aucun oracle sur l'existence d'une Boucle etrangere.
        $withForeignFilter->assertSee($post->title, false);

        $catalogQuery = app(LoopArticleLinks::class)->catalogQuery($this->organization, $otherLoop->id);
        $this->assertSame(
            app(LoopArticleLinks::class)->catalogQuery($this->organization)->pluck('id')->sort()->values()->all(),
            $catalogQuery->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_une_boucle_archivee_n_est_jamais_proposee_ni_montree(): void
    {
        $loop = $this->loop();
        $post = $this->article();
        $post->loops()->attach($loop->id);
        $loop->update(['status' => 'archived']);

        $links = app(LoopArticleLinks::class)->forPost($post->fresh(), $this->organization);
        $this->assertTrue($links->isEmpty(), 'une Boucle archivee n\'est independamment decouvrable nulle part (LoopController::show)');

        $ids = app(LoopArticleLinks::class)->catalogQuery($this->organization)->pluck('id');
        $this->assertNotContains($post->id, $ids);
    }

    // -----------------------------------------------------------------
    // 10. FR/EN
    // -----------------------------------------------------------------

    public function test_les_traductions_fr_et_en_existent(): void
    {
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('blog.shared_in_loop_intro', __('blog.shared_in_loop_intro', ['loop' => 'X']));
            $this->assertNotSame('loops.articles_title', __('loops.articles_title'));
            $this->assertNotSame('loops.articles_badge', __('loops.articles_badge'));
            $this->assertNotSame('loops.articles_nav_link', __('loops.articles_nav_link'));
        }
        app()->setLocale('fr');
    }

    // -----------------------------------------------------------------
    // Securite complementaire : mode mono-boucle, feature desactivee
    // -----------------------------------------------------------------

    public function test_le_catalogue_404_si_la_fonctionnalite_boucles_est_desactivee(): void
    {
        $this->organization->update(['loops_enabled' => false]);

        $this->actingAs($this->owner)
            ->get(route('organization.loops.articles', ['organization' => $this->organization->slug]))
            ->assertNotFound();
    }

    public function test_aucun_badge_n_apparait_si_la_fonctionnalite_boucles_est_desactivee(): void
    {
        $loop = $this->loop();
        $post = $this->article();
        $post->loops()->attach($loop->id);

        $this->organization->update(['loops_enabled' => false]);

        $this->actingAs($this->owner)
            ->get(route('organization.blog.show', ['organization' => $this->organization->slug, 'post' => $post]))
            ->assertOk()
            ->assertDontSee($loop->name, false);
    }
}
