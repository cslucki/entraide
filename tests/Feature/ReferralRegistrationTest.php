<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Community $org;

    private User $referrer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Community::factory()->create(['slug' => 'testorg']);
        $this->referrer = User::factory()->create([
            'community_id' => $this->org->id,
            'referral_code' => 'testref',
        ]);

        app()->instance('current_organization', $this->org);
    }

    public function test_register_with_valid_ref_creates_referral(): void
    {
        $response = $this->post("/{$this->org->slug}/register", [
            'name' => 'New Member',
            'email' => 'new@example.com',
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
        $this->assertEquals($this->org->id, $referral->community_id);
    }

    public function test_register_with_invalid_ref_still_succeeds(): void
    {
        $response = $this->post("/{$this->org->slug}/register", [
            'name' => 'New Member',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'ref' => 'nonexistent',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_dashboard_shows_referral_link(): void
    {
        $user = User::factory()->create([
            'community_id' => $this->org->id,
            'referral_code' => 'mylink',
        ]);

        $response = $this->actingAs($user)->get("/{$this->org->slug}/dashboard");

        $response->assertStatus(200);
        $response->assertSee('Mes invitations');
        $response->assertSee('mylink');
        $response->assertSee("/{$this->org->slug}/register?ref=mylink");
    }
}
