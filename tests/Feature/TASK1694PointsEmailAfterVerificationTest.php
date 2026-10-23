<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * TASK-1694, bug I — l'annonce des points part APRÈS la confirmation.
 *
 * Cyril : « avant, *welcome* était utilisé directement car pour les tests je ne
 * voulais pas de confirmation […]. Ce qui n'est donc plus le cas désormais. »
 *
 * La vérification étant redevenue obligatoire (bug C), annoncer 100 points à
 * quelqu'un qui ne peut pas encore entrer n'avait plus de sens — et deux emails
 * partaient coup sur coup.
 *
 * ## Ce que ces tests mesurent dans les DEUX sens
 *
 * Qu'il ne part **plus** à l'inscription, **et** qu'il part bien à la
 * confirmation. N'en mesurer qu'un laisserait passer un email supprimé.
 */
class TASK1694PointsEmailAfterVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694i',
            'is_active' => true,
            'is_default' => true,
            'is_public' => true,
        ]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    /** LE geste demandé : la confirmation déclenche l'annonce. */
    public function test_verifying_sends_the_points_email(): void
    {
        Notification::fake();

        $membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => null,
        ]);

        event(new Verified($membre));

        Notification::assertSentTo($membre, WelcomeNotification::class);
    }

    /**
     * Et l'inscription ne l'envoie PLUS.
     *
     * Sans cette moitié, l'email pourrait partir deux fois sans que rien ne
     * rougisse.
     */
    public function test_registering_no_longer_sends_it(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'name' => 'Dupont',
            'first_name' => 'Camille',
            'email' => 'camille-1694i@example.test',
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'Un-mot-de-passe-solide-1694!',
            'password_confirmation' => 'Un-mot-de-passe-solide-1694!',
        ]);

        $membre = User::where('email', 'camille-1694i@example.test')->first();

        $this->assertNotNull($membre, 'premisse : le compte est bien cree');
        Notification::assertNotSentTo($membre, WelcomeNotification::class);
    }

    /**
     * Le CRÉDIT, lui, reste à l'inscription.
     *
     * Seule l'annonce se déplace. Déplacer le crédit serait un autre geste,
     * avec ses propres conséquences — le grand livre, les rapports, les comptes
     * déjà crédités et non vérifiés.
     */
    public function test_the_points_are_still_granted_at_registration(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'name' => 'Martin',
            'first_name' => 'Alex',
            'email' => 'alex-1694i@example.test',
            'phone' => '0600000001',
            'country_code' => 'FR',
            'password' => 'Un-mot-de-passe-solide-1694!',
            'password_confirmation' => 'Un-mot-de-passe-solide-1694!',
        ]);

        $membre = User::where('email', 'alex-1694i@example.test')->firstOrFail();

        $this->assertSame(100, (int) $membre->points_balance);
        $this->assertDatabaseHas((new \App\Models\PointLedger)->getTable(), [
            'user_id' => $membre->id,
            'delta' => 100,
            'reason' => 'welcome_bonus',
        ]);
    }

    /** Un destinataire qui n'est pas un User ne déclenche rien. */
    public function test_a_non_user_subject_is_ignored(): void
    {
        Notification::fake();

        $listener = new \App\Listeners\SendPointsGrantedEmailOnVerification;
        $listener->handle(new Verified(new \Illuminate\Foundation\Auth\User));

        Notification::assertNothingSent();
    }
}
