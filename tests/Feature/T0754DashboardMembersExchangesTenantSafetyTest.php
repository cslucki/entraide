<?php

namespace Tests\Feature;

use App\Models\Favorite;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Referral;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class T0754DashboardMembersExchangesTenantSafetyTest extends TestCase
{
    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

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

    public function test_dashboard_shows_beta_onboarding_steps_as_todo_for_new_user(): void
    {
        [$organizationA] = $this->createOrganizations();
        $user = $this->createUserForOrganization($organizationA, [
            'bio' => null,
            'location' => null,
            'phone' => null,
        ]);

        $response = $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Votre progression dans la boucle')
            ->assertSee('Créer ma présentation')
            ->assertSee('Demander de l’aide')
            ->assertSee('Proposer mon aide')
            ->assertSee('Créer mon agent IA');

        $this->assertSame(4, substr_count($response->getContent(), 'À faire'));
    }

    public function test_dashboard_onboarding_steps_are_completed_from_existing_tenant_data(): void
    {
        [$organizationA] = $this->createOrganizations();
        $user = $this->createUserForOrganization($organizationA, [
            'bio' => 'Présentation membre prête.',
        ]);

        ServiceRequest::factory()->forUser($user)->create([
            'organization_id' => $organizationA->id,
        ]);
        $service = Service::factory()->forUser($user)->create([
            'organization_id' => $organizationA->id,
        ]);
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $organizationA->id,
            'user_id' => $user->id,
        ]);
        Favorite::create([
            'organization_id' => $organizationA->id,
            'user_id' => $user->id,
            'service_id' => $service->id,
        ]);
        Referral::factory()->forOrganization($organizationA)->create([
            'referrer_user_id' => $user->id,
            'referred_user_id' => $this->createUserForOrganization($organizationA)->id,
        ]);

        $response = $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Votre progression dans la boucle')
            ->assertSee('Voir mon profil')
            ->assertSee('Voir mes demandes')
            ->assertSee('Voir mes propositions')
            ->assertSee('Voir mon agent');

        $this->assertSame(4, substr_count($response->getContent(), 'Terminé'));
    }

    public function test_dashboard_onboarding_ignores_data_from_another_organization(): void
    {
        [$organizationA, $organizationB] = $this->createOrganizations();
        $user = $this->createUserForOrganization($organizationA, [
            'bio' => null,
            'location' => null,
            'phone' => null,
        ]);

        ServiceRequest::factory()->forUser($user)->create([
            'organization_id' => $organizationB->id,
        ]);
        $service = Service::factory()->forUser($user)->create([
            'organization_id' => $organizationB->id,
        ]);
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $organizationB->id,
            'user_id' => $user->id,
        ]);
        Favorite::create([
            'organization_id' => $organizationB->id,
            'user_id' => $user->id,
            'service_id' => $service->id,
        ]);
        Referral::factory()->forOrganization($organizationB)->create([
            'referrer_user_id' => $user->id,
            'referred_user_id' => $this->createUserForOrganization($organizationB)->id,
        ]);

        $response = $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Votre progression dans la boucle');

        $this->assertSame(4, substr_count($response->getContent(), 'À faire'));
    }

    public function test_organization_dashboard_onboarding_ctas_use_organization_prefixed_routes(): void
    {
        [$organizationA] = $this->createOrganizations();
        $user = $this->createUserForOrganization($organizationA, [
            'bio' => null,
            'location' => null,
            'phone' => null,
        ]);

        $this->actingAs($user)
            ->get("/org/{$organizationA->slug}/dashboard")
            ->assertOk()
            ->assertSee("/org/{$organizationA->slug}/profile/edit", false)
            ->assertSee("/org/{$organizationA->slug}/requests/create", false)
            ->assertSee("/org/{$organizationA->slug}/services/create", false)
            ->assertSee("/org/{$organizationA->slug}/agent-ia", false);
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
     * @return array{Organization, Organization}
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

        $organizationA->update(['is_default' => true]);

        return [$organizationA, $organizationB];
    }

    private function createUserForOrganization(Organization $organization, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $organization->id,
        ], $attributes));
    }

    private function createCompletedExchange(Organization $organization, string $serviceTitle): Transaction
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
