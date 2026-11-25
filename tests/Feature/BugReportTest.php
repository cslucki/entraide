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
            ->post('/report', [
                'category' => 'technical_bug',
                'details' => 'Le footer prend trop de place sur mobile.',
                'page_url' => 'https://test.laravel/explorer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bug_reports', [
            'organization_id' => $organization->id,
            'reporter_id' => $user->id,
            'category' => 'technical_bug',
            'status' => 'pending',
        ]);
    }

    public function test_authenticated_user_can_report_bug_from_organization_route(): void
    {
        Organization::factory()->create(['is_default' => true, 'is_active' => true]);
        $organization = Organization::factory()->create(['slug' => 'cpme', 'is_active' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($user)
            ->post("/org/{$organization->slug}/report", [
                'category' => 'technical_bug',
                'details' => 'Un lien ne répond pas dans le menu mobile.',
                'page_url' => "https://test.laravel/org/{$organization->slug}/explorer",
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bug_reports', [
            'organization_id' => $organization->id,
            'reporter_id' => $user->id,
            'category' => 'technical_bug',
            'status' => 'pending',
        ]);
    }

    /**
     * TASK-1904 (decision MASTER D6) — fin de la page PUBLIQUE de transparence
     * (TASK-1493) : l'invite ne lit plus aucun signalement, meme corrige ; le
     * global `/bugs` redirige vers `/report`.
     */
    public function test_the_global_report_page_shows_no_report_to_a_guest(): void
    {
        $organization = Organization::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'is_public' => true,
            'name' => 'Bug Report Sentinel Org',
        ]);
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

        $this->get('/bugs')->assertRedirect('/report');

        $this->get('/report')
            ->assertOk()
            ->assertSee('data-report-guest', false)
            ->assertDontSee('Le footer déborde.')
            ->assertDontSee('Footer simplifié et rendu responsive');
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
