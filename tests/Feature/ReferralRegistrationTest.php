<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $referrer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'testorg']);
        $this->referrer = User::factory()->create([
            'organization_id' => $this->org->id,
            'referral_code' => 'testref',
        ]);

        app()->instance('current_organization', $this->org);
    }

    public function test_register_with_valid_ref_creates_referral(): void
    {
        $response = $this->post("/org/{$this->org->slug}/register", [
            'name' => 'Member',
            'first_name' => 'New',
            'email' => 'new@example.com',
            'phone' => '+33612345678',
            'country_code' => 'FR',
            'password' => 'password',
            'password_confirmation' => 'password',
            'ref' => 'testref',
        ]);

        $response->assertRedirect();

        $referred = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($referred);

        $referral = Referral::where('referrer_user_id', $this->referrer->id)
            ->where('referred_user_id', $referred->id)
            ->first();

        $this->assertNotNull($referral);
        $this->assertEquals('pending', $referral->status);
        $this->assertEquals($this->org->id, $referral->organization_id);
    }

    public function test_register_with_invalid_ref_still_succeeds(): void
    {
        $response = $this->post("/org/{$this->org->slug}/register", [
            'name' => 'Member',
            'first_name' => 'New',
            'email' => 'new@example.com',
            'phone' => '+33612345678',
            'country_code' => 'FR',
            'password' => 'password',
            'password_confirmation' => 'password',
            'ref' => 'nonexistent',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_dashboard_shows_user_navigation_shortcuts(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'referral_code' => 'mylink',
        ]);

        $response = $this->actingAs($user)->get("/org/{$this->org->slug}/dashboard");

        $response->assertStatus(200);
        $response->assertSee('Profil public');
        $response->assertSee('Agent IA');
        $response->assertSee('Invitations');
        $response->assertSee('Mes points');
        $response->assertSee(route('organization.invitations.index', ['organization' => $this->org->slug]));
        $response->assertDontSee('Mes invitations');
        $response->assertDontSee(__('dashboard.metrics.balance'));
    }
}
