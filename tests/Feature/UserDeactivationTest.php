<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Tests\TestCase;

class UserDeactivationTest extends TestCase
{
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Organization::where('is_default', true)->update(['is_default' => false]);
        $this->organization = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function createUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'password' => bcrypt('password'),
        ], $overrides));
    }

    public function test_deactivated_user_absent_from_members_page(): void
    {
        $activeUser = $this->createUser(['banned_at' => null]);
        $deactivatedUser = $this->createUser(['banned_at' => now()]);

        $response = $this->get('/membres');

        $response->assertOk();
        $response->assertSee($activeUser->name);
        $response->assertDontSee($deactivatedUser->name);
    }

    public function test_active_user_present_on_members_page(): void
    {
        $activeUser = $this->createUser(['banned_at' => null]);

        $response = $this->get('/membres');

        $response->assertOk();
        $response->assertSee($activeUser->name);
    }

    public function test_web_login_refused_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now(), 'password' => bcrypt('password')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_web_session_invalidated_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_api_login_refused_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertForbidden();
    }

    public function test_old_sanctum_token_rejected_after_deactivation(): void
    {
        $user = $this->createUser(['banned_at' => null]);
        $token = $user->createToken('test')->plainTextToken;

        $user->update(['banned_at' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/profile');

        $response->assertUnauthorized();
    }

    public function test_sanctum_tokens_revoked_on_deactivation(): void
    {
        $user = $this->createUser(['banned_at' => null]);
        $user->createToken('token-a')->plainTextToken;
        $user->createToken('token-b')->plainTextToken;

        $this->assertSame(2, $user->tokens()->count());

        $user->update(['banned_at' => now()]);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_no_repeated_token_revocation_on_subsequent_save(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $user->createToken('token-after-ban')->plainTextToken;
        $this->assertSame(1, $user->tokens()->count());

        $tokenCount = $user->tokens()->count();

        $user->update(['bio' => 'Some bio update while still banned']);

        $this->assertSame($tokenCount, $user->tokens()->count());
    }

    public function test_public_web_profile_returns_404_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->get(route('profile.show', $user));

        $response->assertNotFound();
    }

    public function test_public_api_profile_returns_404_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->getJson('/api/users/'.$user->id);

        $response->assertNotFound();
    }

    public function test_public_service_detail_returns_404_when_owner_is_deactivated(): void
    {
        $owner = $this->createUser(['banned_at' => now()]);
        $category = Category::factory()->create(['organization_id' => $this->organization->id]);
        $service = Service::factory()
            ->forUser($owner)
            ->forCategory($category)
            ->create([
                'organization_id' => $this->organization->id,
                'status' => 'active',
            ]);

        $this->get(route('organization.services.show', [$this->organization, $service]))
            ->assertNotFound();
    }

    public function test_public_request_detail_returns_404_when_owner_is_deactivated(): void
    {
        $owner = $this->createUser(['banned_at' => now()]);
        $category = Category::factory()->create(['organization_id' => $this->organization->id]);
        $serviceRequest = ServiceRequest::factory()
            ->forUser($owner)
            ->create([
                'organization_id' => $this->organization->id,
                'category_id' => $category->id,
                'status' => 'open',
            ]);

        $this->get(route('organization.requests.show', [$this->organization, $serviceRequest]))
            ->assertNotFound();
    }

    public function test_search_excludes_deactivated_users(): void
    {
        $activeUser = $this->createUser(['banned_at' => null, 'name' => 'UniqueActiveName']);
        $deactivatedUser = $this->createUser(['banned_at' => now(), 'name' => 'UniqueBannedName']);

        $response = $this->get('/search?q=Unique');

        $response->assertOk();
        $response->assertDontSee('UniqueBannedName');
    }

    public function test_password_reset_refused_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->post('/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertSessionHas('status');
    }

    public function test_password_change_by_token_refused_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->post('/reset-password', [
            'token' => 'fake-token',
            'email' => $user->email,
            'password' => 'NewPass123!!',
            'password_confirmation' => 'NewPass123!!',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_admin_login_as_refused_for_deactivated_user(): void
    {
        $admin = $this->createUser(['is_admin' => true]);
        $deactivatedUser = $this->createUser(['banned_at' => now()]);

        $response = $this->actingAs($admin)
            ->post(route('admin.users.login-as', $deactivatedUser));

        $response->assertSessionHas('error');
    }

    public function test_reactivation_restores_login_and_discoverability(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $user->update(['banned_at' => null]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);

        auth()->logout();

        $membersResponse = $this->get('/membres');
        $membersResponse->assertOk();
        $membersResponse->assertSee($user->name);
    }

    public function test_api_middleware_blocks_deactivated_user_with_existing_token(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $user->createToken('post-ban-token');
        $this->assertSame(1, $user->tokens()->count());

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/profile');

        $response->assertForbidden();
        $response->assertJson(['message' => trans('auth.deactivated')]);
    }

    public function test_organization_isolation_preserved_for_members_page(): void
    {
        $otherOrg = Organization::factory()->create(['slug' => 'other', 'is_active' => true]);
        $userInOtherOrg = User::factory()->create([
            'organization_id' => $otherOrg->id,
            'banned_at' => null,
            'name' => 'OtherOrgUser',
        ]);

        $this->createUser(['banned_at' => null, 'name' => 'MainOrgUser']);

        $response = $this->get('/membres');

        $response->assertOk();
        $response->assertSee('MainOrgUser');
        $response->assertDontSee('OtherOrgUser');
    }
}
