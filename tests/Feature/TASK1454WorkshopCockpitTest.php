<?php

namespace Tests\Feature;

use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\User;
use App\Services\Acquisition\AcquisitionJourneyService;
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
 * TASK-1454 — OrgAdmin Workshops, le cockpit (Growth V3 §13, MASTER Q81) :
 * sur la liste des ateliers, les compteurs sessions publiees / interets Guest /
 * inscrits confirmes et la provenance synthetique (Journey + campagne) —
 * LECTURE SEULE, aucune donnee nominative ici, tenant-safe.
 */
class TASK1454WorkshopCockpitTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_workshop_list_carries_read_only_funnel_counters_and_synthetic_provenance_per_organization(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $a = Organization::factory()->create(['slug' => 'org-a-1454', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $b = Organization::factory()->create(['slug' => 'org-b-1454', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $adminA = User::factory()->create(['organization_id' => $a->id]);
        $adminB = User::factory()->create(['organization_id' => $b->id]);
        $a->update(['admin_id' => $adminA->id]);
        $b->update(['admin_id' => $adminB->id]);

        $journeys = app(AcquisitionJourneyService::class);
        $journey = $journeys->createDraft($a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION, 'campaign' => 'sept-2026'], $adminA);
        $journeys->publish($journey, $adminA);

        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $wa = $workshops->create($a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr', 'acquisition_journey_id' => $journey->id], $adminA);
        $workshops->publish($wa, $adminA);
        $s1 = $sessions->create($wa, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris', 'capacity' => 10], $adminA);
        $sessions->publish($s1, $adminA);
        $sessions->create($wa, ['starts_at' => '2026-10-08 18:30', 'timezone' => 'Europe/Paris'], $adminA); // brouillon
        $wb = $workshops->create($b, ['title' => 'Atelier B', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $adminB);

        // Deux interets Guest (dont un retire), une inscription confirmee, une annulee.
        $interests = app(WorkshopInterestService::class);
        $v1 = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $a);
        $v2 = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $a);
        $v3 = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $a);
        $interests->select($s1, $v1);
        $interests->select($s1, $v2);
        $interests->select($s1, $v3);
        $interests->withdraw($s1, $v3);
        $registrations = app(WorkshopRegistrationService::class);
        $m1 = User::factory()->create(['organization_id' => $a->id, 'name' => 'Membre Un', 'email' => 'un@example.test', 'email_verified_at' => now()]);
        $m2 = User::factory()->create(['organization_id' => $a->id, 'name' => 'Membre Deux', 'email' => 'deux@example.test', 'email_verified_at' => now()]);
        $registrations->register($s1, $m1);
        $registrations->register($s1, $m2);
        $registrations->cancel($s1, $m2);

        $html = $this->actingAs($adminA)->get(route('organization.admin.workshops', $a))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-funnel="'.$wa->id.'" data-workshop-interests="2" data-workshop-registrations="1" data-workshop-published-sessions="1"', $html, 'interets selectionnes (pas les retires), inscrits confirmes (pas les annules), sessions publiees (pas les brouillons)');
        $this->assertStringContainsString('Rentrée v1 · sept-2026', $html, 'provenance synthetique : Journey exacte + campagne');
        $this->assertStringNotContainsString('un@example.test', $html, 'aucune donnee nominative sur le cockpit');
        $this->assertStringNotContainsString('Membre Un', $html);
        $this->assertStringNotContainsString($v1->visitor_key_hash, $html);
        $this->assertStringNotContainsString('data-workshop-funnel="'.$wb->id.'"', $html, 'l\'atelier de B n\'existe pas ici');

        // L'admin de B voit ses compteurs a zero, jamais ceux de A.
        $htmlB = $this->actingAs($adminB)->get(route('organization.admin.workshops', $b))->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-funnel="'.$wb->id.'" data-workshop-interests="0" data-workshop-registrations="0" data-workshop-published-sessions="0"', $htmlB);
        $this->assertStringNotContainsString('data-workshop-funnel="'.$wa->id.'"', $htmlB);
        // Aucune route de mutation d'inscription cote OrgAdmin.
        $this->assertNull(Route::getRoutes()->getByName('organization.admin.workshops.registrations.store'));
    }
}
