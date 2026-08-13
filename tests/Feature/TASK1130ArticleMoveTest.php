<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Deplacer un Article d'un Dossier a un autre (TASK-1130).
 *
 * Un fichier se glissait deja d'un dossier a l'autre ; un Article, non — il
 * fallait le detacher puis le rattacher a la main. L'endpoint est le miroir de
 * `files/{file}/move`, et ces tests gardent ce qui le distingue d'un simple
 * `update` :
 *
 * - la ligne pivot est MODIFIEE, jamais detruite puis recreee : `added_by` doit
 *   survivre au voyage, et `dossier_blog_posts` porte un index unique sur
 *   `blog_post_id` qu'un detach/attach exposerait le temps d'une fenetre ;
 * - la position se recalcule dans la cible, sans quoi deux Articles pourraient
 *   partager la meme place ;
 * - une Serie du dossier source bloque le depart, parce que son moteur exige
 *   que son contenu vive dans SON Dossier ;
 * - les deux Dossiers voient leur index reconstruit, pas seulement la cible ;
 * - les refus (autre tenant, meme dossier, sans droit) repondent avant d'ecrire.
 */
class TASK1130ArticleMoveTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $auteur;

    private User $intrus;

    private Dossier $source;

    private Dossier $cible;

    private BlogPost $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->intrus = User::factory()->create(['organization_id' => $this->org->id]);

        $this->source = $this->dossier($this->org, $this->auteur, 'Source');
        $this->cible = $this->dossier($this->org, $this->auteur, 'Cible');
        $this->article = $this->blogPost($this->org, $this->auteur, 'Note de cadrage');

        app()->instance('current_organization', $this->org);
    }

    private function dossier(Organization $org, ?User $owner, string $nom): Dossier
    {
        return Dossier::create([
            'organization_id' => $org->id,
            'owner_id' => $owner?->id,
            'name' => $nom,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function blogPost(Organization $org, User $auteur, string $titre): BlogPost
    {
        return BlogPost::create([
            'organization_id' => $org->id,
            'user_id' => $auteur->id,
            'title' => $titre,
            'content' => "Contenu de {$titre}.",
            'status' => 'draft',
        ]);
    }

    private function attacher(Dossier $dossier, BlogPost $post, int $position = 1): DossierBlogPost
    {
        return DossierBlogPost::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $this->auteur->id,
            'position' => $position,
        ]);
    }

    private function deplacer(Dossier $source, BlogPost $post, string $cibleId, ?User $acteur = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($acteur ?? $this->auteur)->patchJson(
            route('organization.dossiers.articles.move', [
                'organization' => $this->org->slug,
                'dossier' => $source->getKey(),
                'post' => $post->getKey(),
            ]),
            ['target_dossier_id' => $cibleId],
        );
    }

    // ── Le chemin nominal ────────────────────────────────────────────────────

    public function test_an_article_moves_to_another_dossier(): void
    {
        $entree = $this->attacher($this->source, $this->article);

        $this->deplacer($this->source, $this->article, $this->cible->getKey())
            ->assertOk()
            ->assertJsonPath('article.dossier_id', $this->cible->getKey());

        $this->assertDatabaseHas('dossier_blog_posts', [
            'id' => $entree->getKey(),
            'dossier_id' => $this->cible->getKey(),
            'blog_post_id' => $this->article->getKey(),
        ]);
        $this->assertSame(0, DossierBlogPost::where('dossier_id', $this->source->getKey())->count());
    }

    public function test_the_pivot_row_is_updated_rather_than_recreated(): void
    {
        // `added_by` est la preuve : un detach + attach l'aurait remis a la
        // personne qui deplace, pas a celle qui a classe l'Article.
        $entree = $this->attacher($this->source, $this->article);
        $entree->update(['added_by' => $this->intrus->getKey()]);

        $this->deplacer($this->source, $this->article, $this->cible->getKey())->assertOk();

        $this->assertDatabaseHas('dossier_blog_posts', [
            'id' => $entree->getKey(),
            'added_by' => $this->intrus->getKey(),
        ]);
        $this->assertSame(1, DossierBlogPost::withoutGlobalScopes()->where('blog_post_id', $this->article->getKey())->count());
    }

    public function test_the_article_lands_at_the_end_of_the_target(): void
    {
        $this->attacher($this->source, $this->article);
        $this->attacher($this->cible, $this->blogPost($this->org, $this->auteur, 'Deja la'), 4);

        $this->deplacer($this->source, $this->article, $this->cible->getKey())->assertOk();

        $this->assertDatabaseHas('dossier_blog_posts', [
            'blog_post_id' => $this->article->getKey(),
            'dossier_id' => $this->cible->getKey(),
            'position' => 5,
        ]);
    }

    public function test_both_dossiers_are_reindexed(): void
    {
        Queue::fake();
        $this->attacher($this->source, $this->article);

        $this->deplacer($this->source, $this->article, $this->cible->getKey())->assertOk();

        // La source perd un contenu, la cible en gagne un : les deux index
        // mentiraient si un seul job partait.
        Queue::assertPushed(IndexDossierArticleChunks::class, 2);
        Queue::assertPushed(
            IndexDossierArticleChunks::class,
            fn (IndexDossierArticleChunks $job) => $job->dossierId === $this->source->getKey(),
        );
        Queue::assertPushed(
            IndexDossierArticleChunks::class,
            fn (IndexDossierArticleChunks $job) => $job->dossierId === $this->cible->getKey(),
        );
    }

    // ── Les refus ────────────────────────────────────────────────────────────

    public function test_moving_into_the_same_dossier_is_refused(): void
    {
        $this->attacher($this->source, $this->article);

        $this->deplacer($this->source, $this->article, $this->source->getKey())
            ->assertStatus(422);
    }

    public function test_a_target_in_another_organization_is_not_revealed(): void
    {
        $this->attacher($this->source, $this->article);
        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);
        $ailleurs = $this->dossier($this->autreOrg, $etranger, 'Ailleurs');

        // 404 et non 403 : repondre « interdit » confirmerait son existence.
        $this->deplacer($this->source, $this->article, $ailleurs->getKey())
            ->assertStatus(404);

        $this->assertDatabaseHas('dossier_blog_posts', [
            'blog_post_id' => $this->article->getKey(),
            'dossier_id' => $this->source->getKey(),
        ]);
    }

    public function test_a_missing_target_is_refused(): void
    {
        $this->attacher($this->source, $this->article);

        $this->deplacer($this->source, $this->article, (string) \Illuminate\Support\Str::uuid())
            ->assertStatus(404);
    }

    public function test_someone_else_cannot_move_the_article(): void
    {
        $this->attacher($this->source, $this->article);

        $this->deplacer($this->source, $this->article, $this->cible->getKey(), $this->intrus)
            ->assertStatus(403);

        $this->assertDatabaseHas('dossier_blog_posts', [
            'blog_post_id' => $this->article->getKey(),
            'dossier_id' => $this->source->getKey(),
        ]);
    }

    public function test_an_article_not_in_this_dossier_is_refused(): void
    {
        $this->attacher($this->cible, $this->article);

        $this->deplacer($this->source, $this->article, $this->cible->getKey())
            ->assertStatus(404);
    }

    // ── Les Series : on refuse plutot que d'amputer une sequence ─────────────

    public function test_a_series_root_cannot_leave_its_dossier(): void
    {
        $this->attacher($this->source, $this->article);
        ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->source->id,
            'root_blog_post_id' => $this->article->id,
            'created_by' => $this->auteur->id,
        ]);

        $this->deplacer($this->source, $this->article, $this->cible->getKey())
            ->assertStatus(422)
            ->assertJsonPath('message', __('dossiers.article_move_series_refused'));

        $this->assertDatabaseHas('dossier_blog_posts', [
            'blog_post_id' => $this->article->getKey(),
            'dossier_id' => $this->source->getKey(),
        ]);
    }

    public function test_a_series_item_cannot_leave_its_dossier(): void
    {
        $racine = $this->blogPost($this->org, $this->auteur, 'Racine');
        $this->attacher($this->source, $racine, 1);
        $this->attacher($this->source, $this->article, 2);

        $serie = ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->source->id,
            'root_blog_post_id' => $racine->id,
            'created_by' => $this->auteur->id,
        ]);
        ArticleSeriesItem::create([
            'organization_id' => $this->org->id,
            'article_series_id' => $serie->id,
            'blog_post_id' => $this->article->id,
            'position' => 1,
        ]);

        $this->deplacer($this->source, $this->article, $this->cible->getKey())
            ->assertStatus(422);
    }

    public function test_an_article_free_of_any_series_still_moves(): void
    {
        // Une Serie existe dans le dossier, mais celui-ci n'en fait pas partie :
        // le garde doit viser l'Article, pas le Dossier.
        $racine = $this->blogPost($this->org, $this->auteur, 'Racine');
        $this->attacher($this->source, $racine, 1);
        $this->attacher($this->source, $this->article, 2);

        ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->source->id,
            'root_blog_post_id' => $racine->id,
            'created_by' => $this->auteur->id,
        ]);

        $this->deplacer($this->source, $this->article, $this->cible->getKey())->assertOk();
    }
}
