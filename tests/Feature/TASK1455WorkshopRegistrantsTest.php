<?php

namespace Tests\Feature;

use App\Models\AcquisitionJourney;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\Crm\CrmContactService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopInterestService;
use App\Services\Workshops\WorkshopRegistrationService;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1455 (prep) — OrgAdmin Workshops : « Inscrits & interets » (Growth V3
 * §13), LECTURE SEULE : par session, les membres inscrits (nom, email,
 * provenance Journey/campagne/shortcut, Contact CRM lie), les interets Guest
 * (compteur + pseudonymes, aucune donnee personnelle), distinction
 * Guest / compte / confirme ; tenant-safe ; aucune mutation.
 */
class TASK1455WorkshopRegistrantsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $adminB;

    private User $superAdmin;

    private Workshop $workshopA;

    private WorkshopSession $s1;

    private AcquisitionJourney $journey;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');
        $this->a = Organization::factory()->create(['slug' => 'org-a-1454', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-1454', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->b->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->b->update(['admin_id' => $this->adminB->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->b->id, 'is_admin' => true]);

        $journeys = app(AcquisitionJourneyService::class);
        $this->journey = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION, 'campaign' => 'sept-2026'], $this->adminA);
        $journeys->publish($this->journey, $this->adminA);

        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $this->workshopA = $workshops->create($this->a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($this->workshopA, $this->adminA);
        $this->s1 = $sessions->create($this->workshopA, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris', 'capacity' => 10], $this->adminA);
        $sessions->publish($this->s1, $this->adminA);
    }

    private function url(Organization $organization, Workshop $workshop): string
    {
        return route('organization.admin.workshops.registrants', [$organization, $workshop]);
    }

    public function test_the_org_admin_reads_registrants_with_provenance_and_guest_interests_without_personal_data(): void
    {
        // Un visiteur Guest venu du parcours `rentree` : interet, puis compte claime et inscription.
        $raw = Str::random(64);
        $visitor = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $raw]), $this->a, ['acquisition_journey_id' => $this->journey->id, 'utm_campaign' => 'sept-2026', 'shortcut' => 'demo']);
        app(WorkshopInterestService::class)->select($this->s1, $visitor);
        $member = User::factory()->create(['organization_id' => $this->a->id, 'name' => 'Membre Inscrit', 'email' => 'inscrit@example.test', 'email_verified_at' => now()]);
        $visitor->forceFill(['claimed_user_id' => $member->id, 'claimed_at' => now()])->save();
        app(WorkshopRegistrationService::class)->register($this->s1, $member);
        $contact = app(CrmContactService::class)->findOrCreate($this->a, ['email' => 'inscrit@example.test', 'first_name' => 'Membre'], $this->adminA);
        app(CrmContactService::class)->linkToUser($contact, $member);
        // Un interet Guest sans compte : compte pour un, pseudonyme seulement.
        $lonely = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->a);
        app(WorkshopInterestService::class)->select($this->s1, $lonely);
        // Un interet RETIRE ne compte pas et n'apparait pas (S5).
        $gone = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->a);
        app(WorkshopInterestService::class)->select($this->s1, $gone);
        app(WorkshopInterestService::class)->withdraw($this->s1, $gone);
        // Un second inscrit dont le seul Contact CRM vit dans une AUTRE Organization : jamais propose (S2).
        $stranger = User::factory()->create(['organization_id' => $this->a->id, 'name' => 'Second Inscrit', 'email' => 'second@example.test', 'email_verified_at' => now()]);
        app(WorkshopRegistrationService::class)->register($this->s1, $stranger);
        $foreignContact = CrmContact::query()->create(['organization_id' => $this->b->id, 'email' => 'second@example.test', 'user_id' => $stranger->id, 'source' => CrmContact::SOURCE_MANUAL]);

        $html = $this->actingAs($this->adminA)->get($this->url($this->a, $this->workshopA))->assertOk()->getContent();
        $this->assertStringContainsString('data-registrants-session="'.$this->s1->id.'" data-registrants-count="2" data-registrants-interests="2"', $html);
        $this->assertStringNotContainsString($gone->pseudonym(), $html, 'un interet retire disparait');
        $this->assertStringContainsString('Second Inscrit', $html);
        $this->assertStringNotContainsString('data-registrant-contact="'.$foreignContact->id.'"', $html, 'un Contact d\'une autre Organization n\'est jamais propose');
        $this->assertStringContainsString('Membre Inscrit', $html);
        $this->assertStringContainsString('inscrit@example.test', $html);
        $this->assertStringContainsString('Rentrée v1', $html, 'provenance : la Journey exacte');
        $this->assertStringContainsString('sept-2026', $html);
        $this->assertStringContainsString('/s/demo', $html);
        $this->assertStringContainsString('data-registrant-contact="'.$contact->id.'"', $html, 'le Contact CRM lie est ouvrable');
        $this->assertStringContainsString($lonely->pseudonym(), $html, 'un interet Guest = un pseudonyme');
        $this->assertStringNotContainsString($lonely->visitor_key_hash, $html, 'jamais la cle');
        $this->assertStringContainsString('data-workshop-registrants="'.$this->workshopA->id.'"', $this->actingAs($this->adminA)->get(route('organization.admin.workshops', $this->a))->getContent());

        // Une annulation : la ligne reste visible comme annulee, le compteur ne la compte plus.
        app(WorkshopRegistrationService::class)->cancel($this->s1, $member);
        $html = $this->actingAs($this->adminA)->get($this->url($this->a, $this->workshopA))->assertOk()->getContent();
        $this->assertStringContainsString('data-registrants-count="1"', $html);
        $this->assertStringContainsString('data-registrant-status="cancelled"', $html);
    }

    public function test_registrants_are_tenant_scoped_and_read_only(): void
    {
        $this->actingAs($this->adminB)->get($this->url($this->b, $this->workshopA))->assertNotFound();
        $this->actingAs($this->adminB)->get($this->url($this->a, $this->workshopA))->assertForbidden();
        $member = User::factory()->create(['organization_id' => $this->a->id]);
        $this->actingAs($member)->get($this->url($this->a, $this->workshopA))->assertForbidden();
        $this->actingAs($this->superAdmin)->get($this->url($this->a, $this->workshopA))->assertOk();
        // Aucune route de mutation d'inscription cote admin.
        $this->assertNull(Route::getRoutes()->getByName('organization.admin.workshops.registrants.cancel'));
        $this->assertNull(Route::getRoutes()->getByName('organization.admin.workshops.registrants.store'));
    }
}
