<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1697 — l'ancre `#commentaires` que le controleur visait sans qu'elle
 * existe.
 *
 * Cyril : « quand on fait un commentaire la page se rafraichit et revient au
 * debut de l'article, alors qu'elle devrait rester sur le dernier commentaire
 * qu'on vient de faire ».
 *
 * La cause n'etait pas la redirection : `BlogCommentController::store()`
 * redirige depuis toujours vers `URL::previous().'#commentaires'`. C'est la
 * CIBLE qui manquait — **aucun element de `blog/show.blade.php` ne portait
 * `id="commentaires"`**. Un fragment qui ne designe rien laisse le navigateur
 * en haut de page.
 *
 * Deux details qui comptent :
 *
 * - l'ancre est posee sur l'enveloppe **VISIBLE**, pas sur le bloc replie :
 *   un navigateur ne defile pas vers une cible masquee ;
 * - arriver avec ce fragment **ouvre** la section, sans quoi on atterrirait
 *   devant un bloc ferme contenant le commentaire qu'on vient d'ecrire.
 *   Cette partie est de l'Alpine, hors de portee de PHPUnit : ce que ces
 *   gardes mesurent, c'est l'ancre et la redirection.
 */
class TASK1697BlogCommentAnchorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private BlogPost $article;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1697c',
            'is_active' => true,
            'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->article = BlogPost::query()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article commentable',
            'slug' => 'article-commentable-'.Str::random(6),
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_l_article_porte_l_ancre_des_commentaires(): void
    {
        $html = $this->actingAs($this->membre)
            ->get($this->urlArticle())
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="commentaires"', $html);
    }

    /**
     * L'ancre doit etre posee AVANT le bloc `x-show` : une cible masquee ne
     * recoit aucun defilement. On mesure donc l'ORDRE dans le document.
     */
    public function test_l_ancre_precede_le_bloc_repliable(): void
    {
        $html = $this->actingAs($this->membre)
            ->get($this->urlArticle())
            ->assertOk()
            ->getContent();

        // Comparer deux `strpos()` ne SUFFIT PAS : posee a l'interieur de la
        // balise masquee, `id="commentaires"` precede `x-show` AU SEIN DU MEME
        // TAG, et la comparaison de positions passe. Mesure par sabotage : la
        // garde restait verte. On inspecte donc la BALISE PORTEUSE.
        $this->assertSame(1, preg_match('/<div[^>]*id="commentaires"[^>]*>/', $html, $porteuse),
            'Aucune balise ne porte l\'ancre.');

        $this->assertStringNotContainsString('x-show', $porteuse[0],
            "La balise de l'ancre porte `x-show` : une cible masquee ne recoit aucun defilement.");

        $ancre = strpos($html, 'id="commentaires"');
        $repli = strpos($html, 'x-show="showComments"');

        $this->assertNotFalse($repli, 'bloc repliable absent');
        $this->assertLessThan($repli, $ancre, "L'ancre est posee APRES le bloc repliable.");
    }

    public function test_deposer_un_commentaire_redirige_vers_l_ancre(): void
    {
        $reponse = $this->actingAs($this->membre)
            ->from($this->urlArticle())
            ->post($this->urlCommentaire(), ['content' => 'Un commentaire de mesure.']);

        $reponse->assertRedirect($this->urlArticle().'#commentaires');
    }

    public function test_le_commentaire_est_bien_enregistre(): void
    {
        $this->actingAs($this->membre)
            ->from($this->urlArticle())
            ->post($this->urlCommentaire(), ['content' => 'Un commentaire de mesure.']);

        $this->assertDatabaseHas('blog_comments', [
            'blog_post_id' => $this->article->id,
            'user_id' => $this->membre->id,
            'content' => 'Un commentaire de mesure.',
        ]);
    }

    private function urlArticle(): string
    {
        return route('organization.blog.show', [
            'organization' => $this->organization->slug,
            'post' => $this->article->slug,
        ]);
    }

    private function urlCommentaire(): string
    {
        return route('organization.blog.comment.store', [
            'organization' => $this->organization->slug,
            'post' => $this->article->slug,
        ]);
    }
}
