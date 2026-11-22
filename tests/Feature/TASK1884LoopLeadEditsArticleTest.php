<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1884 correctif 6 (#3) — le proprietaire et les animateurs d'une Boucle
 * modifient les articles de CETTE Boucle comme leur auteur (modifier, publier,
 * gerer les taches) ; supprimer et gerer les co-auteurs restent a l'auteur.
 */
class TASK1884LoopLeadEditsArticleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $proprietaire;

    private User $animateur;

    private User $membre;

    private Loop $boucle;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true, 'is_active' => true]);
        app()->instance('current_organization', $this->org);
        [$this->auteur, $this->proprietaire, $this->animateur, $this->membre] = User::factory()->count(4)->create(['organization_id' => $this->org->id])->all();

        $this->boucle = Loop::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->proprietaire->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $this->boucle->id, 'user_id' => $this->proprietaire->id]);
        LoopMember::factory()->create(['loop_id' => $this->boucle->id, 'user_id' => $this->animateur->id, 'role' => 'facilitator']);
        LoopMember::factory()->create(['loop_id' => $this->boucle->id, 'user_id' => $this->membre->id]);
        LoopMember::factory()->create(['loop_id' => $this->boucle->id, 'user_id' => $this->auteur->id]);

        $this->post = BlogPost::create([
            'user_id' => $this->auteur->id, 'organization_id' => $this->org->id,
            'title' => 'Article de la boucle', 'summary' => 'R', 'content' => 'x', 'status' => 'draft',
        ]);
        $this->post->loops()->attach($this->boucle->id);
    }

    public function test_proprietaire_et_animateur_modifient_et_publient_le_membre_non(): void
    {
        foreach ([$this->proprietaire, $this->animateur] as $chef) {
            $this->assertTrue($chef->can('update', $this->post), 'Chef de Boucle : modifier');
            $this->assertFalse($chef->can('delete', $this->post), 'Supprimer reste a l\'auteur');
            $this->assertFalse($chef->can('manageCoAuthors', $this->post), 'Les co-auteurs restent a l\'auteur');
            $this->actingAs($chef)->get(route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $this->post->slug]))
                ->assertOk()->assertSee('data-todo-assign-picker', false);
        }

        $this->assertFalse($this->membre->can('update', $this->post));
        $this->actingAs($this->membre)->get(route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $this->post->slug]))->assertForbidden();

        $this->actingAs($this->animateur)->patch(route('organization.blog.publish', ['organization' => $this->org->slug, 'post' => $this->post->slug]))->assertRedirect();
        $this->assertSame('published', $this->post->fresh()->status);
    }

    public function test_le_droit_vaut_aussi_par_un_dossier_et_jamais_pour_une_autre_boucle_ni_une_archive(): void
    {
        // Proprietaire d'une AUTRE Boucle : refuse.
        $autre = Loop::factory()->create(['organization_id' => $this->org->id]);
        $chefAilleurs = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $autre->id, 'user_id' => $chefAilleurs->id]);
        $this->assertFalse($chefAilleurs->can('update', $this->post));

        // Article rattache par un DOSSIER de la Boucle (sans pivot) : le droit vaut.
        $parDossier = BlogPost::create(['user_id' => $this->auteur->id, 'organization_id' => $this->org->id, 'title' => 'Doc', 'summary' => 'R', 'content' => 'x', 'status' => 'draft']);
        $dossier = Dossier::factory()->create(['organization_id' => $this->org->id, 'owner_id' => $this->proprietaire->id, 'loop_id' => $this->boucle->id]);
        DossierBlogPost::create(['organization_id' => $this->org->id, 'dossier_id' => $dossier->id, 'blog_post_id' => $parDossier->id, 'added_by' => $this->auteur->id, 'position' => 1]);
        $this->assertTrue($this->animateur->can('update', $parDossier->fresh()));

        // Boucle archivee : lecture seule, plus de droit d'ecriture.
        $this->boucle->forceFill(['status' => 'archived'])->save();
        $this->assertFalse($this->proprietaire->fresh()->can('update', $this->post->fresh()));

        // Article non lie a une Boucle : seul son auteur.
        $libre = BlogPost::create(['user_id' => $this->auteur->id, 'organization_id' => $this->org->id, 'title' => 'Libre', 'summary' => 'R', 'content' => 'x', 'status' => 'draft']);
        $this->assertFalse($this->animateur->can('update', $libre));
    }

    public function test_le_chef_de_boucle_gere_les_taches_comme_l_auteur(): void
    {
        $this->post->coAuthors()->attach($this->membre->id, ['role' => 'coauthor', 'added_by' => $this->auteur->id]);

        $id = $this->actingAs($this->proprietaire)->postJson("/blog/{$this->post->slug}/todos", ['title' => 'Relire', 'assigned_to' => $this->membre->id])
            ->assertCreated()->assertJsonPath('todo.can_assign', true)->json('todo.id');

        $this->actingAs($this->animateur)->putJson("/blog/{$this->post->slug}/todos/{$id}", ['assigned_to' => $this->auteur->id])->assertOk();
        $this->assertDatabaseHas('blog_todos', ['id' => $id, 'assigned_to' => $this->auteur->id]);

        // Le co-auteur simple, lui, ne reattribue pas.
        $this->actingAs($this->membre)->putJson("/blog/{$this->post->slug}/todos/{$id}", ['assigned_to' => $this->membre->id])->assertForbidden();
    }
}
