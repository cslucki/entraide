<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1680 — le SuperAdmin unique administre les demandes d'adhesion d'une
 * Boucle de n'importe quelle Organization, sans devenir membre du tenant ni
 * de la Boucle, sans compte « Cyril par Organization ».
 *
 * L'autorite n'a PAS bouge : manageJoinRequests -> resolveur canonique
 * (is_admin accorde, etranger ordinaire refuse, membre sans permission
 * refuse, archive refusee). Seul le verrou redondant d'appartenance en tete
 * d'accept/reject est remplace par la garde d'administration plateforme, et
 * le cockpit admin gagne le panneau qui rend la capacite UTILISABLE
 * (capacite non exposee = capacite absente).
 */
class TASK1680SuperadminJoinRequestsTest extends TestCase
{
    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private Loop $loop;

    private User $requester;

    private LoopJoinRequest $joinRequest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->orgA->id,
        ]);

        // La Boucle OFSH type : creee par le SuperAdmin SANS owner (T1679).
        $this->loop = app(LoopService::class)->createLoopForOrg(
            null, $this->orgB->id, 'Entraide formateurs', null, 'private',
            null, Loop::ACCESS_REQUEST, null, $this->superAdmin,
        );

        $this->requester = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->joinRequest = app(LoopService::class)->requestToJoin($this->loop, $this->requester, 'Je forme des formateurs.');
    }

    private function accept(LoopJoinRequest $jr, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->superAdmin)->post(
            route('organization.loop-join-requests.accept', ['organization' => $this->orgB->slug, 'joinRequest' => $jr]),
        );
    }

    private function reject(LoopJoinRequest $jr, ?User $actor = null)
    {
        return $this->actingAs($actor ?? $this->superAdmin)->post(
            route('organization.loop-join-requests.reject', ['organization' => $this->orgB->slug, 'joinRequest' => $jr]),
        );
    }

    public function test_the_superadmin_accepts_a_cross_org_request_without_joining_anything(): void
    {
        $this->accept($this->joinRequest)->assertRedirect();

        // Le DEMANDEUR devient membre — et lui seul.
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->requester->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        // Le SuperAdmin ne rejoint RIEN, nulle part, et ne change pas d'org.
        $this->assertSame(0, DB::table('loop_members')->where('user_id', $this->superAdmin->id)->count());
        $this->assertSame($this->orgA->id, $this->superAdmin->fresh()->organization_id);

        // La decision est tracee a son nom.
        $row = DB::table('loop_join_requests')->where('id', $this->joinRequest->id)->first();
        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $row->status);
        $this->assertSame($this->superAdmin->id, $row->decided_by);
    }

    public function test_the_superadmin_rejects_a_cross_org_request(): void
    {
        $this->reject($this->joinRequest)->assertRedirect();

        $row = DB::table('loop_join_requests')->where('id', $this->joinRequest->id)->first();
        $this->assertSame(LoopJoinRequest::STATUS_REJECTED, $row->status);
        $this->assertSame($this->superAdmin->id, $row->decided_by);

        $this->assertSame(0, DB::table('loop_members')->where('loop_id', $this->loop->id)->count());
    }

    public function test_an_ordinary_stranger_is_still_refused(): void
    {
        $orgC = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $orgC->id]);

        $this->accept($this->joinRequest, $stranger)->assertNotFound();
        $this->reject($this->joinRequest, $stranger)->assertNotFound();

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $this->joinRequest->fresh()->status);
    }

    public function test_a_plain_member_without_the_permission_is_refused(): void
    {
        // Membre de l'Organization cible, mais ni owner/facilitator de la
        // Boucle ni admin d'org : la garde d'administration le laisse passer
        // (il appartient au tenant), la POLICY le refuse — preuve que
        // l'autorite reelle n'a pas bouge.
        $plainMember = User::factory()->create(['organization_id' => $this->orgB->id]);

        $this->accept($this->joinRequest, $plainMember)->assertForbidden();
        $this->reject($this->joinRequest, $plainMember)->assertForbidden();

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $this->joinRequest->fresh()->status);
    }

    public function test_an_archived_loop_still_refuses_even_the_superadmin(): void
    {
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->accept($this->joinRequest)->assertForbidden();

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $this->joinRequest->fresh()->status);
        $this->assertSame(0, DB::table('loop_members')->where('loop_id', $this->loop->id)->count());
    }

    public function test_a_join_request_from_another_organization_is_refused_by_the_object_guard(): void
    {
        // Demande d'un AUTRE tenant, postee sous le slug d'orgB : la garde
        // d'objet (organization du JoinRequest) refuse, SuperAdmin compris.
        $orgC = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $loopC = app(LoopService::class)->createLoopForOrg(
            null, $orgC->id, 'Boucle C', null, 'private',
            null, Loop::ACCESS_REQUEST, null, $this->superAdmin,
        );
        $requesterC = User::factory()->create(['organization_id' => $orgC->id]);
        $forged = app(LoopService::class)->requestToJoin($loopC, $requesterC);

        $this->accept($forged)->assertNotFound();
        $this->reject($forged)->assertNotFound();

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $forged->fresh()->status);
    }

    public function test_a_cross_org_decision_keeps_the_notification_actor_null(): void
    {
        $this->accept($this->joinRequest)->assertRedirect();

        $notification = MemberNotification::query()
            ->where('recipient_id', $this->requester->id)
            ->firstOrFail();

        $this->assertSame(NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED, $notification->notification_key);
        // L'acteur hors tenant n'apparait pas dans la notification metier du
        // tenant — comportement fail-safe pose AVANT T1680, ici verrouille.
        $this->assertNull($notification->actor_id);
    }

    public function test_the_admin_cockpit_lists_only_this_loops_pending_requests(): void
    {
        // Une demande d'une AUTRE Boucle du meme tenant ne doit pas fuir ici.
        $otherLoop = app(LoopService::class)->createLoopForOrg(
            null, $this->orgB->id, 'Autre boucle', null, 'private',
            null, Loop::ACCESS_REQUEST, null, $this->superAdmin,
        );
        $otherRequester = User::factory()->create(['organization_id' => $this->orgB->id]);
        app(LoopService::class)->requestToJoin($otherLoop, $otherRequester);

        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.show', $this->loop))
            ->assertOk()
            ->assertSee(__('admin.loop_join_requests_title'))
            ->assertSee($this->requester->email)
            ->assertSee('Je forme des formateurs.')
            ->assertDontSee($otherRequester->email)
            ->assertSee(route('organization.loop-join-requests.accept', ['organization' => $this->orgB->slug, 'joinRequest' => $this->joinRequest]), false)
            ->assertSee(route('organization.loop-join-requests.reject', ['organization' => $this->orgB->slug, 'joinRequest' => $this->joinRequest]), false);

        // Etat vide, une fois la demande traitee.
        $this->accept($this->joinRequest);
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.show', $this->loop))
            ->assertOk()
            ->assertDontSee($this->requester->email);
    }

    public function test_the_admin_cockpit_shows_the_empty_state(): void
    {
        $this->reject($this->joinRequest);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.show', $this->loop))
            ->assertOk()
            ->assertSee(__('admin.loop_join_requests_empty'));
    }

    public function test_the_presentation_hides_participation_ctas_from_the_cross_org_superadmin(): void
    {
        app()->instance('current_organization', $this->orgB);

        // La page se consulte (T1679), mais aucun geste de participation ne
        // s'y offre : le backend refuse deja deux fois (garde + policy), on
        // n'affiche pas une porte qu'on garde fermee.
        $this->actingAs($this->superAdmin)
            ->get(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->assertViewIs('loops.presentation')
            ->assertDontSee(__('loops.cta_request'))
            ->assertDontSee(__('loops.cta_join'));

        // Le POST direct, lui, reste 404 — non-regression de la garde.
        $this->actingAs($this->superAdmin)
            ->post(route('organization.loops.join-requests.store', ['organization' => $this->orgB->slug, 'loop' => $this->loop->id]), [
                'message' => 'intrusion',
            ])
            ->assertNotFound();

        // Boucle en acces OUVERT : meme regle, le CTA « Rejoindre » non plus.
        $openLoop = app(LoopService::class)->createLoopForOrg(
            null, $this->orgB->id, 'Boucle ouverte', null, 'public',
            null, Loop::ACCESS_OPEN, null, $this->superAdmin,
        );
        $this->actingAs($this->superAdmin)
            ->get(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $openLoop->id]))
            ->assertOk()
            ->assertDontSee(__('loops.cta_join'));
    }

    public function test_a_tenant_member_still_sees_the_cta_and_can_request_to_join(): void
    {
        $member = User::factory()->create(['organization_id' => $this->orgB->id]);
        app()->instance('current_organization', $this->orgB);

        $this->actingAs($member)
            ->get(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->assertViewIs('loops.presentation')
            ->assertSee(__('loops.cta_request'));

        $this->actingAs($member)
            ->post(route('organization.loops.join-requests.store', ['organization' => $this->orgB->slug, 'loop' => $this->loop->id]), [
                'message' => 'Je veux participer.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('loop_join_requests', [
            'loop_id' => $this->loop->id,
            'user_id' => $member->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);
    }

    public function test_the_admin_list_links_to_the_loop_front_page(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => $this->orgB->id]))
            ->assertOk()
            ->assertSee(__('admin.loop_view_front'))
            ->assertSee(route('organization.loops.show', [
                'organization' => $this->orgB->slug,
                'loop' => $this->loop->id,
            ]), false);
    }

    public function test_t1679_inspection_remains_read_only_for_the_superadmin(): void
    {
        app()->instance('current_organization', $this->orgB);

        $this->actingAs($this->superAdmin)
            ->get(route('organization.loops.show', ['organization' => $this->orgB->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->assertViewIs('loops.presentation');

        // Toujours aucune participation implicite, meme apres une decision.
        $this->accept($this->joinRequest)->assertRedirect();
        $this->assertSame(0, DB::table('loop_members')->where('user_id', $this->superAdmin->id)->count());

        // Et ChatLoop reste ferme.
        $this->actingAs($this->superAdmin)
            ->post(route('organization.loops.messages.store', ['organization' => $this->orgB->slug, 'loop' => $this->loop->id]), [
                'body' => 'intrusion',
            ])
            ->assertNotFound();
    }
}
