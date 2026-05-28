<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveUrlOrganization;
use App\Models\Community;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class T0754DashboardMembersExchangesTenantSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        ResolveUrlOrganization::$defaultOrganizationId = null;

        parent::tearDown();
    }

    public function test_dashboard_responds_for_user_with_resolved_organization(): void
    {
        [$organizationA] = $this->createOrganizations();
        $user = $this->createUserForOrganization($organizationA, ['name' => 'ORG_A_VISIBLE_MEMBER']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Tableau de bord');
    }

    public function test_dashboard_does_not_show_data_from_another_organization(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();
        $user = $this->createUserForOrganization($organizationA, ['name' => 'ORG_A_VISIBLE_MEMBER']);

        Service::factory()->forUser($user)->create([
            'title' => 'ORG_A_VISIBLE_EXCHANGE',
            'organization_id' => $organizationA->id,
        ]);

        Service::factory()->forUser($user)->create([
            'title' => 'ORG_B_HIDDEN_EXCHANGE',
            'organization_id' => $organizationB->id,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('ORG_A_VISIBLE_EXCHANGE')
            ->assertDontSee('ORG_B_HIDDEN_EXCHANGE');
    }

    public function test_members_lists_only_members_from_resolved_organization(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();
        $user = $this->createUserForOrganization($organizationA);

        $this->createUserForOrganization($organizationA, ['name' => 'ORG_A_VISIBLE_MEMBER']);
        $this->createUserForOrganization($organizationB, ['name' => 'ORG_B_HIDDEN_MEMBER']);

        $this->actingAs($user)
            ->get('/membres')
            ->assertOk()
            ->assertSee('ORG_A_VISIBLE_MEMBER')
            ->assertDontSee('ORG_B_HIDDEN_MEMBER');
    }

    public function test_exchanges_lists_only_completed_transactions_from_resolved_organization(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();
        $user = $this->createUserForOrganization($organizationA);

        $this->createCompletedExchange($organizationA, 'ORG_A_VISIBLE_EXCHANGE');
        $this->createCompletedExchange($organizationB, 'ORG_B_HIDDEN_EXCHANGE');

        $this->actingAs($user)
            ->get('/echanges')
            ->assertOk()
            ->assertSee('ORG_A_VISIBLE_EXCHANGE')
            ->assertDontSee('ORG_B_HIDDEN_EXCHANGE');
    }

    /**
     * @return array{Community, Community}
     */
    private function createOrganizations(): array
    {
        $organizationA = Organization::factory()->create([
            'name' => 'T0754 Organization A',
            'is_active' => true,
        ]);

        $organizationB = Organization::factory()->create([
            'name' => 'T0754 Organization B',
            'is_active' => true,
        ]);

        ResolveUrlOrganization::$defaultOrganizationId = (string) $organizationA->id;

        return [$organizationA, $organizationB];
    }

    private function createUserForOrganization(Community $organization, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $organization->id,
        ], $attributes));
    }

    private function createCompletedExchange(Community $organization, string $serviceTitle): Transaction
    {
        $buyer = $this->createUserForOrganization($organization);
        $seller = $this->createUserForOrganization($organization);
        $service = Service::factory()->forUser($seller)->create([
            'title' => $serviceTitle,
            'organization_id' => $organization->id,
        ]);

        return Transaction::factory()
            ->forService($service)
            ->forBuyer($buyer)
            ->completed()
            ->create([
                'organization_id' => $organization->id,
            ]);
    }
}
