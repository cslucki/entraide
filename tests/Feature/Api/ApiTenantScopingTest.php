<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class ApiTenantScopingTest extends TestCase
{
    private Organization $organizationA;

    private Organization $organizationB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create(['is_active' => true, 'slug' => 'org-alpha']);
        $this->organizationB = Organization::factory()->create(['is_active' => true, 'slug' => 'org-beta']);
    }

    public function test_public_services_use_default_organization(): void
    {
        Setting::set('default_organization_id', $this->organizationA->id);

        Service::factory()->count(3)->create(['status' => 'active', 'community_id' => $this->organizationA->id]);
        Service::factory()->count(2)->create(['status' => 'active', 'community_id' => $this->organizationB->id]);

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    public function test_public_services_ignore_organization_header_and_use_default_organization(): void
    {
        Setting::set('default_organization_id', $this->organizationA->id);

        Service::factory()->count(3)->create(['status' => 'active', 'community_id' => $this->organizationA->id]);
        Service::factory()->count(2)->create(['status' => 'active', 'community_id' => $this->organizationB->id]);

        $this->withHeaders(['X-Organization' => 'org-beta'])
            ->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    public function test_service_show_rejects_cross_organization_access(): void
    {
        Setting::set('default_organization_id', $this->organizationB->id);

        $serviceA = Service::factory()->create(['status' => 'active', 'community_id' => $this->organizationA->id]);

        $this->getJson("/api/services/{$serviceA->id}")
            ->assertNotFound();
    }

    public function test_transactions_isolated_by_authenticated_user_organization(): void
    {
        $user = User::factory()->create(['community_id' => $this->organizationA->id, 'points_balance' => 500]);
        $sameOrganizationUser = User::factory()->create(['community_id' => $this->organizationA->id]);
        $otherOrganizationUser = User::factory()->create(['community_id' => $this->organizationB->id]);

        Transaction::factory()->count(2)->create([
            'buyer_id' => $user->id,
            'seller_id' => $sameOrganizationUser->id,
            'community_id' => $this->organizationA->id,
        ]);

        Transaction::factory()->create([
            'buyer_id' => $sameOrganizationUser->id,
            'seller_id' => $user->id,
            'community_id' => $this->organizationA->id,
        ]);

        Transaction::factory()->count(4)->create([
            'buyer_id' => $sameOrganizationUser->id,
            'seller_id' => User::factory()->create(['community_id' => $this->organizationA->id])->id,
            'community_id' => $this->organizationA->id,
        ]);

        Transaction::factory()->count(2)->create([
            'buyer_id' => $user->id,
            'seller_id' => $otherOrganizationUser->id,
            'community_id' => $this->organizationB->id,
        ]);

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonPath('total', 3);
    }

    public function test_authenticated_user_organization_wins_over_default_organization(): void
    {
        Setting::set('default_organization_id', $this->organizationA->id);

        $user = User::factory()->create(['community_id' => $this->organizationB->id, 'points_balance' => 500]);
        $organizationBUser = User::factory()->create(['community_id' => $this->organizationB->id]);

        Transaction::factory()->create([
            'buyer_id' => $user->id,
            'seller_id' => $organizationBUser->id,
            'community_id' => $this->organizationB->id,
        ]);

        Transaction::factory()->count(2)->create([
            'buyer_id' => $user->id,
            'seller_id' => User::factory()->create(['community_id' => $this->organizationA->id])->id,
            'community_id' => $this->organizationA->id,
        ]);

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/transactions')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.community_id', $this->organizationB->id);
    }

    public function test_authenticated_user_without_organization_fails_safe(): void
    {
        Setting::set('default_organization_id', $this->organizationA->id);

        $user = User::factory()->create(['community_id' => null, 'points_balance' => 500]);

        Transaction::factory()->create([
            'buyer_id' => $user->id,
            'seller_id' => User::factory()->create(['community_id' => $this->organizationA->id])->id,
            'community_id' => $this->organizationA->id,
        ]);

        $response = $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/transactions')
            ->assertForbidden();

        $this->assertNull($response->json('data'));
    }

    public function test_authenticated_user_with_inactive_organization_fails_safe(): void
    {
        Setting::set('default_organization_id', $this->organizationA->id);

        $inactiveOrganization = Organization::factory()->create(['is_active' => false]);
        $user = User::factory()->create(['community_id' => $inactiveOrganization->id, 'points_balance' => 500]);

        Transaction::factory()->create([
            'buyer_id' => $user->id,
            'seller_id' => User::factory()->create(['community_id' => $this->organizationA->id])->id,
            'community_id' => $this->organizationA->id,
        ]);

        $response = $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/transactions')
            ->assertForbidden();

        $this->assertNull($response->json('data'));
    }

    public function test_transaction_store_uses_server_resolved_org(): void
    {
        $buyer = User::factory()->create(['community_id' => $this->organizationA->id, 'points_balance' => 300]);
        $seller = User::factory()->create(['community_id' => $this->organizationA->id]);
        $service = Service::factory()->forUser($seller)->create([
            'status' => 'active',
            'community_id' => $this->organizationA->id,
        ]);

        $this->withToken($buyer->createToken('api')->plainTextToken)
            ->postJson('/api/transactions', [
                'service_id' => $service->id,
                'points_proposed' => 100,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('transactions', [
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'community_id' => $this->organizationA->id,
        ]);
    }

    public function test_default_org_fallback_when_no_user_no_header(): void
    {
        Setting::set('default_organization_id', $this->organizationB->id);

        Service::factory()->count(2)->create(['status' => 'active', 'community_id' => $this->organizationB->id]);
        Service::factory()->count(3)->create(['status' => 'active', 'community_id' => $this->organizationA->id]);

        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }

    public function test_middleware_binds_current_organization_for_authenticated_requests(): void
    {
        $user = User::factory()->create(['community_id' => $this->organizationA->id]);

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/transactions');

        $this->assertTrue(app()->bound('current_organization'));
        $this->assertEquals($this->organizationA->id, app('current_organization')->id);
    }

    public function test_middleware_binds_legacy_runtime_tenant_key_for_backward_compat(): void
    {
        $user = User::factory()->create(['community_id' => $this->organizationB->id]);

        $this->withToken($user->createToken('api')->plainTextToken)
            ->getJson('/api/transactions');

        $this->assertTrue(app()->bound('current_community'));
        $this->assertEquals($this->organizationB->id, app('current_community')->id);
    }
}
