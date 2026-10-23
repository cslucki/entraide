<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug M — la fiche admin d'une Boucle montre les demandes
 * d'adhesion, et permet de les decider sans quitter l'ecran.
 *
 * ## Ce qui manquait
 *
 * `/admin/loops/{loop}/edit` affichait les membres et laissait en ajouter, mais
 * TAISAIT les personnes qui en avaient fait la demande. Un administrateur venu
 * regler le cas d'un demandeur n'en voyait aucune trace et l'ajoutait « a la
 * main », sans savoir qu'une demande existait : c'est ainsi que la Boucle
 * `ofsh` s'est retrouvee avec des membres actifs portant encore une demande
 * ouverte (bug L).
 *
 * ## Pourquoi des routes admin dediees
 *
 * `loop-join-requests.accept|reject` existent et font le bon travail, mais
 * renvoient toujours vers la Boucle : depuis l'administration cela ejecterait
 * le SuperAdmin de son ecran — et, s'il n'est pas membre, vers la page de
 * PRESENTATION, ce qui ressemblerait a une erreur. Les deux actions admin ne
 * portent AUCUNE regle metier : meme service, meme autorisation, retour sur la
 * fiche.
 */
class TASK1693AdminLoopJoinRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Loop $loop;

    private User $superAdmin;

    private User $demandeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693m',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'access_mode' => Loop::ACCESS_REQUEST,
            'status' => 'active',
        ]);

        LoopMember::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => User::factory()->complete()->create(['organization_id' => $this->organization->id])->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $this->demandeur = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function demande(?User $pour = null, string $statut = LoopJoinRequest::STATUS_PENDING): LoopJoinRequest
    {
        return LoopJoinRequest::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => ($pour ?? $this->demandeur)->id,
            'organization_id' => $this->organization->id,
            'status' => $statut,
        ]);
    }

    private function fiche(): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.edit', $this->loop))
            ->assertOk()
            ->getContent();
    }

    /** LE manque signale : la demande doit se VOIR. */
    public function test_the_admin_card_shows_a_pending_request_with_who_and_when(): void
    {
        $this->demande();

        $html = $this->fiche();

        $this->assertStringContainsString('data-loop-join-requests="1"', $html);
        $this->assertStringContainsString($this->demandeur->email, $html);
        $this->assertStringContainsString('data-loop-join-request-accept', $html);
        $this->assertStringContainsString('data-loop-join-request-reject', $html);
    }

    /**
     * Le bloc n'apparait que s'il y a quelque chose a decider.
     *
     * Un cadre vide ferait croire a une attente permanente, et c'est
     * exactement l'inverse du but : rendre l'etat lisible.
     */
    public function test_the_block_is_absent_when_there_is_nothing_to_decide(): void
    {
        $this->assertStringNotContainsString('data-loop-join-requests=', $this->fiche());
    }

    /** Les decisions PASSEES n'encombrent pas un ecran d'action. */
    public function test_already_decided_requests_are_not_listed(): void
    {
        $this->demande(statut: LoopJoinRequest::STATUS_ACCEPTED);
        $this->demande(
            User::factory()->complete()->create(['organization_id' => $this->organization->id]),
            LoopJoinRequest::STATUS_REJECTED,
        );

        $this->assertStringNotContainsString('data-loop-join-requests=', $this->fiche());
    }

    /** Accepter depuis la fiche cree le membre — et RESTE sur la fiche. */
    public function test_accepting_from_the_admin_card_creates_the_member_and_stays_on_the_card(): void
    {
        $demande = $this->demande();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.join-requests.accept', [$this->loop, $demande]))
            ->assertRedirect(route('admin.loops.edit', $this->loop))
            ->assertSessionHas('success');

        $this->assertSame(LoopJoinRequest::STATUS_ACCEPTED, $demande->fresh()->status);
        $this->assertSame($this->superAdmin->id, $demande->fresh()->decided_by);
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->demandeur->id,
            'status' => 'active',
        ]);
    }

    /** Refuser ne cree AUCUN membre. */
    public function test_rejecting_from_the_admin_card_creates_no_member(): void
    {
        $demande = $this->demande();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.join-requests.reject', [$this->loop, $demande]))
            ->assertRedirect(route('admin.loops.edit', $this->loop));

        $this->assertSame(LoopJoinRequest::STATUS_REJECTED, $demande->fresh()->status);
        $this->assertDatabaseMissing('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->demandeur->id,
        ]);
    }

    /**
     * Deux identifiants dans l'URL, et rien ne garantit qu'ils aillent
     * ensemble : la fiche d'une Boucle ne doit pas decider de la demande d'une
     * AUTRE.
     */
    public function test_a_request_belonging_to_another_loop_is_refused(): void
    {
        $autreBoucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'access_mode' => Loop::ACCESS_REQUEST,
            'status' => 'active',
        ]);

        $demandeAilleurs = LoopJoinRequest::query()->create([
            'loop_id' => $autreBoucle->id,
            'user_id' => $this->demandeur->id,
            'organization_id' => $this->organization->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.loops.join-requests.accept', [$this->loop, $demandeAilleurs]))
            ->assertNotFound();

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $demandeAilleurs->fresh()->status);
    }

    /** L'ecran d'administration reste reserve au SuperAdmin plateforme. */
    public function test_the_admin_decision_stays_with_the_platform_superadmin(): void
    {
        $demande = $this->demande();
        $url = route('admin.loops.join-requests.accept', [$this->loop, $demande]);

        // Anonyme d'abord : `actingAs()` persiste pour le reste du test.
        $this->post($url)->assertRedirect(route('login'));

        $simple = User::factory()->complete()->create(['organization_id' => $this->organization->id]);
        $this->actingAs($simple)->post($url)->assertForbidden();

        $this->assertSame(LoopJoinRequest::STATUS_PENDING, $demande->fresh()->status,
            'aucun acteur hors SuperAdmin plateforme ne decide depuis cet ecran');
    }
}
