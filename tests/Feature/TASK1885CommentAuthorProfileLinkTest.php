<?php

namespace Tests\Feature;

use App\Livewire\BlogComments;
use App\Livewire\EntraideComments;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\EntraideComment;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1885 C3 (demande Cyril, 22/11) — l'avatar et le nom de l'auteur d'un
 * commentaire menent a son profil public : commentaires du blog, d'une demande
 * d'aide et d'une proposition d'aide (le Flux avait deja l'avatar, le nom suit).
 *
 * Garde de sens : un auteur DESACTIVE n'a aucun lien (pas d'adresse morte).
 */
class TASK1885CommentAuthorProfileLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $lecteur;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1885-c3', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->org);

        $this->lecteur = $this->membre();
        $this->auteur = $this->membre();
    }

    public function test_blog_comment_and_reply_link_avatar_and_name_to_the_profile(): void
    {
        $article = BlogPost::create([
            'user_id' => $this->lecteur->id,
            'organization_id' => $this->org->id,
            'title' => 'Article',
            'slug' => 'article-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        $racine = BlogComment::create([
            'blog_post_id' => $article->id, 'user_id' => $this->auteur->id,
            'organization_id' => $this->org->id, 'content' => 'Racine.', 'is_approved' => true,
        ]);
        BlogComment::create([
            'blog_post_id' => $article->id, 'user_id' => $this->auteur->id, 'parent_id' => $racine->id,
            'organization_id' => $this->org->id, 'content' => 'Reponse.', 'is_approved' => true,
        ]);

        $html = Livewire::actingAs($this->lecteur)->test(BlogComments::class, ['post' => $article])->html();

        // Avatar + nom, sur le commentaire ET sur la reponse.
        $this->assertSame(4, substr_count($html, 'href="'.$this->profil($this->auteur).'"'));
        $this->assertSame(4, substr_count($html, 'data-user-profile-link="'.$this->auteur->id.'"'));
    }

    public function test_help_request_and_help_offer_comments_link_to_the_profile(): void
    {
        $demande = ServiceRequest::factory()->forUser($this->lecteur)->create([
            'organization_id' => $this->org->id, 'category_id' => Category::factory()->create()->id, 'status' => 'open',
        ]);
        $proposition = Service::factory()->forUser($this->lecteur)->forCategory(Category::factory()->create())
            ->create(['organization_id' => $this->org->id, 'status' => 'active']);

        foreach ([$demande, $proposition] as $annonce) {
            $this->commentaireEntraide($annonce, $this->auteur, 'Un avis.');

            $html = Livewire::actingAs($this->lecteur)->test(EntraideComments::class, ['annonce' => $annonce])->html();

            $this->assertSame(2, substr_count($html, 'href="'.$this->profil($this->auteur).'"'), $annonce::class);
        }
    }

    public function test_a_deactivated_author_has_no_link(): void
    {
        $demande = ServiceRequest::factory()->forUser($this->lecteur)->create([
            'organization_id' => $this->org->id, 'category_id' => Category::factory()->create()->id, 'status' => 'open',
        ]);
        $this->commentaireEntraide($demande, $this->auteur, 'Un avis.');
        $this->auteur->forceFill(['banned_at' => now()])->save();

        $html = Livewire::actingAs($this->lecteur)->test(EntraideComments::class, ['annonce' => $demande])->html();

        $this->assertStringContainsString('Un avis.', $html);
        $this->assertStringNotContainsString('data-user-profile-link=', $html);
    }

    private function membre(): User
    {
        return User::factory()->complete()->create(['organization_id' => $this->org->id, 'email_verified_at' => now()]);
    }

    private function profil(User $user): string
    {
        return e(route('organization.profile.show', ['organization' => $this->org->slug, 'user' => $user->slug ?: $user->id]));
    }

    private function commentaireEntraide(Service|ServiceRequest $annonce, User $auteur, string $texte): EntraideComment
    {
        return EntraideComment::create([
            'organization_id' => $annonce->organization_id,
            'commentable_type' => $annonce->getMorphClass(),
            'commentable_id' => $annonce->getKey(),
            'user_id' => $auteur->id,
            'content' => $texte,
            'is_approved' => true,
        ]);
    }
}
