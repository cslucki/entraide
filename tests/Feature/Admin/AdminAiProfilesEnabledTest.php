<?php

namespace Tests\Feature\Admin;

use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class AdminAiProfilesEnabledTest extends TestCase
{
    private function enableAiProfiles(Organization $org, bool $enabled = true): void
    {
        $org->update(['ai_profiles_enabled' => $enabled]);
    }

    // ── Super-admin org edit ──────────────────────────────────────────────────

    public function test_super_admin_can_toggle_ai_profiles_enabled(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $org = Organization::factory()->create(['is_active' => true, 'ai_profiles_enabled' => true]);

        $this->actingAs($admin)
            ->post(route('admin.ai-config.profile'), [
                'organization_id' => $org->id,
                'ai_profiles_enabled' => '0',
            ])->assertRedirect();

        $this->assertFalse($org->fresh()->ai_profiles_enabled);
    }

    // ── Access control: wizard ─────────────────────────────────────────────────

    public function test_ai_wizard_returns_200_when_enabled(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('agent-ia.wizard'))
            ->assertOk();
    }

    public function test_ai_wizard_returns_404_when_disabled(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'ai_profiles_enabled' => false]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('agent-ia.wizard'))
            ->assertNotFound();
    }

    // ── Access control: org-scoped wizard ─────────────────────────────────────

    public function test_org_ai_wizard_returns_200_when_enabled(): void
    {
        $org = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('organization.agent-ia.wizard', $org->slug))
            ->assertOk();
    }

    public function test_org_ai_wizard_returns_404_when_disabled(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'ai_profiles_enabled' => false]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('organization.agent-ia.wizard', $org->slug))
            ->assertNotFound();
    }

    // ── Access control: public profile chat ────────────────────────────────────

    public function test_ai_profile_chat_returns_200_when_enabled(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        MemberAiProfile::factory()->published()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->get(route('agent-ia.profile.chat', $user))
            ->assertOk();
    }

    public function test_ai_profile_chat_returns_404_when_disabled(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'is_default' => true, 'ai_profiles_enabled' => false]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        MemberAiProfile::factory()->published()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->get(route('agent-ia.profile.chat', $user))
            ->assertNotFound();
    }

    // ── Dashboard ─────────────────────────────────────────────────────────────

    public function test_dashboard_hides_ai_profile_banner_when_disabled(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'ai_profiles_enabled' => false]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Profil IA');
    }

    // ── Profile show ─────────────────────────────────────────────────────────

    public function test_profile_show_hides_ai_agent_when_disabled(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'ai_profiles_enabled' => false]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('profile.show', $user))
            ->assertOk()
            ->assertDontSee(__('profile.ai_agent_available'));
    }

    // ── Org-admin sidebar ──────────────────────────────────────────────────────

    public function test_org_admin_sidebar_hides_ai_profiles_when_disabled(): void
    {
        $org = Organization::factory()->create(['is_active' => true, 'ai_profiles_enabled' => false]);
        $admin = User::factory()->create(['is_admin' => false, 'organization_id' => $org->id]);
        $org->admin_id = $admin->id;
        $org->save();

        $this->actingAs($admin)
            ->get(route('organization.admin.dashboard', $org->slug))
            ->assertOk()
            ->assertDontSee('Agents profil');
    }
}
