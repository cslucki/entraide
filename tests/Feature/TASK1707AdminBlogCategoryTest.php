<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1707 — meme defaut que F3, cinquieme surface, HORS recette.
 *
 * Trouve en corrigeant F3 : la liste des articles de l'administration
 * d'Organization portait le MEME `$post->category->name` sur un modele qui n'a
 * pas d'attribut `name`. Pastille de couleur vide, pour la meme raison.
 *
 * Ce n'est PAS un defaut releve par la recette, et il est traite dans un commit
 * separe : autre surface, et son contexte de nommage est `blog`
 * (`blog_naming`), pas `transactions`. C'est tout l'interet de distinguer les
 * deux appels — `displayName('blog')` suit un reglage different de celui des
 * echanges, et un `displayName('transactions')` copie d'un ecran a l'autre
 * aurait affiche le mauvais nom ici.
 *
 * Une seule garde : c'est le MEME defaut, deja mesure sous tous ses angles par
 * `TASK1707DashboardCategoryTest`. Ce qui reste a prouver ici, c'est le
 * contexte.
 */
class TASK1707AdminBlogCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_liste_admin_des_articles_affiche_la_categorie_au_nom_du_blog(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'org-1707-blog',
            'is_active' => true,
            'blog_naming' => 'b2b',
            // Le reglage des ECHANGES est mis a l'oppose a dessein : si cette
            // vue prenait le contexte `transactions`, elle afficherait le nom
            // b2c et ce test rougirait.
            'transactions_naming' => 'b2c',
        ]);

        $categorie = Category::factory()->create([
            'organization_id' => $organization->id,
            'name_b2c' => 'Recits de membres',
            'name_b2b' => 'Publications institutionnelles',
        ]);

        $administrateur = User::factory()->complete()->create([
            'organization_id' => $organization->id,
            'email_verified_at' => now(),
        ]);
        $organization->update(['admin_id' => $administrateur->id]);

        BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $administrateur->id,
            'category_id' => $categorie->id,
            'title' => 'Un article de la recette 1707',
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);

        app()->forgetInstance('current_organization');

        $html = $this->actingAs($administrateur)
            ->get('/org/'.$organization->slug.'/admin/blog')
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/<span[^>]*\sdata-blog-category[\s>][^>]*>(.*?)<\/span>/s', $html, $pastille),
            'La pastille de categorie n\'est pas servie : la mesure ne porterait sur rien.'
        );

        $this->assertSame(
            'Publications institutionnelles',
            trim($pastille[1]),
            'La pastille est vide, ou suit le reglage des echanges au lieu de celui du blog.'
        );
    }
}
