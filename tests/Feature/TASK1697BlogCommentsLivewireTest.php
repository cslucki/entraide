<?php

namespace Tests\Feature;

use App\Livewire\BlogComments;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1697 volet b — le fil de commentaires devient un composant Livewire.
 *
 * Quatre symptomes signales par Cyril, une seule cause : tout le bloc vivait
 * derriere `x-show="showComments"`, et chaque envoi etait un POST plein cadre.
 *
 * ## Ce que ces gardes tiennent
 *
 * - les reponses sont visibles **sans aucun clic** ;
 * - « Repondre » existe sur chaque commentaire ;
 * - les gardes du composant **RECOPIENT** celles de `BlogCommentController`,
 *   qui reste en place pour un navigateur sans JavaScript : frontiere
 *   d'Organization, `max:2000`, parent appartenant a l'article, suppression
 *   reservee a l'auteur ou a un administrateur ;
 * - **une seule profondeur** : repondre a une reponse est refuse ;
 * - `postId` est `#[Locked]` — sans quoi le client pourrait commenter
 *   l'article d'une autre Organization avec un composant monte sur le sien.
 */
class TASK1697BlogCommentsLivewireTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteur;

    private User $lecteur;

    private BlogPost $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1697b',
            'is_active' => true,
            'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->auteur = $this->membre();
        $this->lecteur = $this->membre();
        $this->article = $this->articlePublie($this->auteur);
    }

    // ── Ce que Cyril voyait, et ne voit plus ────────────────────────────────

    public function test_les_reponses_sont_visibles_sans_aucun_clic(): void
    {
        $commentaire = $this->commentaire($this->lecteur, 'Un commentaire racine.');
        $this->reponse($commentaire, $this->auteur, 'Une reponse visible.');

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->assertSee('Un commentaire racine.')
            ->assertSee('Une reponse visible.');
    }

    public function test_chaque_commentaire_porte_un_bouton_repondre(): void
    {
        $commentaire = $this->commentaire($this->lecteur, 'Un commentaire racine.');

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->assertSee('data-comment-reply="'.$commentaire->id.'"', false)
            ->assertSee(__('blog.btn_reply'));
    }

    /** Le mecanisme qui fait que Y voit le commentaire de X sans rafraichir. */
    public function test_le_fil_s_actualise_tout_seul(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->assertSee('wire:poll.visible.15s', false);
    }

    public function test_la_zone_de_saisie_est_toujours_la(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->assertSee('data-comment-compose', false)
            ->assertSee('data-comment-input', false);
    }

    // ── Deposer ─────────────────────────────────────────────────────────────

    public function test_deposer_un_commentaire_l_enregistre_et_vide_le_champ(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Mon commentaire.')
            ->call('addComment')
            ->assertSet('content', '')
            ->assertDispatched('comment-added')
            ->assertSee('Mon commentaire.');

        $this->assertDatabaseHas('blog_comments', [
            'blog_post_id' => $this->article->id,
            'user_id' => $this->lecteur->id,
            'parent_id' => null,
            'content' => 'Mon commentaire.',
        ]);
    }

    public function test_un_commentaire_vide_est_refuse(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', '   ')
            ->call('addComment')
            ->assertHasErrors(['content' => 'required']);

        $this->assertDatabaseCount('blog_comments', 0);
    }

    public function test_un_commentaire_trop_long_est_refuse(): void
    {
        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('content', str_repeat('a', 2001))
            ->call('addComment')
            ->assertHasErrors(['content' => 'max']);

        $this->assertDatabaseCount('blog_comments', 0);
    }

    // ── Repondre ────────────────────────────────────────────────────────────

    public function test_repondre_attache_la_reponse_au_commentaire(): void
    {
        $commentaire = $this->commentaire($this->lecteur, 'Un commentaire racine.');

        Livewire::actingAs($this->auteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->call('startReply', (string) $commentaire->id)
            ->assertSet('replyTo', (string) $commentaire->id)
            ->set('replyContent', 'Ma reponse.')
            ->call('addReply')
            ->assertSet('replyTo', null)
            ->assertDispatched('comment-added');

        $this->assertDatabaseHas('blog_comments', [
            'blog_post_id' => $this->article->id,
            'user_id' => $this->auteur->id,
            'parent_id' => $commentaire->id,
            'content' => 'Ma reponse.',
        ]);
    }

    public function test_le_champ_de_reponse_n_apparait_que_sous_le_commentaire_vise(): void
    {
        $un = $this->commentaire($this->lecteur, 'Premier.');
        $deux = $this->commentaire($this->lecteur, 'Second.');

        Livewire::actingAs($this->auteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->call('startReply', (string) $un->id)
            ->assertSee('data-reply-compose="'.$un->id.'"', false)
            ->assertDontSee('data-reply-compose="'.$deux->id.'"', false);
    }

    /** Une seule profondeur : repondre a une reponse est refuse. */
    public function test_repondre_a_une_reponse_est_refuse(): void
    {
        $commentaire = $this->commentaire($this->lecteur, 'Racine.');
        $reponse = $this->reponse($commentaire, $this->auteur, 'Reponse.');

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->call('startReply', (string) $reponse->id)
            ->assertStatus(404);
    }

    public function test_repondre_a_un_commentaire_d_un_autre_article_est_refuse(): void
    {
        $autreArticle = $this->articlePublie($this->auteur, 'Autre article');
        $ailleurs = BlogComment::create([
            'blog_post_id' => $autreArticle->id,
            'user_id' => $this->lecteur->id,
            'organization_id' => $this->organization->id,
            'content' => 'Ailleurs.',
            'is_approved' => true,
        ]);

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->call('startReply', (string) $ailleurs->id)
            ->assertStatus(404);
    }

    public function test_annuler_referme_le_champ_de_reponse(): void
    {
        $commentaire = $this->commentaire($this->lecteur, 'Racine.');

        Livewire::actingAs($this->auteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->call('startReply', (string) $commentaire->id)
            ->set('replyContent', 'Un brouillon.')
            ->call('cancelReply')
            ->assertSet('replyTo', null)
            ->assertSet('replyContent', '');
    }

    // ── Supprimer ───────────────────────────────────────────────────────────

    public function test_on_supprime_son_propre_commentaire(): void
    {
        $sien = $this->commentaire($this->lecteur, 'Le mien.');

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->call('deleteComment', (string) $sien->id)
            ->assertDispatched('comment-removed');

        $this->assertSoftDeleted('blog_comments', ['id' => $sien->id]);
    }

    public function test_on_ne_supprime_pas_le_commentaire_d_un_autre(): void
    {
        $autrui = $this->commentaire($this->auteur, 'Pas le mien.');

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->call('deleteComment', (string) $autrui->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('blog_comments', ['id' => $autrui->id, 'deleted_at' => null]);
    }

    public function test_un_administrateur_supprime_le_commentaire_d_un_autre(): void
    {
        $autrui = $this->commentaire($this->auteur, 'Pas le mien.');
        $admin = $this->membre(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(BlogComments::class, ['post' => $this->article])
            ->call('deleteComment', (string) $autrui->id);

        $this->assertSoftDeleted('blog_comments', ['id' => $autrui->id]);
    }

    // ── Les frontieres ──────────────────────────────────────────────────────

    public function test_un_visiteur_anonyme_ne_depose_rien(): void
    {
        Livewire::test(BlogComments::class, ['post' => $this->article])
            ->set('content', 'Anonyme.')
            ->call('addComment')
            ->assertStatus(403);

        $this->assertDatabaseCount('blog_comments', 0);
    }

    public function test_un_article_d_une_autre_organization_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1697z', 'is_active' => true]);
        $etranger = BlogPost::create([
            'user_id' => $this->auteur->id,
            'organization_id' => $autreOrg->id,
            'title' => 'Article etranger',
            'slug' => 'article-etranger-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $etranger])
            ->assertStatus(404);
    }

    /** `#[Locked]` : le client ne doit pas pouvoir deplacer le composant. */
    public function test_l_article_vise_est_verrouille(): void
    {
        $autreArticle = $this->articlePublie($this->auteur, 'Autre article');

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->set('postId', (string) $autreArticle->id);
    }

    public function test_un_commentaire_non_approuve_n_est_pas_affiche(): void
    {
        BlogComment::create([
            'blog_post_id' => $this->article->id,
            'user_id' => $this->lecteur->id,
            'organization_id' => $this->organization->id,
            'content' => 'En attente de validation.',
            'is_approved' => false,
        ]);

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->assertDontSee('En attente de validation.');
    }

    public function test_le_total_compte_les_commentaires_ET_les_reponses(): void
    {
        $un = $this->commentaire($this->lecteur, 'Un.');
        $this->reponse($un, $this->auteur, 'Reponse a un.');
        $this->commentaire($this->lecteur, 'Deux.');

        Livewire::actingAs($this->lecteur)
            ->test(BlogComments::class, ['post' => $this->article])
            ->assertSee(trans_choice('blog.comments_total', 3, ['count' => 3]));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function membre(array $attributs = []): User
    {
        return User::factory()->complete()->create(array_merge([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ], $attributs));
    }

    private function articlePublie(User $auteur, string $titre = 'Article commentable'): BlogPost
    {
        return BlogPost::create([
            'user_id' => $auteur->id,
            'organization_id' => $this->organization->id,
            'title' => $titre,
            'slug' => Str::slug($titre).'-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    private function commentaire(User $qui, string $contenu): BlogComment
    {
        return BlogComment::create([
            'blog_post_id' => $this->article->id,
            'user_id' => $qui->id,
            'organization_id' => $this->organization->id,
            'content' => $contenu,
            'is_approved' => true,
        ]);
    }

    private function reponse(BlogComment $parent, User $qui, string $contenu): BlogComment
    {
        return BlogComment::create([
            'blog_post_id' => $parent->blog_post_id,
            'user_id' => $qui->id,
            'parent_id' => $parent->id,
            'organization_id' => $this->organization->id,
            'content' => $contenu,
            'is_approved' => true,
        ]);
    }
}
