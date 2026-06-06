<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BugReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_report_bug_on_default_organization(): void
    {
        $organization = Organization::factory()->create(['is_default' => true, 'is_active' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->post('/bugs', [
                'reason' => 'Affichage mobile',
                'details' => 'Le footer prend trop de place sur mobile.',
                'page_url' => 'https://test.laravel/explorer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bug_reports', [
            'organization_id' => $organization->id,
            'reporter_id' => $user->id,
            'reason' => 'Affichage mobile',
            'status' => 'pending',
        ]);
    }

    public function test_authenticated_user_can_report_bug_from_organization_route(): void
    {
        Organization::factory()->create(['is_default' => true, 'is_active' => true]);
        $organization = Organization::factory()->create(['slug' => 'cpme', 'is_active' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->post("/org/{$organization->slug}/bugs", [
                'reason' => 'Navigation',
                'details' => 'Un lien ne répond pas dans le menu mobile.',
                'page_url' => "https://test.laravel/org/{$organization->slug}/explorer",
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bug_reports', [
            'organization_id' => $organization->id,
            'reporter_id' => $user->id,
            'reason' => 'Navigation',
            'status' => 'pending',
        ]);
    }

    public function test_public_bug_list_shows_fixed_notes_and_hides_dismissed_bugs(): void
    {
        $organization = Organization::factory()->create(['is_default' => true, 'is_active' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        BugReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $user->id,
            'reason' => 'Affichage mobile',
            'details' => 'Le footer déborde.',
            'status' => 'fixed',
            'resolution_notes' => 'Footer simplifié et rendu responsive.',
            'fixed_at' => now(),
        ]);

        BugReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $user->id,
            'reason' => 'Doublon',
            'details' => 'Signalement ignoré.',
            'status' => 'dismissed',
        ]);

        $this->get('/bugs')
            ->assertOk()
            ->assertSee('Footer simplifié et rendu responsive')
            ->assertDontSee('Doublon');
    }

    public function test_admin_can_mark_bug_as_fixed_with_optional_public_note(): void
    {
        $organization = Organization::factory()->create(['is_default' => true, 'is_active' => true]);
        $reporter = User::factory()->create(['organization_id' => $organization->id]);
        $admin = User::factory()->create(['organization_id' => $organization->id, 'is_admin' => true]);
        $bugReport = BugReport::create([
            'organization_id' => $organization->id,
            'reporter_id' => $reporter->id,
            'reason' => 'Fonctionnement',
            'details' => 'Le formulaire ne se ferme pas.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->patch("/admin/bugs-reports/{$bugReport->id}/fix", [
                'resolution_notes' => 'Fermeture de la pop-up corrigée.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bug_reports', [
            'id' => $bugReport->id,
            'status' => 'fixed',
            'resolution_notes' => 'Fermeture de la pop-up corrigée.',
        ]);
    }
}
