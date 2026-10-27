<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1695 (bug 6 de Cyril) — retirer d'un Dossier de Boucle un Article
 * qu'on n'a pas écrit.
 *
 * ## Ce qui était mesuré sur la Boucle `ofsh`
 *
 * Son propriétaire passe `update`, `attachArticle`, `manageFiles` et
 * `deleteFile` sur le Dossier — il le gouverne entièrement. Et il ne pouvait
 * pas en retirer « Cadre du dialogue », semé par `admin@bouclepro.test` :
 * `ensureUserOwnsBlogPost()` exigeait d'être l'**auteur**.
 *
 * La garde confondait deux questions : qui peut POSER un Article ici (son
 * auteur — et cela ne change pas), et qui peut en RETIRER un (qui tient le
 * Dossier). Détacher ne supprime pas l'Article : il survit au Blog.
 *
 * ## Le second volet
 *
 * L'Article était le **document racine** de la Boucle. `designate()` pose deux
 * pointeurs et n'a pas d'inverse : sans libération, le détacher laissait la
 * Boucle désigner un document absent de son Dossier.
 */
class TASK1695DetachLoopRootArticleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private User $autreAuteur;

    private Loop $loop;

    private Dossier $racine;

    private BlogPost $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1695d6', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        // L'auteur n'est PAS le propriétaire de la Boucle : c'est tout le sujet.
        $this->autreAuteur = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->loop = (new LoopService)->createLoop($this->proprietaire, 'Boucle bug 6', type: 'project');
        $this->racine = Dossier::query()->where('loop_id', $this->loop->id)->firstOrFail();

        // Pas de factory pour BlogPost dans ce depot : les tests voisins le
        // creent a la main (TASK-1693, TASK-1247). Meme idiome.
        $this->article = $this->article('Cadre du dialogue 1695', 'cadre-du-dialogue-1695');

        // Le MÊME chemin que la production : il pose les deux pointeurs.
        app(LoopRootDocumentService::class)->designate($this->loop->fresh(), $this->article);
    }

    private function article(string $titre, string $slug): BlogPost
    {
        return BlogPost::create([
            'user_id' => $this->autreAuteur->id,
            'organization_id' => $this->organization->id,
            'title' => $titre,
            'slug' => $slug,
            'content' => '<p>Contenu.</p>',
            'summary' => 'Resume',
            'status' => 'draft',
        ]);
    }

    private function detacher(User $qui)
    {
        return $this->actingAs($qui)->deleteJson(route('organization.dossiers.articles.destroy', [
            'organization' => $this->organization->slug,
            'dossier' => $this->racine->id,
            'post' => $this->article->id,
        ]));
    }

    /** LE bug : le propriétaire de la Boucle retire l'Article d'un autre. */
    public function test_the_loop_owner_can_detach_an_article_he_did_not_write(): void
    {
        $this->assertNotSame($this->proprietaire->id, $this->article->user_id);

        $this->detacher($this->proprietaire)->assertOk();

        $this->assertDatabaseMissing('dossier_blog_posts', [
            'dossier_id' => $this->racine->id,
            'blog_post_id' => $this->article->id,
        ]);
    }

    /**
     * Détacher ne SUPPRIME pas l'Article.
     *
     * C'est ce qui rend le geste sûr : l'Article survit au Blog, et son auteur
     * peut le rattacher. Sans cette assertion, transformer le détachement en
     * suppression resterait vert.
     */
    public function test_detaching_never_deletes_the_article(): void
    {
        $this->detacher($this->proprietaire)->assertOk();

        $this->assertDatabaseHas('blog_posts', [
            'id' => $this->article->id,
            'user_id' => $this->autreAuteur->id,
        ]);
    }

    /**
     * Les DEUX pointeurs de document racine sont libérés.
     *
     * `designate()` les pose ensemble et n'a pas d'inverse. Les laisser ferait
     * désigner par la Boucle un document qui n'est plus dans son Dossier — la
     * référence pendante à l'origine du blocage de Cyril.
     */
    public function test_both_root_pointers_are_released(): void
    {
        $this->assertSame($this->article->id, $this->racine->fresh()->root_blog_post_id);
        $this->assertSame($this->article->id, $this->loop->fresh()->manifesto_blog_post_id);

        $this->detacher($this->proprietaire)->assertOk();

        $this->assertNull($this->racine->fresh()->root_blog_post_id);
        $this->assertNull($this->loop->fresh()->manifesto_blog_post_id);
    }

    /**
     * Un Article ORDINAIRE se détache sans toucher aux pointeurs.
     *
     * Sans cette assertion, libérer inconditionnellement effacerait le
     * document racine de la Boucle en retirant n'importe quel autre Article.
     */
    public function test_detaching_an_ordinary_article_leaves_the_root_alone(): void
    {
        $ordinaire = $this->article('Article ordinaire 1695', 'article-ordinaire-1695');
        DossierBlogPost::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->racine->id,
            'blog_post_id' => $ordinaire->id,
            'position' => 1,
        ]);

        $this->actingAs($this->proprietaire)
            ->deleteJson(route('organization.dossiers.articles.destroy', [
                'organization' => $this->organization->slug,
                'dossier' => $this->racine->id,
                'post' => $ordinaire->id,
            ]))
            ->assertOk();

        $this->assertSame($this->article->id, $this->racine->fresh()->root_blog_post_id);
        $this->assertSame($this->article->id, $this->loop->fresh()->manifesto_blog_post_id);
    }

    /**
     * Un simple MEMBRE de la Boucle ne détache pas.
     *
     * L'ouverture porte sur `detachArticle` — propriétaire ou éditeur du
     * Dossier, ou la permission `dossiers.create_article` pour une Boucle. Sans
     * cette assertion, supprimer la garde entière resterait vert.
     */
    public function test_a_plain_member_still_cannot_detach(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->organization->id]);
        (new LoopService)->addMember($this->loop->fresh(), $membre, 'member');

        $this->detacher($membre)->assertForbidden();

        $this->assertDatabaseHas('dossier_blog_posts', [
            'dossier_id' => $this->racine->id,
            'blog_post_id' => $this->article->id,
        ]);
    }

    /** Et un étranger à la Boucle encore moins. */
    public function test_a_stranger_cannot_detach(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->detacher($etranger)->assertForbidden();
    }

    /**
     * L'AUTEUR peut toujours détacher le sien.
     *
     * Le correctif élargit ; il ne doit rien retirer. Ici l'auteur n'est pas
     * membre de la Boucle — il passe par son propre Dossier.
     */
    public function test_the_author_can_still_detach_from_his_own_dossier(): void
    {
        $sien = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->autreAuteur->id,
            'visibility' => 'private',
        ]);
        $billet = $this->article('Billet de l auteur 1695', 'billet-auteur-1695');
        DossierBlogPost::query()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $sien->id,
            'blog_post_id' => $billet->id,
            'position' => 0,
        ]);

        $this->actingAs($this->autreAuteur)
            ->deleteJson(route('organization.dossiers.articles.destroy', [
                'organization' => $this->organization->slug,
                'dossier' => $sien->id,
                'post' => $billet->id,
            ]))
            ->assertOk();
    }
}
