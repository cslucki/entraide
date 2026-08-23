<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1277 — parcours « demande d'adhesion » : etat « en attente » sur la
 * carte du catalogue et redirections Organization-scoped des routes plates.
 *
 * Symptome 2 (dogfooding test20260822) : depuis `/org/{slug}/loops/{loop}`,
 * « Accepter » une demande redirigeait vers la route courte `/loops/{loop}`
 * (404 : la route courte resout `main`). Les routes plates
 * `loop-join-requests.{accept,reject,cancel}` et `loops.members.role` n'ont
 * pas de segment `{organization}` : `LoopController::loopRoute()` derive
 * desormais l'Organization de la Boucle pour elles.
 *
 * Symptome 1 : la carte affichait encore « Demander a rejoindre » — seulement
 * via le bouton Precedent du navigateur, qui rejoue la reponse en cache sans
 * la revalider (`no-cache`). Le catalogue est servi `no-store`.
 */
class TASK1277JoinRequestRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Loop $loop;

    private User $owner;

    private User $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['loops_enabled' => true]);
        $this->loop = Loop::factory()->requestAccess()->create(['organization_id' => $this->org->id]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $this->loop->id, 'user_id' => $this->owner->id]);
        $this->applicant = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function pendingRequest(): LoopJoinRequest
    {
        return LoopJoinRequest::factory()->create([
            'loop_id' => $this->loop->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->applicant->id,
        ]);
    }

    private function orgScopedShow(): string
    {
        return route('organization.loops.show', ['organization' => $this->org->slug, 'loop' => $this->loop->id]);
    }

    // ── Routes plates : redirection Organization-scoped ───────────────────

    public function test_accept_redirects_to_organization_scoped_loop_and_target_answers_200(): void
    {
        $joinRequest = $this->pendingRequest();

        $response = $this->actingAs($this->owner)
            ->post(route('loop-join-requests.accept', $joinRequest))
            ->assertRedirect($this->orgScopedShow())
            ->assertSessionHas('success', __('loops.join_request_accepted'));

        $this->assertStringNotContainsString('/org/', route('loops.show', $this->loop));
        $this->assertStringStartsWith('/org/'.$this->org->slug.'/loops/', parse_url($response->headers->get('Location'), PHP_URL_PATH));

        $this->actingAs($this->owner)->get($response->headers->get('Location'))->assertOk();

        $this->assertSame('accepted', $joinRequest->fresh()->status);
        $this->assertDatabaseHas('loop_members', ['loop_id' => $this->loop->id, 'user_id' => $this->applicant->id, 'status' => 'active']);
    }

    public function test_reject_redirects_to_organization_scoped_loop(): void
    {
        $joinRequest = $this->pendingRequest();

        $response = $this->actingAs($this->owner)
            ->post(route('loop-join-requests.reject', $joinRequest))
            ->assertRedirect($this->orgScopedShow())
            ->assertSessionHas('info', __('loops.join_request_rejected'));

        $this->actingAs($this->owner)->get($response->headers->get('Location'))->assertOk();
        $this->assertSame('rejected', $joinRequest->fresh()->status);
    }

    public function test_cancel_by_applicant_redirects_to_organization_scoped_loop(): void
    {
        $joinRequest = $this->pendingRequest();

        $response = $this->actingAs($this->applicant)
            ->delete(route('loop-join-requests.cancel', $joinRequest))
            ->assertRedirect($this->orgScopedShow())
            ->assertSessionHas('info', __('loops.join_request_cancelled'));

        // L'ancienne demandeuse retombe sur la presentation org-scoped, en 200.
        $this->actingAs($this->applicant)->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee(__('loops.cta_request'));
        $this->assertSame('cancelled', $joinRequest->fresh()->status);
    }

    public function test_accept_error_path_also_redirects_to_organization_scoped_loop(): void
    {
        $joinRequest = $this->pendingRequest();
        $this->actingAs($this->owner)->post(route('loop-join-requests.accept', $joinRequest))->assertRedirect($this->orgScopedShow());

        // Seconde acceptation d'une demande deja tranchee : le repli « error » suit la meme regle.
        $this->actingAs($this->owner)
            ->post(route('loop-join-requests.accept', $joinRequest))
            ->assertRedirect($this->orgScopedShow())
            ->assertSessionHas('error');
    }

    public function test_member_role_change_redirects_to_organization_scoped_loop(): void
    {
        $member = LoopMember::factory()->create(['loop_id' => $this->loop->id, 'user_id' => $this->applicant->id, 'role' => 'member', 'status' => 'active']);

        $response = $this->actingAs($this->owner)
            ->put(route('loops.members.role', $member), ['role' => 'facilitator'])
            ->assertRedirect($this->orgScopedShow());

        $this->actingAs($this->owner)->get($response->headers->get('Location'))->assertOk();
    }

    // ── Surfaces existantes : contrats inchanges ──────────────────────────

    public function test_organization_scoped_store_still_redirects_to_organization_scoped_loop(): void
    {
        $this->actingAs($this->applicant)
            ->post(route('organization.loops.join-requests.store', ['organization' => $this->org->slug, 'loop' => $this->loop->id]), ['message' => 'Bonjour'])
            ->assertRedirect($this->orgScopedShow())
            ->assertSessionHas('success', __('loops.join_request_sent'));
    }

    public function test_short_surface_keeps_short_redirect(): void
    {
        // Une action lancee depuis la surface courte `loops.*` reste sur la
        // surface courte : les 14 assertions historiques de route courte ne bougent pas.
        $this->actingAs($this->applicant)
            ->post(route('loops.join-requests.store', $this->loop), ['message' => 'Bonjour'])
            ->assertRedirect(route('loops.show', $this->loop));
    }

    public function test_cross_organization_accept_is_still_refused(): void
    {
        $joinRequest = $this->pendingRequest();
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);
        app()->instance('current_organization', $otherOrg);

        $this->actingAs($stranger)
            ->post(route('loop-join-requests.accept', $joinRequest))
            ->assertNotFound();

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $joinRequest->fresh()->status);
    }

    // ── Symptome 1 : carte du catalogue ───────────────────────────────────

    public function test_catalog_card_shows_pending_state_and_no_request_cta(): void
    {
        Loop::factory()->requestAccess()->create(['organization_id' => $this->org->id]); // une 2e Boucle : pas de redirection mono-Boucle
        $this->pendingRequest();

        $this->actingAs($this->applicant)
            ->get(route('organization.loops.index', ['organization' => $this->org->slug]))
            ->assertOk()
            ->assertSee(__('loops.cta_pending'));
    }

    public function test_catalog_is_served_no_store_on_both_surfaces(): void
    {
        Loop::factory()->requestAccess()->create(['organization_id' => $this->org->id]);

        foreach ([
            route('organization.loops.index', ['organization' => $this->org->slug]),
            route('loops.index'),
        ] as $url) {
            $cacheControl = $this->actingAs($this->applicant)->get($url)->assertOk()->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', (string) $cacheControl, $url);
        }
    }
}
