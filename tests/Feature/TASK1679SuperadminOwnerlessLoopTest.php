<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Policies\LoopPolicy;
use App\Services\LoopService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1679 — un SuperAdmin cree une Boucle SANS owner initial, dans une
 * Organization qui n'est pas la sienne.
 *
 * Le schema n'a jamais exige d'owner (`loops.created_by` nullable, zero
 * contrainte sur `loop_members`) : le verrou etait purement applicatif.
 * Ces tests prouvent que sa levee est CONFINEE a la branche SuperAdmin du
 * store admin, et que rien d'autre ne bouge — gardes same-org, garde du
 * dernier owner, permissions tenant.
 *
 * Auteur du document racine : le cahier demandait NULL, mais
 * `blog_posts.user_id` est NOT NULL (invariant T1534, « l'auteur est
 * l'humain qui valide ») et la migration est interdite ici. L'auteur d'une
 * Boucle sans owner est donc le createur administratif — deviation assumee,
 * verifiee telle quelle ci-dessous.
 */
class TASK1679SuperadminOwnerlessLoopTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->orgA->id,
        ]);
    }

    private function storeLoop(array $payload, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->superAdmin)->post(route('admin.loops.store'), array_merge([
            'name' => 'Entraide formateurs',
            'visibility' => 'private',
            'organization_id' => $this->orgB->id,
        ], $payload));
    }

    /** La ligne `loops` brute, hors de tout global scope. */
    private function loopRow(string $name): ?object
    {
        return DB::table('loops')->where('name', $name)->first();
    }

    public function test_superadmin_creates_ownerless_loop_in_a_foreign_organization(): void
    {
        $this->storeLoop([])->assertRedirect();

        $loop = $this->loopRow('Entraide formateurs');
        $this->assertNotNull($loop);
        $this->assertSame($this->orgB->id, $loop->organization_id);

        // Createur administratif, pas owner metier.
        $this->assertSame($this->superAdmin->id, $loop->created_by);

        // ZERO membership a la naissance — pas seulement zero owner.
        $this->assertSame(0, DB::table('loop_members')->where('loop_id', $loop->id)->count());

        // Le document racine existe quand meme : Dossier racine pointe sur un
        // Article publie, dans l'Organization CIBLE.
        $dossier = DB::table('dossiers')->where('loop_id', $loop->id)->first();
        $this->assertNotNull($dossier);
        $this->assertNotNull($dossier->root_blog_post_id);

        $post = DB::table('blog_posts')->where('id', $dossier->root_blog_post_id)->first();
        $this->assertNotNull($post);
        $this->assertSame($this->orgB->id, $post->organization_id);

        // Deviation documentee (cf. docblock de classe) : auteur = createur
        // administratif, car `blog_posts.user_id` est NOT NULL.
        $this->assertSame($this->superAdmin->id, $post->user_id);

        // Le preset de cartes du type s'applique comme pour toute Boucle.
        $this->assertGreaterThan(0, DB::table('loop_cards')->where('loop_id', $loop->id)->count());
    }

    public function test_superadmin_creation_with_an_owner_keeps_the_existing_behavior(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->orgB->id]);

        $this->storeLoop(['name' => 'Boucle avec owner', 'owner_id' => $owner->id])->assertRedirect();

        $loop = $this->loopRow('Boucle avec owner');
        $this->assertNotNull($loop);

        // `created_by` reste l'owner, comme avant TASK-1679.
        $this->assertSame($owner->id, $loop->created_by);

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_an_owner_from_another_organization_is_still_refused(): void
    {
        $orgC = Organization::factory()->create(['is_active' => true]);
        $strangerOwner = User::factory()->create(['organization_id' => $orgC->id]);

        $this->storeLoop(['name' => 'Boucle interdite', 'owner_id' => $strangerOwner->id])
            ->assertStatus(403);

        $this->assertNull($this->loopRow('Boucle interdite'));
    }

    public function test_a_non_superadmin_cannot_create_an_ownerless_loop(): void
    {
        // Le middleware `admin` (is_admin strict) refuse AVANT la validation :
        // la levee du caractere obligatoire de owner_id n'ouvre aucune porte
        // a qui n'est pas SuperAdmin.
        $member = User::factory()->create([
            'is_admin' => false,
            'organization_id' => $this->orgB->id,
        ]);

        $this->storeLoop(['name' => 'Boucle membre'], $member)->assertStatus(403);

        $this->assertNull($this->loopRow('Boucle membre'));
    }

    public function test_an_owner_is_assignable_later_from_the_admin_and_same_org_stays_enforced(): void
    {
        $this->storeLoop([])->assertRedirect();
        $loopId = $this->loopRow('Entraide formateurs')->id;
        $loop = Loop::withoutGlobalScopes()->findOrFail($loopId);

        // Un vrai membre de l'Organization cible devient owner apres coup.
        $futureOwner = User::factory()->create(['organization_id' => $this->orgB->id]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.members.add', $loop), [
                'user_id' => $futureOwner->id,
                'role' => 'owner',
            ])
            ->assertRedirect(route('admin.loops.edit', $loop));

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $futureOwner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        // Cross-org : toujours refuse, par la validation du controller.
        $orgC = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $orgC->id]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.members.add', $loop), [
                'user_id' => $stranger->id,
                'role' => 'owner',
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $stranger->id,
        ]);
    }

    public function test_an_ownerless_loop_renders_in_catalog_and_presentation_without_error(): void
    {
        $this->storeLoop(['name' => 'Boucle publique sans owner', 'visibility' => 'public'])->assertRedirect();
        // Une seconde Boucle : a Boucle accessible unique, le catalogue
        // REDIRIGE vers elle au lieu de lister — deux Boucles forcent la liste,
        // et la carte 0-owner est rendue deux fois plutot qu'une.
        $this->storeLoop(['name' => 'Seconde boucle sans owner', 'visibility' => 'public'])->assertRedirect();
        $loopId = $this->loopRow('Boucle publique sans owner')->id;

        // `catalog-card` et `presentation` lisent `owner?->user` : un rendu
        // complet prouve que zero owner ne produit ni 500 ni derefencement.
        $member = User::factory()->create(['organization_id' => $this->orgB->id]);
        app()->instance('current_organization', $this->orgB);

        $this->actingAs($member)
            ->get(route('organization.loops.index', ['organization' => $this->orgB->slug]))
            ->assertOk()
            ->assertSee('Boucle publique sans owner');

        $this->actingAs($member)
            ->get(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $loopId]))
            ->assertOk();
    }

    public function test_the_last_active_owner_guard_is_untouched(): void
    {
        $owner = User::factory()->create(['organization_id' => $this->orgB->id]);
        $loop = app(LoopService::class)->createLoopForOrg($owner, $this->orgB->id, 'Boucle gardee');

        $member = LoopMember::withoutGlobalScopes()
            ->where('loop_id', $loop->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove the last active owner');

        app(LoopService::class)->removeMember($member);
    }

    public function test_no_tenant_permission_is_widened_by_an_ownerless_loop(): void
    {
        $this->storeLoop(['name' => 'Boucle sans autorite'])->assertRedirect();
        $loop = Loop::withoutGlobalScopes()->findOrFail($this->loopRow('Boucle sans autorite')->id);

        $resolver = app(LoopPermissionResolver::class);
        $policy = app(LoopPolicy::class);

        // Un etranger au tenant n'y gagne rien.
        $orgC = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $orgC->id]);
        $this->assertFalse($resolver->can($stranger, $loop, 'loops.archive'));
        $this->assertFalse($policy->viewWorkspace($stranger, $loop));

        // Un simple membre de l'Organization cible non plus : sans membership,
        // ni workspace ni archivage — l'absence d'owner ne distribue aucune
        // autorite par defaut.
        $member = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->assertFalse($resolver->can($member, $loop, 'loops.archive'));
        $this->assertFalse($policy->viewWorkspace($member, $loop));
    }

    public function test_the_admin_edit_screen_links_to_the_loop_front_page(): void
    {
        $this->storeLoop([])->assertRedirect();
        $loop = Loop::withoutGlobalScopes()->findOrFail($this->loopRow('Entraide formateurs')->id);

        // Le lien doit etre l'URL org-scopee (workspaceUrl) : l'admin
        // plateforme regarde une Boucle d'une AUTRE Organization, la route
        // courte resoudrait la sienne.
        $expected = route('organization.loops.show', [
            'organization' => $this->orgB->slug,
            'loop' => $loop->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.edit', $loop))
            ->assertOk()
            ->assertSee($expected, false);
    }

    public function test_the_service_refuses_a_loop_with_neither_owner_nor_creator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(LoopService::class)->createLoopForOrg(null, $this->orgB->id, 'Boucle orpheline');
    }
}
