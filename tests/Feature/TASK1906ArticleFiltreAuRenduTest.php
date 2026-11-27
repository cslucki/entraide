<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1906 — le contenu d'un article est refiltre A L'AFFICHAGE : un contenu
 * entre en base sans passer par l'editeur (ancien filtre, clonage, document de
 * Boucle insere directement) ne doit jamais etre rendu tel quel.
 */
class TASK1906ArticleFiltreAuRenduTest extends TestCase
{
    use RefreshDatabase;

    private const MALVEILLANT = '<p title=a onclick=alert(1)>texte</p><img src=x onerror=alert(2)><p x-init="alert(3)">fin</p>';

    private Organization $org;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true, 'is_public' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function article(string $contenu): BlogPost
    {
        // Ecriture DIRECTE en base : on contourne le filtre d'enregistrement.
        return BlogPost::create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->org->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->org->id])->id,
            'title' => 'Article T1906',
            'slug' => 'article-t1906-'.uniqid(),
            'summary' => 'Resume',
            'content' => $contenu,
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function test_la_page_de_l_article_refiltre_un_contenu_stocke_sans_filtre(): void
    {
        $post = $this->article(self::MALVEILLANT);

        $page = $this->get(route('blog.show', ['post' => $post]))->assertOk()->getContent();

        $this->assertStringContainsString('texte', $page);
        $this->assertStringNotContainsString('alert(1)', $page);
        $this->assertStringNotContainsString('alert(2)', $page);
        $this->assertStringNotContainsString('alert(3)', $page);
    }

    public function test_le_document_racine_d_une_boucle_garde_sa_liste_reduite_et_perd_les_attributs_actifs(): void
    {
        $post = $this->article('<h1>Racine</h1><blockquote>Citation</blockquote>'.self::MALVEILLANT);
        $boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);
        $boucle->forceFill(['manifesto_blog_post_id' => $post->id])->save();

        $html = $boucle->fresh()->manifestoHtmlForAdmin();

        $this->assertStringContainsString('<h1>Racine</h1>', $html);
        $this->assertStringContainsString('<blockquote>Citation</blockquote>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('alert(', $html);
    }
}
