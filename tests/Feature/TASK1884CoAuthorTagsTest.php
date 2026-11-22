<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1884 correctif 3 (#8) — le co-auteur pose des tags ; un champ de tags
 * envoye vide retire tous les tags (avant : ignore, les tags restaient).
 */
class TASK1884CoAuthorTagsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $coAuteur;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->coAuteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->post = BlogPost::withoutGlobalScopes()->forceCreate([
            'user_id' => $this->auteur->id, 'organization_id' => $this->org->id,
            'title' => 'Article tags', 'slug' => 'article-tags', 'content' => '<p>x</p>', 'status' => 'draft',
        ]);
        $this->post->coAuthors()->attach($this->coAuteur->id, ['role' => 'coauthor', 'added_by' => $this->auteur->id]);
    }

    private function enregistrer(User $qui, array $champs): void
    {
        $this->actingAs($qui)->put(route('organization.blog.update', ['organization' => $this->org->slug, 'post' => $this->post->slug]), $champs + [
            'title' => 'Article tags',
            'summary' => 'Resume',
            'content' => str_repeat('Contenu assez long pour passer la validation du blog. ', 3),
            'status' => 'draft',
            'category_id' => Category::factory()->create(['organization_id' => $this->org->id])->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function tags(): array
    {
        return $this->post->tags()->orderBy('name')->pluck('name')->all();
    }

    public function test_le_co_auteur_pose_des_tags(): void
    {
        $this->enregistrer($this->coAuteur, ['tags' => 'Emploi, #Entraide']);

        $this->assertSame(['Emploi', 'Entraide'], $this->tags());
    }

    public function test_un_champ_vide_retire_tous_les_tags_un_champ_absent_n_y_touche_pas(): void
    {
        $tag = Tag::create(['name' => 'Ancien', 'slug' => 'ancien', 'organization_id' => $this->org->id]);
        $this->post->tags()->syncWithPivotValues([$tag->id], ['organization_id' => $this->org->id]);

        $this->enregistrer($this->auteur, []);
        $this->assertSame(['Ancien'], $this->tags(), 'Champ absent : rien ne change.');

        $this->enregistrer($this->coAuteur, ['tags' => '']);
        $this->assertSame([], $this->tags(), 'Champ envoye vide : tous les tags sont retires.');
    }
}
