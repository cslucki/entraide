<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1843 — `/notifications/unread-count`, source de verite HTTP du badge
 * temps reel.
 *
 * Meme montage que `TASK1373NotificationCenterTest` : DEUX Organizations,
 * celle par defaut de la plateforme n'etant PAS celle du membre — avec une
 * seule Organization le test resterait vert meme si le code lisait le
 * mauvais tenant (`currentOrganization()` au lieu de `$user->organization`).
 */
class TASK1843UnreadCountEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const OBJET = 'loop_invitation';

    private Organization $orgDefaut;

    private Organization $orgMembre;

    private User $alice;

    private User $bob;

    private NotificationEmitter $emetteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgDefaut = Organization::factory()->create(['name' => 'T1843 Organization par defaut', 'is_active' => true]);
        $this->orgMembre = Organization::factory()->create(['name' => 'T1843 Organization du membre', 'is_active' => true]);
        $this->orgDefaut->update(['is_default' => true]);

        $this->alice = User::factory()->create(['organization_id' => $this->orgMembre->id]);
        $this->bob = User::factory()->create(['organization_id' => $this->orgMembre->id]);

        $this->emetteur = new NotificationEmitter;
    }

    protected function tearDown(): void
    {
        Organization::query()->update(['is_default' => false]);

        parent::tearDown();
    }

    private function emettre(User $destinataire): void
    {
        $this->emetteur->emit(
            notificationKey: NotificationCatalogue::LOOP_INVITATION,
            organization: $this->orgMembre,
            recipient: $destinataire,
            eventId: (string) Str::uuid(),
            objectType: self::OBJET,
        );
    }

    public function test_une_requete_non_authentifiee_est_refusee(): void
    {
        // `auth` (middleware de la route) refuse AVANT `acteur()` : pour une
        // requete JSON, `Authenticate::redirectTo()` rend `null`, donc une
        // 401 (`AuthenticationException`), jamais le 404 de `abort_unless()`.
        $this->getJson('/notifications/unread-count')->assertUnauthorized();
    }

    public function test_rend_zero_sans_aucune_notification(): void
    {
        $this->actingAs($this->alice)
            ->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function test_rend_le_compte_exact_apres_creation(): void
    {
        $this->emettre($this->alice);
        $this->emettre($this->alice);

        $this->actingAs($this->alice)
            ->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 2]);
    }

    public function test_plusieurs_notifications_rapprochees_rendent_un_resultat_exact(): void
    {
        // TASK-1843 — mandat MASTER : « plusieurs notifications rapprochees :
        // resultat exact, sans surcharge inutile ». `COUNT(*)` reste exact
        // quel que soit le nombre d'evenements, sans accumulation de delta.
        for ($i = 0; $i < 5; $i++) {
            $this->emettre($this->alice);
        }

        $this->actingAs($this->alice)
            ->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 5]);
    }

    public function test_une_notification_marquee_lue_fait_baisser_le_compte(): void
    {
        $this->emettre($this->alice);
        $this->emettre($this->alice);

        $notification = \App\Models\MemberNotification::query()
            ->forRecipient((string) $this->orgMembre->id, (string) $this->alice->id)
            ->first();

        $notification->markAsReadFor((string) $this->orgMembre->id, (string) $this->alice->id);

        $this->actingAs($this->alice)
            ->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 1]);
    }

    public function test_le_compte_est_scope_a_l_organization_du_membre_jamais_celle_par_defaut(): void
    {
        $this->emettre($this->alice);

        // TASK-1373 — meme expression que `NotificationCenterController::acteur()`
        // et le rail : l'Organization du MEMBRE d'abord. Si cette route lisait
        // `currentOrganization()` en premier, elle rendrait zero ici (aucune
        // notification dans l'Organization par defaut), et ce test rougirait.
        $this->actingAs($this->alice)
            ->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 1]);
    }

    public function test_un_membre_ne_voit_jamais_le_compte_d_un_autre(): void
    {
        $this->emettre($this->alice);
        $this->emettre($this->alice);
        $this->emettre($this->alice);

        $this->actingAs($this->bob)
            ->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 0]);
    }

    public function test_le_compte_correspond_exactement_a_unreadNotificationsCount(): void
    {
        $this->emettre($this->alice);

        $attendu = $this->alice->unreadNotificationsCount((string) $this->orgMembre->id);

        $this->actingAs($this->alice)
            ->getJson('/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => $attendu]);
    }
}
