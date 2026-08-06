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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La navigation d'un Article a l'autre dans une Serie.
 *
 * Le precedent et le suivant sont **deduits** de la liste ordonnee que le
 * pop-up Dossier construisait deja — racine d'abord, puis les annexes a leur
 * position persistee. Ces tests verifient cette deduction aux endroits ou elle
 * peut se tromper : les deux bouts, une Serie d'un seul Article, et surtout
 * apres une promotion, qui renumerote tout.
 */
class TASK1092SeriesNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private Organization $org;

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

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function article(string $title): BlogPost
    {
        return BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->org->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
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

    private function attach(Dossier $dossier, BlogPost $post, int $position = 0): void
    {
        DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'position' => $position,
            'added_by' => $this->author->id,
        ]);
    }

    /**
     * Une Serie : une racine et N annexes, dans l'ordre donne.
     *
     * @param  array<int, string>  $annexTitles
     * @return array{0: Dossier, 1: ArticleSeries, 2: BlogPost, 3: array<int, BlogPost>}
     */
    private function series(string $rootTitle, array $annexTitles): array
    {
        $dossier = $this->dossier();

        $root = $this->article($rootTitle);
        $this->attach($dossier, $root, 0);

        $series = ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'root_blog_post_id' => $root->id,
            'created_by' => $this->author->id,
        ]);

        $annexes = [];

        foreach (array_values($annexTitles) as $i => $title) {
            $post = $this->article($title);
            $this->attach($dossier, $post, $i + 1);

            ArticleSeriesItem::create([
                'organization_id' => $this->org->id,
                'article_series_id' => $series->id,
                'blog_post_id' => $post->id,
                'position' => $i,
                'added_by' => $this->author->id,
            ]);

            $annexes[] = $post;
        }

        return [$dossier, $series, $root, $annexes];
    }

    /** @return array<string, mixed>|null */
    private function seriesPayloadFor(BlogPost $post): ?array
    {
        return $this->actingAs($this->author)
            ->getJson(route('organization.blog.dossier.current', [
                'organization' => $this->org->slug,
                'post' => $post->slug,
            ]))
            ->assertOk()
            ->json('dossier.series');
    }

    // ── Hors Serie ──────────────────────────────────────────────────────────

    public function test_an_article_outside_any_series_gets_no_navigation(): void
    {
        $dossier = $this->dossier();
        $post = $this->article('Seul au monde');
        $this->attach($dossier, $post);

        $this->assertNull($this->seriesPayloadFor($post));
    }

    public function test_an_article_of_the_dossier_but_not_of_its_series_gets_no_navigation(): void
    {
        // Le Dossier a une Serie, mais cet Article n'en fait pas partie : il ne
        // doit voir ni voisins ni rang.
        [$dossier] = $this->series('Racine', ['Annexe']);

        $outsider = $this->article('Range mais hors Serie');
        $this->attach($dossier, $outsider, 9);

        $this->assertNull($this->seriesPayloadFor($outsider));
    }

    // ── Les deux bouts ──────────────────────────────────────────────────────

    public function test_the_root_has_no_previous_and_the_first_annex_as_next(): void
    {
        [, , $root, $annexes] = $this->series('Racine', ['Premiere', 'Deuxieme']);

        $payload = $this->seriesPayloadFor($root);

        $this->assertTrue($payload['is_root']);
        $this->assertNull($payload['previous']);
        $this->assertSame($annexes[0]->id, $payload['next']['id']);
        $this->assertSame(1, $payload['position']);
        $this->assertSame(3, $payload['total']);
    }

    public function test_a_middle_annex_has_both_neighbours(): void
    {
        [, , $root, $annexes] = $this->series('Racine', ['Premiere', 'Deuxieme', 'Troisieme']);

        $payload = $this->seriesPayloadFor($annexes[1]);

        $this->assertFalse($payload['is_root']);
        $this->assertSame($annexes[0]->id, $payload['previous']['id']);
        $this->assertSame($annexes[2]->id, $payload['next']['id']);
        $this->assertSame(3, $payload['position']);
    }

    public function test_the_first_annex_has_the_root_as_previous(): void
    {
        [, , $root, $annexes] = $this->series('Racine', ['Premiere', 'Deuxieme']);

        $payload = $this->seriesPayloadFor($annexes[0]);

        $this->assertSame($root->id, $payload['previous']['id']);
        $this->assertSame($annexes[1]->id, $payload['next']['id']);
    }

    public function test_the_last_article_has_no_next(): void
    {
        [, , , $annexes] = $this->series('Racine', ['Premiere', 'Derniere']);

        $payload = $this->seriesPayloadFor($annexes[1]);

        $this->assertNotNull($payload['previous']);
        $this->assertNull($payload['next']);
    }

    public function test_a_series_reduced_to_its_root_has_no_neighbour_at_all(): void
    {
        [, , $root] = $this->series('Toute seule', []);

        $payload = $this->seriesPayloadFor($root);

        $this->assertNull($payload['previous']);
        $this->assertNull($payload['next']);
        $this->assertSame(1, $payload['total']);
    }

    // ── L'ordre persiste, la navigation le suit ─────────────────────────────

    public function test_the_navigation_follows_the_persisted_order(): void
    {
        [, $series, $root, $annexes] = $this->series('Racine', ['A', 'B']);

        // On inverse les positions : la navigation doit suivre, sans que rien
        // d'autre ne bouge.
        ArticleSeriesItem::where('article_series_id', $series->id)
            ->where('blog_post_id', $annexes[0]->id)->update(['position' => 5]);
        ArticleSeriesItem::where('article_series_id', $series->id)
            ->where('blog_post_id', $annexes[1]->id)->update(['position' => 1]);

        $payload = $this->seriesPayloadFor($root);

        $this->assertSame($annexes[1]->id, $payload['next']['id']);
    }

    public function test_the_navigation_is_correct_after_promoting_an_annex(): void
    {
        // La promotion deplace deux Articles a la fois : c'est la ou une
        // navigation calculee a part se serait desynchronisee.
        [$dossier, $series, $root, $annexes] = $this->series('Ancienne racine', ['Future racine', 'Autre']);

        $this->actingAs($this->author)
            ->patchJson(route('organization.dossiers.series.update', [
                'organization' => $this->org->slug,
                'dossier' => $dossier->id,
            ]), ['root_blog_post_id' => $annexes[0]->id])
            ->assertOk();

        $promoted = $this->seriesPayloadFor($annexes[0]);
        $this->assertTrue($promoted['is_root']);
        $this->assertNull($promoted['previous']);
        // L'ancienne racine est devenue la premiere annexe : c'est elle qui suit.
        $this->assertSame($root->id, $promoted['next']['id']);

        $former = $this->seriesPayloadFor($root);
        $this->assertFalse($former['is_root']);
        $this->assertSame($annexes[0]->id, $former['previous']['id']);
    }

    public function test_an_article_removed_from_the_series_loses_its_navigation(): void
    {
        [$dossier, , , $annexes] = $this->series('Racine', ['A retirer', 'Reste']);

        $this->actingAs($this->author)
            ->deleteJson(route('organization.dossiers.series.annexes.destroy', [
                'organization' => $this->org->slug,
                'dossier' => $dossier->id,
                'item' => $annexes[0]->id,
            ]))
            ->assertOk();

        // Retire de la Serie, mais toujours dans le Dossier.
        $this->assertNull($this->seriesPayloadFor($annexes[0]));
        $this->assertDatabaseHas('dossier_blog_posts', [
            'dossier_id' => $dossier->id, 'blog_post_id' => $annexes[0]->id,
        ]);
    }

    // ── Le lien vers la Serie ───────────────────────────────────────────────

    public function test_the_payload_links_to_the_series_inside_its_dossier(): void
    {
        // Ce produit n'a pas de route de Serie dediee : la Serie se lit dans
        // l'onglet « contenus » de son Dossier.
        [$dossier, , $root] = $this->series('Racine', ['Une annexe']);

        $payload = $this->seriesPayloadFor($root);

        $this->assertStringContainsString((string) $dossier->id, $payload['url']);
        $this->assertStringContainsString('tab=contenus', $payload['url']);
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────

    public function test_an_article_of_another_organization_is_never_reachable(): void
    {
        [, , $root] = $this->series('Racine', ['Annexe']);

        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($stranger)
            ->getJson(route('organization.blog.dossier.current', [
                'organization' => $this->org->slug,
                'post' => $root->slug,
            ]))
            // 403 et non 404 : le middleware du prefixe d'Organization refuse
            // avant meme d'atteindre le controleur. Le refus arrive donc plus
            // tot que la garde de tenant du controleur, qui reste en second
            // verrou.
            ->assertForbidden();
    }

    public function test_the_neighbours_never_come_from_another_dossier(): void
    {
        [, , $root, $annexes] = $this->series('Racine', ['Annexe']);

        // Une seconde Serie ailleurs : elle ne doit jamais apparaitre dans la
        // navigation de la premiere.
        [, , $otherRoot] = $this->series('Racine ailleurs', ['Annexe ailleurs']);

        $payload = $this->seriesPayloadFor($root);
        $ids = collect($payload['articles'])->pluck('id')->all();

        $this->assertContains($annexes[0]->id, $ids);
        $this->assertNotContains($otherRoot->id, $ids);
    }
}
