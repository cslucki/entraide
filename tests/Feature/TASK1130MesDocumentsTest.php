<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\PersonalDocumentsRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * « Mes documents » — la vraie racine personnelle (TASK-1130, decision finale).
 *
 * Ce que cette suite protege, dans l'ordre ou une regression ferait mal :
 * l'invariant d'unicite en base, l'isolation par Organization, l'immutabilite
 * de la racine systeme (nom, partage, suppression, deplacement), et le fait
 * que son CONTENU reste parfaitement ordinaire.
 */
#[Group('task-1130')]
class TASK1130MesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);

        // Les policies Dossier lisent `currentOrganization()` — hors requete
        // HTTP, le tenant courant doit etre pose explicitement, sinon toute
        // verification de droit repondrait « non » pour la mauvaise raison.
        app()->instance('current_organization', $this->org);
    }

    private function racine(?User $user = null, ?Organization $org = null): Dossier
    {
        return app(PersonalDocumentsRoot::class)->resolve(
            ($org ?? $this->org)->id,
            ($user ?? $this->user)->id,
        );
    }

    private function article(string $titre): BlogPost
    {
        return BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'title' => $titre,
            'content' => "Contenu de {$titre}.",
            'status' => 'draft',
        ]);
    }

    private function ouvrirLeModule(?User $user = null, array $query = [])
    {
        return $this->actingAs($user ?? $this->user)
            ->get(route('organization.dossiers.index', ['organization' => $this->org->slug] + $query));
    }

    // ── L'invariant ─────────────────────────────────────────────────────────

    public function test_the_root_is_created_once_and_resolved_again_afterwards(): void
    {
        $premiere = $this->racine();
        $seconde = $this->racine();

        $this->assertTrue($premiere->is($seconde));
        $this->assertSame(1, Dossier::where('organization_id', $this->org->id)
            ->where('owner_id', $this->user->id)
            ->where('system_role', Dossier::SYSTEM_ROLE_PERSONAL_DOCUMENTS)
            ->count());
    }

    public function test_the_database_refuses_a_second_personal_root(): void
    {
        $this->racine();

        // L'invariant n'est pas la discipline des appelants : c'est l'index
        // partiel qui refuse, meme si quelqu'un ecrit la ligne a la main.
        $this->expectException(\Illuminate\Database\QueryException::class);

        Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->user->id,
            'system_role' => Dossier::SYSTEM_ROLE_PERSONAL_DOCUMENTS,
            'name' => 'Doublon',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    public function test_the_root_is_scoped_to_the_organization_and_to_the_user(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreUser = User::factory()->create(['organization_id' => $this->org->id]);

        $mienne = $this->racine();
        $ailleurs = $this->racine($this->user, $autreOrg);
        $duVoisin = $this->racine($autreUser);

        $this->assertFalse($mienne->is($ailleurs));
        $this->assertFalse($mienne->is($duVoisin));
        $this->assertSame($autreOrg->id, $ailleurs->organization_id);
        $this->assertSame($autreUser->id, $duVoisin->owner_id);
    }

    public function test_the_root_satisfies_the_holder_contract(): void
    {
        $racine = $this->racine();

        // Aucune migration de contrainte n'a ete necessaire precisement parce
        // que la racine personnelle est une racine ordinaire.
        $this->assertNull($racine->parent_id);
        $this->assertNull($racine->loop_id);
        $this->assertSame($this->user->id, $racine->owner_id);
        $this->assertTrue($racine->isRoot());
        $this->assertTrue($racine->isPersonalDocumentsRoot());
    }

    public function test_the_root_is_never_identified_by_its_name(): void
    {
        $racine = $this->racine();
        $racine->forceFill(['name' => 'nom ecrit dans une autre langue'])->save();

        $this->assertTrue($racine->fresh()->isPersonalDocumentsRoot());
        $this->assertSame(__('dossiers.my_documents'), $racine->fresh()->displayName());
    }

    // ── L'entree du module ──────────────────────────────────────────────────

    public function test_opening_the_module_lands_directly_in_my_documents(): void
    {
        $this->ouvrirLeModule()
            ->assertOk()
            ->assertSee(__('dossiers.space_my_documents'))
            ->assertSee(__('dossiers.space_shared'))
            ->assertSee(__('dossiers.space_loops'));

        $this->assertNotNull(app(PersonalDocumentsRoot::class)->find($this->org->id, $this->user->id));
    }

    public function test_older_personal_roots_stay_reachable_from_my_documents(): void
    {
        $ancienne = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->user->id,
            'name' => 'Racine historique',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->ouvrirLeModule()->assertOk()->assertSee('Racine historique');

        // Jamais deplacee : elle reste une racine, avec son proprietaire.
        $ancienne->refresh();
        $this->assertNull($ancienne->parent_id);
        $this->assertSame($this->user->id, $ancienne->owner_id);
        $this->assertNull($ancienne->system_role);
    }

    public function test_a_case_b_root_keeps_its_loop_sharing_untouched(): void
    {
        $loop = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general']);
        $casB = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->user->id,
            'name' => 'Partage avec la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $loop->id,
        ]);

        $this->ouvrirLeModule()->assertOk();

        $casB->refresh();
        $this->assertSame($loop->id, $casB->shared_with_loop_id);
        $this->assertSame($this->user->id, $casB->owner_id);
        $this->assertNull($casB->parent_id);
    }

    // ── Ce que la racine refuse ─────────────────────────────────────────────

    public function test_the_root_cannot_be_renamed(): void
    {
        $racine = $this->racine();

        $this->actingAs($this->user)
            ->patch(route('organization.dossiers.update', ['organization' => $this->org->slug, 'dossier' => $racine->getKey()]), [
                'name' => 'Mon nom a moi',
            ])
            ->assertForbidden();

        $this->assertTrue($racine->fresh()->isPersonalDocumentsRoot());
    }

    public function test_the_root_cannot_be_deleted(): void
    {
        $racine = $this->racine();

        $this->actingAs($this->user)
            ->delete(route('organization.dossiers.destroy', ['organization' => $this->org->slug, 'dossier' => $racine->getKey()]))
            ->assertForbidden();

        $this->assertNotNull($racine->fresh());
    }

    public function test_the_root_cannot_be_shared(): void
    {
        $racine = $this->racine();
        $invite = User::factory()->create(['organization_id' => $this->org->id]);

        $this->assertFalse($this->user->can('manageMembers', $racine));
        $this->assertFalse($this->user->can('updateVisibility', $racine));

        $this->actingAs($this->user)
            ->post(route('organization.dossiers.members.store', ['organization' => $this->org->slug, 'dossier' => $racine->getKey()]), [
                'user_id' => $invite->id,
                'role' => 'reader',
            ])
            ->assertForbidden();
    }

    public function test_the_root_cannot_be_moved_under_another_dossier(): void
    {
        $racine = $this->racine();
        $ailleurs = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->user->id,
            'name' => 'Destination',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dossiers.system_root_move_refused');

        $racine->assertValidParent($ailleurs);
    }

    // ── Ce que la racine accepte : son contenu est ordinaire ────────────────

    public function test_a_subfolder_can_be_created_inside_my_documents(): void
    {
        $racine = $this->racine();

        $this->actingAs($this->user)
            ->post(route('organization.dossiers.store', ['organization' => $this->org->slug]), [
                'name' => 'Presse',
                'parent_id' => $racine->getKey(),
            ])
            ->assertRedirect();

        $enfant = Dossier::where('parent_id', $racine->getKey())->first();
        $this->assertNotNull($enfant);
        $this->assertSame('Presse', $enfant->name);
        // Un enfant n'est pas un holder : la contrainte XOR reste satisfaite.
        $this->assertNull($enfant->owner_id);
        $this->assertNull($enfant->loop_id);
    }

    public function test_a_file_lives_directly_in_my_documents(): void
    {
        $racine = $this->racine();

        $fichier = DossierFile::factory()->create([
            'organization_id' => $this->org->id,
            'dossier_id' => $racine->getKey(),
            'uploaded_by' => $this->user->id,
            'display_name' => 'convention.pdf',
        ]);

        $this->assertTrue($this->user->can('manageFiles', $racine));
        $this->assertSame($racine->getKey(), $fichier->dossier_id);
        $this->assertTrue($racine->files()->whereKey($fichier->getKey())->exists());
    }

    public function test_an_article_can_be_attached_to_my_documents(): void
    {
        $racine = $this->racine();
        $article = $this->article('Editorial de rentree');

        $this->actingAs($this->user)
            ->post(route('organization.dossiers.articles.store', ['organization' => $this->org->slug, 'dossier' => $racine->getKey()]), [
                'blog_post_id' => $article->getKey(),
            ])
            ->assertRedirect();

        $this->assertTrue($racine->dossierBlogPosts()->where('blog_post_id', $article->getKey())->exists());
    }

    public function test_a_series_can_be_created_in_my_documents(): void
    {
        $racine = $this->racine();
        $article = $this->article('Premier episode');
        $racine->dossierBlogPosts()->create([
            'organization_id' => $this->org->id,
            'blog_post_id' => $article->getKey(),
            'added_by' => $this->user->id,
            'position' => 0,
        ]);

        $this->actingAs($this->user)
            ->postJson(route('organization.dossiers.series.store', ['organization' => $this->org->slug, 'dossier' => $racine->getKey()]), [
                'name' => 'Revue de presse',
            ])
            ->assertSuccessful();

        $this->assertTrue($racine->articleSeries()->exists());
    }

    // ── Les vues d'agregation ───────────────────────────────────────────────

    public function test_shared_is_a_view_with_two_sub_views_and_never_a_folder(): void
    {
        $this->ouvrirLeModule(query: ['espace' => 'partages'])
            ->assertOk()
            ->assertSee(__('dossiers.shared_with_me'))
            ->assertSee(__('dossiers.shared_by_me'));

        // Aucune ligne « Partages » n'existe en base : c'est une vue.
        $this->assertSame(0, Dossier::where('organization_id', $this->org->id)
            ->where('name', __('dossiers.space_shared'))
            ->count());
    }

    public function test_loops_view_shows_the_loop_and_a_way_back_to_it(): void
    {
        $loop = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general', 'name' => 'Boucle Centre-ville']);
        $loop->members()->create(['organization_id' => $this->org->id, 'user_id' => $this->user->id, 'role' => 'owner', 'status' => 'active']);
        Dossier::create([
            'organization_id' => $this->org->id,
            'loop_id' => $loop->getKey(),
            'name' => 'Drive',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);

        $this->ouvrirLeModule(query: ['espace' => 'boucles'])
            ->assertOk()
            ->assertSee('Boucle Centre-ville')
            ->assertSee(__('dossiers.loop_visit'));
    }

    // ── Isolation ───────────────────────────────────────────────────────────

    public function test_another_users_root_is_never_visible(): void
    {
        $voisin = User::factory()->create(['organization_id' => $this->org->id]);
        $racineDuVoisin = $this->racine($voisin);
        $racineDuVoisin->forceFill(['name' => 'Racine du voisin'])->save();

        $this->ouvrirLeModule()->assertOk()->assertDontSee('Racine du voisin');

        $this->actingAs($this->user)
            ->get(route('organization.dossiers.show', ['organization' => $this->org->slug, 'dossier' => $racineDuVoisin->getKey()]))
            ->assertForbidden();
    }
}
