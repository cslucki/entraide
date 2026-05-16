<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WithTestOrganization;
use Tests\TestCase;

class BanMiddlewareTest extends TestCase
{
    use RefreshDatabase;
    use WithTestOrganization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganization();
    }

    public function test_banned_user_is_logged_out_and_redirected(): void
    {
        $user = User::factory()->create(['banned_at' => now()]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_banned_user_redirect_has_error_message(): void
    {
        $user = User::factory()->create(['banned_at' => now()]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('suspendu', session('error'));
    }

    public function test_active_user_is_not_affected(): void
    {
        $user = $this->orgUser(['banned_at' => null]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_is_not_affected(): void
    {
        $response = $this->get('/');

        $response->assertOk();
    }
}
