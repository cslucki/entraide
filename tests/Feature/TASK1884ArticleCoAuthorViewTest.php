<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1884 correctif 1 — vue de l'article :
 * #2 le co-auteur a les boutons de l'auteur (Modifier, Publier) quel que soit le statut ;
 * #4 les co-auteurs sont nommes a cote de l'auteur.
 */
class TASK1884ArticleCoAuthorViewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $coAuteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Alice', 'name' => 'Alice Martin']);
        $this->coAuteur = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Bruno', 'name' => 'Bruno Petit']);
    }

    private function article(string $statut): BlogPost
    {
        $post = BlogPost::withoutGlobalScopes()->forceCreate([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->org->id,
            'title' => 'Article '.$statut,
            'slug' => 'article-'.$statut,
            'content' => '<p>Contenu</p>',
            'status' => $statut,
            'published_at' => $statut === 'published' ? now()->subHour() : null,
            'listed_in_blog' => true,
        ]);
        $post->coAuthors()->attach($this->coAuteur->id, ['role' => 'coauthor', 'added_by' => $this->auteur->id]);

        return $post;
    }

    private function page(BlogPost $post, User $lecteur)
    {
        return $this->actingAs($lecteur)->get(route('organization.blog.show', ['organization' => $this->org->slug, 'post' => $post->slug]));
    }

    public function test_le_co_auteur_voit_modifier_quel_que_soit_le_statut_et_publier_hors_publie(): void
    {
        foreach (['draft', 'pending', 'published', 'archived'] as $statut) {
            $post = $this->article($statut);
            $edition = route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $post->slug]);

            $reponse = $this->page($post, $this->coAuteur)->assertOk()
                ->assertSee('data-blog-author-actions', false)
                ->assertSee($edition, false);

            $publier = route('organization.blog.publish', ['organization' => $this->org->slug, 'post' => $post->slug]);
            $statut === 'published'
                ? $reponse->assertDontSee($publier, false)
                : $reponse->assertSee($publier, false);

            // « Nouvel article » reste reserve a l'auteur de CET article (T1783) : le lien
            // apparait ailleurs (navigation), donc autant de fois que pour un simple membre.
            $creer = route('organization.blog.create', ['organization' => $this->org->slug]);
            $auteur = $this->page($post, $this->auteur)->getContent();
            $this->assertSame(substr_count($auteur, $creer) - 1, substr_count($reponse->getContent(), $creer));
        }
    }

    public function test_le_co_auteur_peut_publier_et_un_simple_membre_ne_voit_aucun_bouton(): void
    {
        $post = $this->article('draft');

        $this->actingAs($this->coAuteur)
            ->patch(route('organization.blog.publish', ['organization' => $this->org->slug, 'post' => $post->slug]))
            ->assertRedirect();
        $this->assertSame('published', $post->fresh()->status);

        $membre = User::factory()->create(['organization_id' => $this->org->id]);
        $this->page($post->fresh(), $membre)->assertOk()->assertDontSee('data-blog-author-actions', false);
    }

    public function test_les_co_auteurs_sont_nommes_et_un_compte_desactive_ne_l_est_pas(): void
    {
        $post = $this->article('published');
        $desactive = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Zoe', 'name' => 'Zoe Fantome', 'banned_at' => now()]);
        $post->coAuthors()->attach($desactive->id, ['role' => 'coauthor', 'added_by' => $this->auteur->id]);

        $membre = User::factory()->create(['organization_id' => $this->org->id]);
        $reponse = $this->page($post, $membre)->assertOk()->assertSee('data-blog-coauthors', false);

        $this->assertStringContainsString(e($this->coAuteur->publicDisplayName()), $reponse->getContent());
        $this->assertStringNotContainsString(e($desactive->publicDisplayName() ?? 'Zoe'), $reponse->getContent());
    }

    public function test_sans_co_auteur_rien_n_est_affiche(): void
    {
        $post = $this->article('published');
        $post->coAuthors()->detach();

        $this->page($post, $this->auteur)->assertOk()->assertDontSee('data-blog-coauthors', false);
    }
}
