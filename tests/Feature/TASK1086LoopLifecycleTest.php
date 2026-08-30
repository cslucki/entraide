<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopPermissionSettingsService;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopLifecycleService;
use App\Support\Loops\LoopPermissionResolver;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Archiver une Boucle, la reactiver, et ne rien pouvoir y ecrire entre les deux.
 *
 * Ce qui est defendu ici plus que le reste : l'archivage ne supprime rien, et la
 * lecture seule tient **cote serveur**, sur les routes directes — pas seulement
 * sur les boutons qu'on a pense a cacher.
 */
class TASK1086LoopLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $orgAdmin;

    private User $owner;

    private User $facilitator;

    private User $member;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);

        $this->owner = $this->userInOrg();
        $this->facilitator = $this->userInOrg();
        $this->member = $this->userInOrg();

        $this->loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle de test',
            'slug' => 'boucle-de-test',
            // Type « project » et non « general » : son socle porte Roadmap
            // (et, depuis TASK-1332, plus le Manifeste par defaut — active
            // explicitement ci-dessous). Le socle « general » n'en prescrit
            // aucune, et les permissions qui dependent d'une Card seraient
            // refusees pour cette raison plutot que pour l'archivage — ce qui
            // ne prouverait rien.
            'type' => 'project',
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $this->owner->id,
        ]);

        app(LoopTypeRegistry::class)->applyPreset($this->loop);
        app(LoopCardCompositionService::class)->enable($this->loop, 'core.manifesto');

        $this->join($this->owner, 'owner');
        $this->join($this->facilitator, 'facilitator');
        $this->join($this->member, 'member');

        app()->instance('current_organization', $this->org);
    }

    private function userInOrg(?Organization $org = null): User
    {
        return User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
    }

    private function join(User $user, string $role): LoopMember
    {
        return LoopMember::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function lifecycle(): LoopLifecycleService
    {
        return app(LoopLifecycleService::class);
    }

    private function archiveUrl(): string
    {
        return route('organization.loops.archive', [
            'organization' => $this->org->slug, 'loop' => $this->loop->id,
        ]);
    }

    private function reactivateUrl(): string
    {
        return route('organization.loops.reactivate', [
            'organization' => $this->org->slug, 'loop' => $this->loop->id,
        ]);
    }

    // ── Qui peut archiver ───────────────────────────────────────────────────

    public function test_the_owner_archives_and_reactivates(): void
    {
        $this->actingAs($this->owner)->post($this->archiveUrl())->assertRedirect();

        $this->assertTrue($this->loop->fresh()->isArchived());
        $this->assertNotNull($this->loop->fresh()->archived_at);
        $this->assertSame($this->owner->id, $this->loop->fresh()->archived_by);

        $this->actingAs($this->owner)->post($this->reactivateUrl())->assertRedirect();

        $fresh = $this->loop->fresh();
        $this->assertTrue($fresh->isActive());
        // La trace decrit l'archivage en cours ; il n'y en a plus.
        $this->assertNull($fresh->archived_at);
        $this->assertNull($fresh->archived_by);
    }

    public function test_the_facilitator_may_not_archive(): void
    {
        $this->assertFalse($this->lifecycle()->canArchive($this->facilitator, $this->loop));

        $this->actingAs($this->facilitator)->post($this->archiveUrl())->assertForbidden();
        $this->assertTrue($this->loop->fresh()->isActive());
    }

    public function test_a_plain_member_may_not_archive(): void
    {
        $this->assertFalse($this->lifecycle()->canArchive($this->member, $this->loop));

        $this->actingAs($this->member)->post($this->archiveUrl())->assertForbidden();
        $this->assertTrue($this->loop->fresh()->isActive());
    }

    public function test_the_organization_admin_archives(): void
    {
        $this->assertTrue($this->lifecycle()->canArchive($this->orgAdmin, $this->loop));

        $this->actingAs($this->orgAdmin)->post($this->archiveUrl())->assertRedirect();
        $this->assertTrue($this->loop->fresh()->isArchived());
    }

    public function test_the_super_admin_archives(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);

        $this->assertTrue($this->lifecycle()->canArchive($superAdmin, $this->loop));
        $this->assertSame(
            LoopLifecycleService::RESULT_OK,
            $this->lifecycle()->archive($superAdmin, $this->loop),
        );
    }

    public function test_someone_from_another_organization_is_refused(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->assertFalse($this->lifecycle()->canArchive($stranger, $this->loop));
        $this->assertSame(
            LoopLifecycleService::RESULT_DENIED,
            $this->lifecycle()->archive($stranger, $this->loop),
        );
        $this->assertTrue($this->loop->fresh()->isActive());
    }

    // ── Ce que l'archivage fait, et ne fait pas ─────────────────────────────

    public function test_archiving_deletes_nothing(): void
    {
        $membersBefore = LoopMember::where('loop_id', $this->loop->id)->count();
        $cardsBefore = $this->loop->cards()->count();

        $this->lifecycle()->archive($this->owner, $this->loop);

        $this->assertSame($membersBefore, LoopMember::where('loop_id', $this->loop->id)->count());
        $this->assertSame($cardsBefore, $this->loop->fresh()->cards()->count());
        $this->assertDatabaseHas('loops', ['id' => $this->loop->id, 'status' => 'archived']);
    }

    public function test_reactivating_replays_no_preset_and_relights_no_card(): void
    {
        // Une Card eteinte a la main avant l'archivage.
        app(LoopCardCompositionService::class)
            ->disable($this->loop, 'core.roadmap');

        $this->lifecycle()->archive($this->owner, $this->loop);
        $this->lifecycle()->reactivate($this->owner, $this->loop);

        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $this->loop->id,
            'card_key' => 'core.roadmap',
            'enabled' => false,
        ]);
    }

    public function test_the_impact_names_the_last_active_loop(): void
    {
        $this->assertTrue($this->lifecycle()->impactOf($this->loop)['last_active']);

        Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Une autre', 'slug' => 'une-autre',
            'type' => 'general', 'status' => 'active', 'visibility' => 'private',
            'created_by' => $this->owner->id,
        ]);

        $this->assertFalse($this->lifecycle()->impactOf($this->loop->fresh())['last_active']);
    }

    public function test_archiving_twice_is_reported_not_duplicated(): void
    {
        $this->assertSame(LoopLifecycleService::RESULT_OK, $this->lifecycle()->archive($this->owner, $this->loop));
        $this->assertSame(LoopLifecycleService::RESULT_ALREADY, $this->lifecycle()->archive($this->owner, $this->loop->fresh()));
    }

    public function test_an_archived_loop_leaves_the_active_listing(): void
    {
        $this->lifecycle()->archive($this->owner, $this->loop);

        $this->actingAs($this->member)
            ->get(route('organization.loops.index', ['organization' => $this->org->slug]))
            ->assertOk()
            ->assertDontSee('Boucle de test');
    }

    // ── Lecture seule ───────────────────────────────────────────────────────

    public function test_every_write_permission_is_refused_once_archived(): void
    {
        $this->lifecycle()->archive($this->owner, $this->loop);
        $loop = $this->loop->fresh();
        $resolver = app(LoopPermissionResolver::class);

        foreach (['chatloop.post', 'manifesto.update', 'roadmap.manage',
            'loop_members.invite', 'loops.update_identity', 'loops.manage_owners'] as $permission) {
            $this->assertFalse(
                $resolver->can($this->owner, $loop, $permission),
                "« {$permission} » devrait être refusée sur une Boucle archivée.",
            );
        }
    }

    public function test_reads_survive_archiving(): void
    {
        $this->lifecycle()->archive($this->owner, $this->loop);
        $loop = $this->loop->fresh();
        $resolver = app(LoopPermissionResolver::class);

        foreach (['loops.view', 'chatloop.view', 'manifesto.view',
            'roadmap.view', 'loop_members.view'] as $permission) {
            $this->assertTrue(
                $resolver->can($this->member, $loop, $permission),
                "« {$permission} » devrait rester permise sur une Boucle archivée.",
            );
        }
    }

    public function test_even_a_super_admin_may_not_write_into_an_archive(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);
        $this->lifecycle()->archive($this->owner, $this->loop);

        $resolver = app(LoopPermissionResolver::class);
        $loop = $this->loop->fresh();

        $this->assertFalse($resolver->can($superAdmin, $loop, 'chatloop.post'));
        // Sauf celle-ci : c'est ce par quoi on reactive.
        $this->assertTrue($resolver->can($superAdmin, $loop, 'loops.archive'));
    }

    public function test_an_unflagged_capability_fails_closed(): void
    {
        // Le drapeau `read` est ce qui laisse passer ; l'oublier refuse. On le
        // verifie sur une permission d'ecriture reelle plutot que sur une cle
        // inventee, qui serait refusee pour une autre raison.
        $this->lifecycle()->archive($this->owner, $this->loop);

        $this->assertFalse(app(LoopPermissionSettingsService::class)->isReadOnly('chatloop.post'));
        $this->assertFalse(
            app(LoopPermissionResolver::class)->can($this->owner, $this->loop->fresh(), 'chatloop.post'),
        );
    }

    public function test_the_identity_route_refuses_an_archived_loop(): void
    {
        $this->lifecycle()->archive($this->owner, $this->loop);

        // Route directe, pas un bouton masque.
        $this->actingAs($this->owner)
            ->put(route('organization.loops.update', [
                'organization' => $this->org->slug, 'loop' => $this->loop->id,
            ]), ['name' => 'Renommee'])
            ->assertForbidden();

        $this->assertSame('Boucle de test', $this->loop->fresh()->name);
    }

    public function test_the_invitation_route_refuses_an_archived_loop(): void
    {
        $this->lifecycle()->archive($this->owner, $this->loop);

        $this->actingAs($this->owner)
            ->post(route('organization.loops.invitations.store', [
                'organization' => $this->org->slug, 'loop' => $this->loop->id,
            ]), ['email' => 'invite@example.test'])
            ->assertForbidden();
    }

    public function test_reactivation_restores_writing(): void
    {
        $this->lifecycle()->archive($this->owner, $this->loop);
        $this->lifecycle()->reactivate($this->owner, $this->loop);

        $this->assertTrue(
            app(LoopPermissionResolver::class)->can($this->owner, $this->loop->fresh(), 'chatloop.post'),
        );
    }

    public function test_an_active_loop_stays_fully_writable(): void
    {
        // Le faux positif qu'on redoute : une garde trop large rendrait une
        // Boucle active inutilisable.
        $resolver = app(LoopPermissionResolver::class);

        $this->assertTrue($resolver->can($this->owner, $this->loop, 'chatloop.post'));
        $this->assertTrue($resolver->can($this->owner, $this->loop, 'loops.update_identity'));
        $this->assertTrue($resolver->can($this->member, $this->loop, 'chatloop.post'));
    }

    // ── Permissions ─────────────────────────────────────────────────────────

    public function test_the_owner_no_longer_configures_the_cards(): void
    {
        $this->assertFalse(
            app(LoopPermissionResolver::class)->can($this->owner, $this->loop, 'loops.manage_cards'),
        );
    }

    public function test_the_administrators_still_configure_the_cards(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->org->id]);
        $resolver = app(LoopPermissionResolver::class);

        $this->assertTrue($resolver->can($this->orgAdmin, $this->loop, 'loops.manage_cards'));
        $this->assertTrue($resolver->can($superAdmin, $this->loop, 'loops.manage_cards'));
    }

    public function test_the_owner_keeps_the_archive_permission(): void
    {
        $this->assertTrue(
            app(LoopPermissionResolver::class)->can($this->owner, $this->loop, 'loops.archive'),
        );
    }
}
