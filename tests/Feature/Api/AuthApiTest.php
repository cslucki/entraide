<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\User;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    public function test_register_creates_user_with_welcome_bonus(): void
    {
        $organization = Organization::factory()->create(['slug' => 'main', 'is_active' => true]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alice Dupont',
            'email' => 'alice@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'points_balance']]);

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
            'points_balance' => 100,
            'organization_id' => $organization->id,
        ]);

        $user = User::where('email', 'alice@example.com')->first();
        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $user->id,
            'delta' => 100,
            'reason' => 'welcome_bonus',
        ]);
    }

    public function test_register_validates_required_fields(): void
    {
        $this->postJson('/api/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'bob@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_returns_token_for_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'carol@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'carol@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'points_balance']]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::factory()->create(['email' => 'dan@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'dan@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_returns_403_for_banned_user(): void
    {
        User::factory()->create([
            'email' => 'eve@example.com',
            'banned_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'eve@example.com',
            'password' => 'password',
        ])->assertForbidden();
    }

    public function test_logout_revokes_token(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        // Token must be removed from the database
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_protected_route_requires_token(): void
    {
        $this->getJson('/api/profile')
            ->assertUnauthorized();
    }
}
