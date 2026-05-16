<?php

namespace Tests\Feature\Admin;

use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminReferralTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_referrals(): void
    {
        $this->get(route('admin.referrals'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_referrals(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.referrals'))
            ->assertForbidden();
    }

    public function test_admin_can_view_referrals_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->get(route('admin.referrals'))
            ->assertOk();
    }

    // ── Page content ──────────────────────────────────────────────────────────

    public function test_admin_sees_page_title_and_kpis(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.referrals'));

        $response->assertSee('Suivi des invitations');
        $response->assertSee('Invitations');
        $response->assertSee('En attente');
        $response->assertSee('Activations');
        $response->assertSee("Points d'invitation", false);
    }

    public function test_admin_sees_referral_data(): void
    {
        $admin = $this->makeAdmin();
        $referrer = User::factory()->create(['name' => 'Alice']);
        $referred = User::factory()->create(['name' => 'Bob']);

        Referral::factory()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.referrals'));

        $response->assertSee('Alice');
        $response->assertSee('Bob');
        $response->assertSee('En attente');
    }

    public function test_admin_sees_activated_referrals(): void
    {
        $admin = $this->makeAdmin();
        $referrer = User::factory()->create(['name' => 'Charlie']);
        $referred = User::factory()->create(['name' => 'Diana']);

        Referral::factory()->activated()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.referrals'));

        $response->assertSee('Charlie');
        $response->assertSee('Diana');
        $response->assertSee('Activée');
    }

    public function test_admin_sees_referral_points_kpi(): void
    {
        $admin = $this->makeAdmin();
        $user = User::factory()->create();

        ReferralReward::factory()->count(3)->create([
            'user_id' => $user->id,
            'points' => 10,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.referrals'));

        $response->assertSee('30');
    }

    public function test_admin_sees_contributors_section(): void
    {
        $admin = $this->makeAdmin();
        $contributor = User::factory()->create(['name' => 'Eve']);
        $referred = User::factory()->create();

        Referral::factory()->activated()->create([
            'referrer_user_id' => $contributor->id,
            'referred_user_id' => $referred->id,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.referrals'));

        $response->assertSee('Eve');
        $response->assertSee('Contributions');
        $response->assertSee("Membres qui font entrer d'autres personnes dans la boucle", false);
    }

    // ── Forbidden wording ─────────────────────────────────────────────────────

    public function test_page_does_not_contain_forbidden_wording(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.referrals'));

        $response->assertDontSee('Community');
        $response->assertDontSee('Classement');
        $response->assertDontSee('Top parrain');
        $response->assertDontSee('Growth hacking');
        $response->assertDontSee('MLM');
        $response->assertDontSee('Parrainez et gagnez');
        $response->assertDontSee('Boostez votre réseau');
    }

    // ── Empty states ──────────────────────────────────────────────────────────

    public function test_page_shows_empty_state_when_no_data(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get(route('admin.referrals'));

        $response->assertSee('Aucune invitation récente');
        $response->assertSee('Aucune activation récente');
        $response->assertSee('Aucune contribution à afficher pour le moment');
    }
}
