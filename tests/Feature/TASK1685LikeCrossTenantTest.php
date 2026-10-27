<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Like;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1685 / P0-2 — un « j'aime » ne franchit plus la frontiere d'Organization.
 *
 * ## Ce qui a ete mesure AVANT tout code, en base isolee
 *
 * Un membre de l'Organization A postait sur `/org/B/likes/toggle` avec
 * l'identifiant d'un article de B :
 *
 * - HTTP **200** ;
 * - table `likes` **0 -> 1**, ligne `organization_id: B` ;
 * - reponse **`{"liked":true,"count":1}`** ;
 * - article vise : **`audience=loop, listed_in_blog=false`**, donc NON public.
 *
 * Trois defauts en un : une ecriture dans le tenant d'autrui, la divulgation
 * du compteur d'un article non public, et un oracle d'enumeration (200 contre
 * 404 confirmait l'existence d'un `likeable_id` etranger).
 *
 * ## La cause, et pourquoi elle se voyait mal
 *
 * Le scope du controleur (`where('organization_id', $org->id)`) etait CORRECT —
 * mais il bornait l'article a l'Organization de **l'URL**, jamais a celle de
 * l'utilisateur. Une lecture rapide voyait un `where` sur `organization_id` et
 * concluait que la frontiere etait tenue. C'est l'incoherence
 * `CurrentOrganization` relevee a l'audit sur les surfaces de lecture — ici sur
 * une ECRITURE.
 *
 * ## Pourquoi la garde est en AMONT
 *
 * Refuser APRES avoir resolu l'article aurait ferme l'ecriture en laissant
 * ouverts le compteur et l'oracle. Ces tests verifient les trois.
 */
