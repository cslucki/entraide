<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use App\Services\Workshops\WorkshopResumption;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * TASK-1465 — audit OPUS final P1-3 (F6 rouvert, Growth V3 §11) : le lien de
 * verification REJOUE (double clic, verification deja faite dans un autre
 * navigateur, client mail qui pre-ouvre) consomme quand meme la reprise
 * structuree et ramene le membre sur son atelier ; la cle `workshop.resume`
 * ne reste jamais en session. Le repli (dashboard) reste le meme quand rien
 * n'est parque, et la reference est toujours revalidee (User, tenant, atelier).
 */
class TASK1465VerificationReplayResumptionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private User $admin;

    private Workshop $workshop;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Organization::factory()->create(['slug' => 'org-a-1465', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $admin = $this->admin = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $admin->id]);
        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $this->workshop = $workshops->create($this->a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $admin);
        $workshops->publish($this->workshop, $admin);
        $session = $sessions->create($this->workshop, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris'], $admin);
        $sessions->publish($session, $admin);
        $this->sessionId = $session->id;
    }

    private function verifyUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
    }

    private function reference(?Organization $organization = null, ?string $workshopId = null): array
    {
        return ['organization_id' => ($organization ?? $this->a)->id, 'workshop_id' => $workshopId ?? $this->workshop->id, 'workshop_session_id' => $this->sessionId];
    }

    public function test_a_replayed_verification_link_still_consumes_the_parked_resumption_and_lands_on_the_workshop(): void
    {
        $user = User::factory()->create(['organization_id' => $this->a->id, 'email_verified_at' => null]);
        $workshopUrl = route('organization.workshop.show', ['organization' => $this->a->slug, 'workshop' => 'ia-90']);

        // L'email a DEJA ete verifie ailleurs (autre navigateur, pre-ouverture) ; ce navigateur porte encore la reference parquee.
        Carbon::setTestNow('2026-09-08 10:00:00');
        $user->markEmailAsVerified();
        Carbon::setTestNow('2026-09-08 10:05:00');
        Event::fake([Verified::class]);
        $this->actingAs($user)->withSession([WorkshopResumption::SESSION_KEY => $this->reference()])->get($this->verifyUrl($user))
            ->assertRedirect($workshopUrl.'?verified=1');
        $this->assertNull(session(WorkshopResumption::SESSION_KEY), 'la cle ne reste jamais en session');
        // Un lien rejoue ne re-verifie rien (S2) : ni evenement Verified, ni horodatage reecrit.
        Event::assertNotDispatched(Verified::class);
        $this->assertSame('2026-09-08 10:00:00', $user->fresh()->email_verified_at->format('Y-m-d H:i:s'), 'l\'horodatage de verification ne bouge pas sur un rejeu');

        // Rejoue une seconde fois, plus rien de parque : repli normal, aucune erreur.
        $this->actingAs($user)->get($this->verifyUrl($user))->assertRedirect(route('dashboard', absolute: false).'?verified=1');

        // Premiere verification + reference parquee (chemin nominal T1453) : inchange.
        $fresh = User::factory()->create(['organization_id' => $this->a->id, 'email_verified_at' => null]);
        $this->actingAs($fresh)->withSession([WorkshopResumption::SESSION_KEY => $this->reference()])->get($this->verifyUrl($fresh))->assertRedirect($workshopUrl.'?verified=1');
        $this->assertNotNull($fresh->fresh()->email_verified_at);
        $this->assertNull(session(WorkshopResumption::SESSION_KEY));
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_the_replayed_link_never_resumes_a_reference_that_is_not_the_members_own(): void
    {
        $b = Organization::factory()->create(['slug' => 'org-b-1465', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $user = User::factory()->create(['organization_id' => $this->a->id, 'email_verified_at' => now()]);

        // Reference d'une AUTRE Organization : revalidee, refusee, consommee — repli normal.
        $this->actingAs($user)->withSession([WorkshopResumption::SESSION_KEY => $this->reference($b)])->get($this->verifyUrl($user))
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        $this->assertNull(session(WorkshopResumption::SESSION_KEY), 'une reference etrangere est consommee, jamais gardee');

        // Atelier retire entre-temps : rien a reprendre.
        app(WorkshopService::class)->retire($this->workshop, $this->admin);
        $this->actingAs($user)->withSession([WorkshopResumption::SESSION_KEY => $this->reference()])->get($this->verifyUrl($user))
            ->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        $this->assertNull(session(WorkshopResumption::SESSION_KEY));
    }
}
