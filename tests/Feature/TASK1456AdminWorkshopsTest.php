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
 * TASK-1456 — SuperAdmin Workshops (Growth V3 §14, MASTER Q81) : cockpit
 * transversal LECTURE SEULE — Organization, atelier, etat, sessions, interets,
 * inscriptions, conversions, Journey/campagne, filtre Organization, lien
 * « Ouvrir dans l'Organization ». Aucune mutation, aucune donnee nominative.
 */
class TASK1456AdminWorkshopsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_super_admin_reads_a_cross_organization_read_only_cockpit_with_an_organization_filter(): void
    {
        Carbon::setTestNow('2026-09-08 10:00:00');
        $a = Organization::factory()->create(['slug' => 'org-a-1456', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $b = Organization::factory()->create(['slug' => 'org-b-1456', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $adminA = User::factory()->create(['organization_id' => $a->id]);
        $adminB = User::factory()->create(['organization_id' => $b->id]);
        $a->update(['admin_id' => $adminA->id]);
        $b->update(['admin_id' => $adminB->id]);
        $superAdmin = User::factory()->create(['organization_id' => $b->id, 'is_admin' => true]);

        $journeys = app(AcquisitionJourneyService::class);
        $journey = $journeys->createDraft($a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION, 'campaign' => 'sept-2026'], $adminA);
        $journeys->publish($journey, $adminA);
        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $wa = $workshops->create($a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr', 'acquisition_journey_id' => $journey->id], $adminA);
        $workshops->publish($wa, $adminA);
        $s1 = $sessions->create($wa, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris', 'capacity' => 10], $adminA);
        $sessions->publish($s1, $adminA);
        $wb = $workshops->create($b, ['title' => 'Atelier B', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $adminB);

        // Un visiteur venu du parcours, claime, inscrit : une conversion pour A.
        $visitor = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $a, ['acquisition_journey_id' => $journey->id]);
        app(WorkshopInterestService::class)->select($s1, $visitor);
        $member = User::factory()->create(['organization_id' => $a->id, 'name' => 'Membre Un', 'email' => 'un@example.test', 'email_verified_at' => now()]);
        $visitor->forceFill(['claimed_user_id' => $member->id, 'claimed_at' => now()])->save();
        app(WorkshopRegistrationService::class)->register($s1, $member);
        // Une inscription ANNULEE et un interet RETIRE ne comptent pas (S2/S3).
        $gone = User::factory()->create(['organization_id' => $a->id, 'email_verified_at' => now()]);
        app(WorkshopRegistrationService::class)->register($s1, $gone);
        app(WorkshopRegistrationService::class)->cancel($s1, $gone);
        $withdrawn = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $a);
        app(WorkshopInterestService::class)->select($s1, $withdrawn);
        app(WorkshopInterestService::class)->withdraw($s1, $withdrawn);

        $html = $this->actingAs($superAdmin)->get(route('admin.workshops'))->assertOk()->getContent();
        $this->assertStringContainsString('data-admin-workshop="'.$wa->id.'" data-admin-workshop-organization="'.$a->id.'" data-admin-workshop-registrations="1" data-admin-workshop-interests="1"', $html);
        $this->assertStringContainsString('data-admin-workshop="'.$wb->id.'"', $html, 'toutes les Organizations');
        $this->assertStringContainsString('Rentrée v1', $html);
        $this->assertStringContainsString('data-admin-workshop-open="'.$wa->id.'"', $html, 'lien « Ouvrir dans l\'Organization »');
        $this->assertStringNotContainsString('un@example.test', $html, 'aucune donnee nominative dans le cockpit transversal');
        $this->assertStringNotContainsString('Membre Un', $html);
        $this->assertStringNotContainsString($visitor->visitor_key_hash, $html);

        // Filtre Organization : B seulement.
        $htmlB = $this->actingAs($superAdmin)->get(route('admin.workshops', ['organization' => $b->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-admin-workshop="'.$wb->id.'"', $htmlB);
        $this->assertStringNotContainsString('data-admin-workshop="'.$wa->id.'"', $htmlB);

        // Reserve au SuperAdmin ; aucune route de mutation.
        $this->actingAs($adminA)->get(route('admin.workshops'))->assertForbidden();
        $this->assertNull(Route::getRoutes()->getByName('admin.workshops.store'));
        $this->assertNull(Route::getRoutes()->getByName('admin.workshops.publish'));
    }
}
