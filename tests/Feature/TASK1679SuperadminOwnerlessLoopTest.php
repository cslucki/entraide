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
 * Recette MASTER (reprise) — deux decisions de plus :
 * 1. le document racine EST le Manifeste visible ; depuis T1332 aucun preset
 *    ne l'active par defaut, il ne nait donc PLUS a la creation quand la Card
 *    `core.manifesto` n'est pas active. Le Dossier racine, structurel, nait
 *    toujours. L'arbitrage « auteur NULL vs SuperAdmin » disparait pour les
 *    Boucles sans Manifeste ; pour un type a Manifeste ON, l'auteur reste un
 *    humain reel (`blog_posts.user_id` NOT NULL, invariant T1534) — le
 *    createur administratif quand il n'y a pas d'owner.
 * 2. le SuperAdmin INSPECTE une Boucle de n'importe quel tenant (page de
 *    presentation, garde T1658) sans membership, sans workspace primaire,
 *    sans droit d'ecrire — les ecritures gardent la garde d'appartenance.
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

        // Le Dossier racine, structurel, existe — mais AUCUN Manifeste : le
        // type par defaut (Communaute) n'active pas `core.manifesto`, donc
        // aucun document racine ne nait (decision produit, recette T1679).
        $dossier = DB::table('dossiers')->where('loop_id', $loop->id)->first();
        $this->assertNotNull($dossier);
        $this->assertNull($dossier->root_blog_post_id);
        $this->assertNull($loop->manifesto_blog_post_id);
        $this->assertSame(0, DB::table('blog_posts')
            ->where('organization_id', $this->orgB->id)
            ->where('audience', 'loop')
            ->count());

        // Le preset de cartes du type s'applique comme pour toute Boucle.
        $this->assertGreaterThan(0, DB::table('loop_cards')->where('loop_id', $loop->id)->count());
    }

    public function test_a_type_with_the_manifesto_card_on_still_gets_its_document_at_creation(): void
    {
        // Un type dont le preset active `core.manifesto` garde le comportement
        // historique : document racine cree, publie, dans l'Organization
        // cible. Sans owner, son auteur est le createur administratif —
        // `blog_posts.user_id` est NOT NULL (invariant T1534).
        $settings = app(\App\Services\LoopTypeSettingsService::class);
        $settings->save('general', array_merge($settings->cardsFor('general'), ['core.manifesto']), $settings->isAvailable('general'));

        $this->storeLoop(['name' => 'Boucle a manifeste'])->assertRedirect();

        $loop = $this->loopRow('Boucle a manifeste');
        $dossier = DB::table('dossiers')->where('loop_id', $loop->id)->first();
        $this->assertNotNull($dossier->root_blog_post_id);

        $post = DB::table('blog_posts')->where('id', $dossier->root_blog_post_id)->first();
        $this->assertSame($this->orgB->id, $post->organization_id);
        $this->assertSame('published', $post->status);
        $this->assertSame($this->superAdmin->id, $post->user_id);
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

    public function test_the_superadmin_inspects_a_foreign_loop_without_any_implicit_participation(): void
    {
        $this->storeLoop(['name' => 'Boucle inspectee', 'description' => 'Entraide entre formateurs OFSH.'])->assertRedirect();
        $loopId = $this->loopRow('Boucle inspectee')->id;

        app()->instance('current_organization', $this->orgB);

        // VOIR : 200, page de PRESENTATION (jamais le workspace), ou la
        // description reste lisible — sans Manifeste, elle ne fait doublon
        // avec rien.
        $this->actingAs($this->superAdmin)
            ->get(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $loopId]))
            ->assertOk()
            ->assertViewIs('loops.presentation')
            ->assertSee('Entraide entre formateurs OFSH.');

        // Aucune participation implicite : la visite ne cree aucun membership.
        $this->assertSame(0, DB::table('loop_members')
            ->where('loop_id', $loopId)
            ->where('user_id', $this->superAdmin->id)
            ->count());

        // ECRIRE reste ferme : ChatLoop garde assertUserBelongsToOrganization.
        $this->actingAs($this->superAdmin)
            ->post(route('organization.loops.messages.store', ['organization' => $this->orgB->slug, 'loop' => $loopId]), [
                'body' => 'intrusion',
            ])
            ->assertNotFound();

        // Un membre etranger ORDINAIRE reste refuse des la lecture.
        $orgC = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $orgC->id]);
        $this->actingAs($stranger)
            ->get(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $loopId]))
            ->assertNotFound();
    }

    public function test_the_superadmin_does_not_inherit_a_primary_loop_workspace(): void
    {
        // La Boucle primaire ouvre son workspace aux membres de SON
        // Organization sans membership. Inspecter n'est pas participer : le
        // SuperAdmin hors tenant reste sur la presentation, le membre du
        // tenant garde son acces.
        $this->storeLoop(['name' => 'Boucle primaire'])->assertRedirect();
        $loopId = $this->loopRow('Boucle primaire')->id;
        $this->orgB->update(['primary_loop_id' => $loopId]);

        app()->instance('current_organization', $this->orgB);

        $this->actingAs($this->superAdmin)
            ->get(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $loopId]))
            ->assertOk()
            ->assertViewIs('loops.presentation');

        $member = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->actingAs($member)
            ->get(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $loopId]))
            ->assertOk()
            ->assertViewIs('loops.show');
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
