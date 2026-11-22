<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1884 correctif 2 (#5) — co-ecriture d'un article de Boucle : seuls les
 * membres actifs d'une des Boucles liees sont proposes ET acceptes.
 */
class TASK1884CoAuthorLoopMembersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $membreBoucle;

    private User $horsBoucle;

    private BlogPost $post;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Auteur Principal']);
        $this->membreBoucle = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Camille Boucle']);
        $this->horsBoucle = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Camille Dehors']);

        $this->boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->auteur->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $this->boucle->id, 'user_id' => $this->auteur->id]);
        LoopMember::factory()->create(['loop_id' => $this->boucle->id, 'user_id' => $this->membreBoucle->id]);

        $this->post = BlogPost::withoutGlobalScopes()->forceCreate([
            'user_id' => $this->auteur->id, 'organization_id' => $this->org->id,
            'title' => 'Article de boucle', 'slug' => 'article-de-boucle', 'content' => '<p>x</p>', 'status' => 'draft',
        ]);
    }

    private function route(string $nom): string
    {
        return route('organization.blog.co-authors.'.$nom, ['organization' => $this->org->slug, 'post' => $this->post->slug]);
    }

    /** Comptes proposes par la recherche (q=Camille), par identifiant. */
    private function noms(): array
    {
        return collect($this->actingAs($this->auteur)->getJson($this->route('search').'?q=Camille')->assertOk()->json('users'))
            ->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    private function ids(User ...$users): array
    {
        return collect($users)->map(fn (User $u) => (string) $u->id)->sort()->values()->all();
    }

    public function test_article_hors_boucle_toute_l_organisation_reste_proposee(): void
    {
        $this->assertSame($this->ids($this->membreBoucle, $this->horsBoucle), $this->noms());
    }

    public function test_article_de_boucle_seuls_ses_membres_actifs_sont_proposes_et_acceptes(): void
    {
        $this->post->loops()->attach($this->boucle->id);
        $invite = User::factory()->create(['organization_id' => $this->org->id, 'name' => 'Camille Invitee']);
        LoopMember::factory()->invited()->create(['loop_id' => $this->boucle->id, 'user_id' => $invite->id]);

        $this->assertSame($this->ids($this->membreBoucle), $this->noms());

        $this->actingAs($this->auteur)->postJson($this->route('store'), ['user_id' => $this->horsBoucle->id])
            ->assertStatus(422)->assertJson(['message' => __('blog.co_author_not_loop_member')]);
        $this->actingAs($this->auteur)->postJson($this->route('store'), ['user_id' => $invite->id])->assertStatus(422);
        $this->assertSame(0, $this->post->coAuthors()->count());

        $this->actingAs($this->auteur)->postJson($this->route('store'), ['user_id' => $this->membreBoucle->id])->assertSuccessful();
        $this->assertTrue($this->post->coAuthors()->where('user_id', $this->membreBoucle->id)->exists());
    }

    public function test_plusieurs_boucles_membres_de_l_une_ou_l_autre(): void
    {
        $autre = Loop::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->auteur->id]);
        LoopMember::factory()->create(['loop_id' => $autre->id, 'user_id' => $this->horsBoucle->id]);
        $this->post->loops()->attach([$this->boucle->id, $autre->id]);

        $this->assertSame($this->ids($this->membreBoucle, $this->horsBoucle), $this->noms());
    }

    public function test_un_co_auteur_qui_quitte_la_boucle_reste_co_auteur(): void
    {
        $this->post->loops()->attach($this->boucle->id);
        $this->actingAs($this->auteur)->postJson($this->route('store'), ['user_id' => $this->membreBoucle->id])->assertSuccessful();

        LoopMember::where('user_id', $this->membreBoucle->id)->update(['status' => 'left']);

        $this->assertTrue($this->post->coAuthors()->where('user_id', $this->membreBoucle->id)->exists());
        $this->assertTrue($this->membreBoucle->can('update', $this->post->fresh()));
    }

    /** Demande Cyril : chercher sur le prenom et le nom, quel que soit l'ordre ou la casse. */
    public function test_recherche_sur_prenom_et_nom_sans_casse(): void
    {
        $patricia = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Patricia', 'name' => 'Pétillion']);

        $trouve = fn (string $q) => collect($this->actingAs($this->auteur)->getJson($this->route('search').'?q='.urlencode($q))->assertOk()->json('users'))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach (['patricia', 'PATRICIA pétillion', 'pétillion patricia', 'tric illi'] as $q) {
            $this->assertContains((string) $patricia->id, $trouve($q), "Recherche « $q »");
        }
        $this->assertNotContains((string) $patricia->id, $trouve('patricia martin'));
    }

    /** Remarque Cyril : la zone d'ajout dit qui peut etre ajoute. */
    public function test_l_editeur_annonce_le_perimetre_de_la_co_ecriture(): void
    {
        $edition = route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $this->post->slug]);

        $this->actingAs($this->auteur)->get($edition)->assertOk()
            ->assertSee('data-coauthor-add', false)
            ->assertSee(__('blog.co_author_scope_org'), false);

        $this->post->loops()->attach($this->boucle->id);
        $this->actingAs($this->auteur)->get($edition)->assertOk()
            ->assertSee('« '.$this->boucle->name.' »', false)
            ->assertSee(__('blog.co_author_no_match_loop'), false)
            ->assertSee('data-coauthor-only-owner', false);
    }
}
