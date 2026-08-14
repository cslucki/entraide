<?php

namespace Tests\Feature;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les Cards de l'editeur passent en barre de boutons, et les series se
 * completent.
 *
 * Deux choses sont defendues ici plus que le reste : le pop-up Dossier doit
 * connaitre la serie entiere, pas seulement le titre de sa racine ; et designer
 * une racine ne doit jamais laisser un article a la fois racine et annexe.
 */
class TASK1085EditorPanelsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create();
        $this->org = Organization::factory()->create([
            'is_active' => true, 'admin_id' => $this->author->id,
        ]);
        $this->author->update(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function article(string $title): BlogPost
    {
        return BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->org->id,
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title).'-'.uniqid(),
            'content' => '<p>Contenu</p>',
            'status' => 'draft',
        ]);
    }

    private function dossier(): Dossier
    {
        return Dossier::factory()->create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->author->id,
        ]);
    }

    private function attach(Dossier $dossier, BlogPost $post, int $position = 0): DossierBlogPost
    {
        return DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'position' => $position,
            'added_by' => $this->author->id,
        ]);
    }

    private function currentDossierUrl(BlogPost $post): string
    {
        return route('organization.blog.dossier.current', [
            'organization' => $this->org->slug,
            'post' => $post->slug,
        ]);
    }

    private function seriesUrl(Dossier $dossier): string
    {
        return route('organization.dossiers.series.store', [
            'organization' => $this->org->slug,
            'dossier' => $dossier->id,
        ]);
    }

    // ── Le pop-up Dossier connait la serie ──────────────────────────────────

    public function test_the_dossier_payload_carries_a_link_to_the_dossier(): void
    {
        $dossier = $this->dossier();
        $post = $this->article('Un article');
        $this->attach($dossier, $post);

        $payload = $this->actingAs($this->author)
            ->getJson($this->currentDossierUrl($post))
            ->assertOk()
            ->json('dossier');

        $this->assertSame($dossier->name, $payload['name']);
        $this->assertStringContainsString($dossier->id, $payload['url']);
    }

    public function test_an_article_outside_any_dossier_says_so(): void
    {
        $post = $this->article('Orphelin');

        $this->actingAs($this->author)
            ->getJson($this->currentDossierUrl($post))
            ->assertOk()
            ->assertJson(['dossier' => null]);
    }

    public function test_the_payload_lists_the_whole_series_root_first(): void
    {
        $dossier = $this->dossier();
        $root = $this->article('La racine');
        $second = $this->article('Deuxieme');
        $third = $this->article('Troisieme');

        foreach ([$root, $second, $third] as $i => $p) {
            $this->attach($dossier, $p, $i);
        }

        $series = ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->author->id,
        ]);

        foreach ([$second, $third] as $i => $p) {
            ArticleSeriesItem::create([
                'organization_id' => $this->org->id,
                'article_series_id' => $series->id,
                'blog_post_id' => $p->id,
                'position' => $i,
                'added_by' => $this->author->id,
            ]);
        }

        $payload = $this->actingAs($this->author)
            ->getJson($this->currentDossierUrl($second))
            ->assertOk()
            ->json('dossier.series');

        $this->assertSame(['La racine', 'Deuxieme', 'Troisieme'], array_column($payload['articles'], 'title'));
        $this->assertTrue($payload['articles'][0]['is_root']);
        // L'article courant est signale : il ne sera pas un lien.
        $this->assertTrue($payload['articles'][1]['is_current']);
        $this->assertFalse($payload['articles'][0]['is_current']);
        $this->assertFalse($payload['is_root']);
    }

    public function test_an_article_of_the_dossier_but_outside_the_series_has_no_series_block(): void
    {
        $dossier = $this->dossier();
        $root = $this->article('La racine');
        $aside = $this->article('A cote');
        $this->attach($dossier, $root, 0);
        $this->attach($dossier, $aside, 1);

        ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->author->id,
        ]);

        $this->actingAs($this->author)
            ->getJson($this->currentDossierUrl($aside))
            ->assertOk()
            ->assertJsonPath('dossier.series', null);
    }

    // ── Designer une racine ─────────────────────────────────────────────────

    public function test_creating_a_series_designates_a_root(): void
    {
        $dossier = $this->dossier();
        $post = $this->article('Le premier');
        $this->attach($dossier, $post);

        $this->actingAs($this->author)
            ->postJson($this->seriesUrl($dossier), ['root_blog_post_id' => $post->id])
            ->assertOk();

        $this->assertDatabaseHas('article_series', [
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $post->id,
        ]);
    }

    public function test_promoting_an_annexe_never_leaves_it_root_and_annexe_at_once(): void
    {
        // Le defaut trouve en recette : un depot sur la racine promouvait
        // l'article *et* le rajoutait en annexe.
        $dossier = $this->dossier();
        $root = $this->article('Ancienne racine');
        $annexe = $this->article('Annexe promue');
        $this->attach($dossier, $root, 0);
        $this->attach($dossier, $annexe, 1);

        $series = ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->author->id,
        ]);
        ArticleSeriesItem::create([
            'organization_id' => $this->org->id,
            'article_series_id' => $series->id,
            'blog_post_id' => $annexe->id,
            'position' => 0,
            'added_by' => $this->author->id,
        ]);

        $this->actingAs($this->author)
            ->patchJson(route('organization.dossiers.series.update', [
                'organization' => $this->org->slug,
                'dossier' => $dossier->id,
            ]), ['root_blog_post_id' => $annexe->id])
            ->assertOk();

        $this->assertSame($annexe->id, $series->fresh()->root_blog_post_id);
        $this->assertDatabaseMissing('article_series_items', [
            'article_series_id' => $series->id,
            'blog_post_id' => $annexe->id,
        ]);
        // Rien n'est perdu : l'ancienne racine devient annexe.
        $this->assertDatabaseHas('article_series_items', [
            'article_series_id' => $series->id,
            'blog_post_id' => $root->id,
        ]);
    }

    public function test_an_article_outside_the_dossier_cannot_become_its_root(): void
    {
        $dossier = $this->dossier();
        $inside = $this->article('Dedans');
        $outside = $this->article('Dehors');
        $this->attach($dossier, $inside);

        $this->actingAs($this->author)
            // 404 et non 422 : l'article n'est pas « invalide », il est
            // introuvable dans ce Dossier.
            ->postJson($this->seriesUrl($dossier), ['root_blog_post_id' => $outside->id])
            ->assertStatus(404);

        $this->assertDatabaseMissing('article_series', ['dossier_id' => $dossier->id]);
    }

    // ── Les ecrans ──────────────────────────────────────────────────────────

    public function test_the_editor_offers_the_four_cards_as_a_button_bar(): void
    {
        $post = $this->article('En cours');

        $html = $this->actingAs($this->author)
            ->get(route('organization.blog.edit', [
                'organization' => $this->org->slug, 'post' => $post->slug,
            ]))->assertOk()->getContent();

        foreach (['boucle', 'dossier', 'todo', 'coauthors', 'snapshot'] as $panel) {
            $this->assertStringContainsString("editorPanels.toggle('".$panel."')", $html,
                "Le pop-up « {$panel} » n'a pas de commande d'ouverture.");
        }

        // Le titre disparait : on devine qu'on edite.
        $this->assertStringNotContainsString('<h1>'.__('blog.heading_edit').'</h1>', $html);
    }

    public function test_the_dossier_screen_offers_creating_a_series(): void
    {
        // TASK-1130 (doctrine finale) : la creation vit dans le mode Serie
        // (selecteur « Serie : … ▾ » et etat vide), et la racine se designe
        // ligne par ligne — plus de panneau Contenus ni de modal dedie.
        $dossier = $this->dossier();

        $this->actingAs($this->author)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->org->slug, 'dossier' => $dossier->id,
            ]))
            ->assertOk()
            ->assertSee(e(__('dossiers.series_mode_create')), false)
            ->assertSee(e(__('dossiers.content_set_root')), false);
    }

    public function test_no_native_confirmation_is_used_for_the_panels(): void
    {
        $post = $this->article('En cours');

        $html = $this->actingAs($this->author)
            ->get(route('organization.blog.edit', [
                'organization' => $this->org->slug, 'post' => $post->slug,
            ]))->assertOk()->getContent();

        // Le bouton Snapshot vit dans le <form> de l'article : sans type, il le
        // soumettrait.
        $this->assertStringContainsString(
            '<button type="button" @click="$store.editorPanels.toggle(\'snapshot\')"',
            $html
        );
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_a_dossier_of_another_organization_is_out_of_reach(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $dossier = $this->dossier();
        $post = $this->article('Interne');
        $this->attach($dossier, $post);

        $this->actingAs($stranger)
            ->postJson($this->seriesUrl($dossier), ['root_blog_post_id' => $post->id])
            ->assertForbidden();
    }
}