class TASK1685LikeCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $memberA;

    private User $memberB;

    private BlogPost $postA;

    private BlogPost $postB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'p02-a', 'is_active' => true, 'is_public' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'p02-b', 'is_active' => true, 'is_public' => true]);

        $this->memberA = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false, 'email_verified_at' => now()]);
        $this->memberB = User::factory()->create(['organization_id' => $this->orgB->id, 'is_admin' => false, 'email_verified_at' => now()]);

        $this->postA = $this->makePost($this->orgA, $this->memberA, 'article-de-a', 'public', true);
        // L'article de B est volontairement NON public : c'est le cas reel mesure.
        $this->postB = $this->makePost($this->orgB, $this->memberB, 'article-prive-de-b', 'loop', false);
    }

    private function makePost(Organization $org, User $author, string $slug, string $audience, bool $listed): BlogPost
    {
        $post = new BlogPost([
            'organization_id' => $org->id,
            'user_id' => $author->id,
            'title' => 'Titre '.$slug,
            'slug' => $slug,
            'content' => 'contenu',
            'status' => 'published',
            'audience' => $audience,
            'listed_in_blog' => $listed,
        ]);
        $post->save();

        return $post;
    }

    private function likes(): int
    {
        return DB::table('likes')->count();
    }

    private function toggle(User $actor, Organization $urlOrg, BlogPost $post)
    {
        return $this->actingAs($actor)->postJson("/org/{$urlOrg->slug}/likes/toggle", [
            'likeable_type' => 'blog_post',
            'likeable_id' => $post->id,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le defaut prouve, dans les DEUX SENS
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_member_of_a_cannot_like_a_private_article_of_b(): void
    {
        $before = $this->likes();

        $response = $this->toggle($this->memberA, $this->orgB, $this->postB);

        $response->assertNotFound();
        $this->assertSame($before, $this->likes(), 'aucune ligne ne doit etre creee');
    }

    public function test_a_member_of_b_cannot_like_an_article_of_a(): void
    {
        $before = $this->likes();

        $this->toggle($this->memberB, $this->orgA, $this->postA)->assertNotFound();

        $this->assertSame($before, $this->likes());
    }

    public function test_the_url_organization_must_match_the_actor(): void
    {
        $before = $this->likes();

        // URL de A, article de B : l'article n'appartient pas a l'URL.
        $this->toggle($this->memberA, $this->orgA, $this->postB)->assertNotFound();

        // URL de B, article de A : l'acteur n'appartient pas a l'URL.
        $this->toggle($this->memberA, $this->orgB, $this->postA)->assertNotFound();

        $this->assertSame($before, $this->likes());
    }

    // ────────────────────────────────────────────────────────────────────────
    // Ni compteur, ni oracle
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Le refus ne doit porter AUCUN compteur. C'est la raison pour laquelle la
     * garde est en amont : refuser apres avoir resolu l'article aurait ferme
     * l'ecriture en laissant fuir `count`.
     */
    public function test_the_refusal_discloses_no_counter_and_no_private_attribute(): void
    {
        // On pose d'abord des likes legitimes sur l'article de B, pour que son
        // compteur soit NON nul : un compteur a zero ne prouverait rien.
        Like::create([
            'user_id' => $this->memberB->id,
            'likeable_type' => BlogPost::class,
            'likeable_id' => $this->postB->id,
            'organization_id' => $this->orgB->id,
        ]);

        $response = $this->toggle($this->memberA, $this->orgB, $this->postB);

        $response->assertNotFound();

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('"count"', $body);
        $this->assertStringNotContainsString('"liked"', $body);
        $this->assertStringNotContainsString($this->postB->title, $body);
        $this->assertStringNotContainsString($this->postB->slug, $body);
    }

    /**
     * L'oracle d'enumeration. Un article etranger qui EXISTE et un identifiant
     * qui n'existe nulle part doivent rendre la MEME chose — sinon le refus
     * devient un detecteur d'existence.
     */
    public function test_an_existing_foreign_article_is_indistinguishable_from_an_unknown_one(): void
    {
        $existing = $this->toggle($this->memberA, $this->orgB, $this->postB);

        $unknown = $this->actingAs($this->memberA)->postJson("/org/{$this->orgB->slug}/likes/toggle", [
            'likeable_type' => 'blog_post',
            'likeable_id' => '01a00000-0000-7000-0000-000000000000',
        ]);

        $this->assertSame($existing->status(), $unknown->status());

        // On ne compare PAS les corps octet pour octet : en test `APP_DEBUG`
        // renvoie une trace qui reprend la charge utile de la requete, donc
        // l'identifiant demande — deux corps differents pour une raison qui
        // n'existe pas en production. Mesure faite en ecrivant ce test.
        //
        // Ce qui est affirme est la propriete REELLE : ni l'un ni l'autre ne
        // porte quoi que ce soit de l'article vise.
        foreach ([$existing, $unknown] as $response) {
            $body = $response->getContent() ?: '';
            $this->assertStringNotContainsString($this->postB->title, $body);
            $this->assertStringNotContainsString($this->postB->slug, $body);
            $this->assertStringNotContainsString('"count"', $body);
            $this->assertStringNotContainsString('"liked"', $body);
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le flux LEGITIME n'a pas bouge
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_member_still_likes_an_article_of_their_own_organization(): void
    {
        $before = $this->likes();

        $response = $this->toggle($this->memberA, $this->orgA, $this->postA);

        $response->assertOk();
        $response->assertJson(['liked' => true, 'count' => 1]);
        $this->assertSame($before + 1, $this->likes());

        $row = DB::table('likes')->latest('created_at')->first();
        $this->assertSame($this->memberA->id, $row->user_id);
        $this->assertSame($this->orgA->id, $row->organization_id);
    }

    public function test_the_toggle_still_removes_an_own_like(): void
    {
        $this->toggle($this->memberA, $this->orgA, $this->postA)->assertOk();
        $this->assertSame(1, $this->likes());

        $this->toggle($this->memberA, $this->orgA, $this->postA)
            ->assertOk()
            ->assertJson(['liked' => false, 'count' => 0]);

        $this->assertSame(0, $this->likes());
    }

    /**
     * Les regles de visibilite des articles ne sont PAS modifiees par ce
     * correctif : dans SON Organization, qui pouvait aimer quoi reste
     * exactement ce que c'etait. On le fige pour qu'une future garde de
     * visibilite soit une decision, pas un effet de bord de celle-ci.
     */
    public function test_the_existing_visibility_rules_are_unchanged_inside_ones_own_organization(): void
    {
        $privateOwn = $this->makePost($this->orgA, $this->memberA, 'prive-de-a', 'loop', false);

        $this->toggle($this->memberA, $this->orgA, $privateOwn)->assertOk();

        $this->assertSame(1, $this->likes());
    }

    // ────────────────────────────────────────────────────────────────────────
    // Le SuperAdmin ne participe pas
    // ────────────────────────────────────────────────────────────────────────

    public function test_a_super_admin_gets_no_implicit_participation(): void
    {
        $superAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $before = $this->likes();

        $this->toggle($superAdmin, $this->orgB, $this->postB)->assertNotFound();

        $this->assertSame($before, $this->likes(), 'le SuperAdmin ne doit pas ecrire dans un tenant etranger');
    }

    // ────────────────────────────────────────────────────────────────────────
    // On ne supprime pas le like d'autrui
    // ────────────────────────────────────────────────────────────────────────

    public function test_nobody_can_remove_someone_elses_like(): void
    {
        $victimLike = Like::create([
            'user_id' => $this->memberB->id,
            'likeable_type' => BlogPost::class,
            'likeable_id' => $this->postB->id,
            'organization_id' => $this->orgB->id,
        ]);

        $this->toggle($this->memberA, $this->orgB, $this->postB)->assertNotFound();

        $this->assertDatabaseHas('likes', ['id' => $victimLike->id]);
    }
}
