<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Tests\TestCase;

class T0751UserDashboardMyRequestsServicesTest extends TestCase
{
    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    private function createOrganizations(): array
    {
        $orgA = Organization::factory()->create(['name' => 'T0751 Organization A', 'is_active' => true]);
        $orgB = Organization::factory()->create(['name' => 'T0751 Organization B', 'is_active' => true]);
        $orgA->update(['is_default' => true]);

        return [$orgA, $orgB];
    }

    private function createUserForOrganization(Organization $organization, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $organization->id,
        ], $attributes));
    }

    public function test_dashboard_shows_view_all_on_requests_card(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Voir tout');
    }

    public function test_dashboard_does_not_show_raw_placeholder_mes_services(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Mes :services')
            ->assertDontSee(':services')
            ->assertDontSee('My :services');
    }

    public function test_dashboard_does_not_show_double_plus(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        $response = $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();

        $this->assertStringNotContainsString('+ + ', $response->content());
    }

    public function test_onboarding_request_done_goes_to_list(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        ServiceRequest::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'title' => 'ONBOARDING_REQUEST_DONE',
        ]);

        $orgRoute = route('organization.dashboard.requests', ['organization' => $org->slug]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Voir mes demandes')
            ->assertSee($orgRoute);
    }

    public function test_onboarding_request_todo_goes_to_create(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        $createUrl = route('requests.create');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Créer une demande')
            ->assertSee($createUrl);
    }

    public function test_onboarding_service_done_goes_to_list(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        Service::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'title' => 'ONBOARDING_SERVICE_DONE',
        ]);

        $orgRoute = route('organization.dashboard.services', ['organization' => $org->slug]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Voir mes propositions')
            ->assertSee($orgRoute);
    }

    public function test_onboarding_service_todo_goes_to_create(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        $createUrl = route('services.create');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Créer une proposition')
            ->assertSee($createUrl);
    }

    public function test_my_requests_lists_only_user_requests(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org, ['name' => 'REQUEST_OWNER']);
        $other = $this->createUserForOrganization($org, ['name' => 'OTHER_USER']);

        ServiceRequest::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'title' => 'MY_REQUEST_VISIBLE',
        ]);

        ServiceRequest::factory()->create([
            'user_id' => $other->id,
            'organization_id' => $org->id,
            'title' => 'OTHER_REQUEST_HIDDEN',
        ]);

        $this->actingAs($user)
            ->get('/dashboard/requests')
            ->assertOk()
            ->assertSee('MY_REQUEST_VISIBLE')
            ->assertDontSee('OTHER_REQUEST_HIDDEN');
    }

    public function test_my_requests_excludes_other_organization(): void
    {
        [$orgA, $orgB] = $this->createOrganizations();
        $user = $this->createUserForOrganization($orgA);

        ServiceRequest::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'title' => 'ORG_A_VISIBLE',
        ]);

        ServiceRequest::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'title' => 'ORG_B_HIDDEN',
        ]);

        $this->actingAs($user)
            ->get('/dashboard/requests')
            ->assertOk()
            ->assertSee('ORG_A_VISIBLE')
            ->assertDontSee('ORG_B_HIDDEN');
    }

    public function test_request_detail_forbidden_for_other_user(): void
    {
        [$org] = $this->createOrganizations();
        $owner = $this->createUserForOrganization($org);
        $other = $this->createUserForOrganization($org);

        $request = ServiceRequest::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($other)
            ->get("/dashboard/requests/{$request->id}")
            ->assertForbidden();
    }

    public function test_my_services_lists_only_user_services(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org, ['name' => 'SERVICE_OWNER']);
        $other = $this->createUserForOrganization($org, ['name' => 'OTHER_USER']);

        Service::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'title' => 'MY_SERVICE_VISIBLE',
        ]);

        Service::factory()->create([
            'user_id' => $other->id,
            'organization_id' => $org->id,
            'title' => 'OTHER_SERVICE_HIDDEN',
        ]);

        $this->actingAs($user)
            ->get('/dashboard/services')
            ->assertOk()
            ->assertSee('MY_SERVICE_VISIBLE')
            ->assertDontSee('OTHER_SERVICE_HIDDEN');
    }

    public function test_my_services_excludes_other_organization(): void
    {
        [$orgA, $orgB] = $this->createOrganizations();
        $user = $this->createUserForOrganization($orgA);

        Service::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $orgA->id,
            'title' => 'ORG_A_VISIBLE',
        ]);

        Service::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $orgB->id,
            'title' => 'ORG_B_HIDDEN',
        ]);

        $this->actingAs($user)
            ->get('/dashboard/services')
            ->assertOk()
            ->assertSee('ORG_A_VISIBLE')
            ->assertDontSee('ORG_B_HIDDEN');
    }

    public function test_service_detail_forbidden_for_other_user(): void
    {
        [$org] = $this->createOrganizations();
        $owner = $this->createUserForOrganization($org);
        $other = $this->createUserForOrganization($org);

        $service = Service::factory()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($other)
            ->get("/dashboard/services/{$service->id}")
            ->assertForbidden();
    }

    public function test_org_admin_dashboard_title_contains_organization_name(): void
    {
        [$org] = $this->createOrganizations();
        $admin = User::factory()->create([
            'organization_id' => $org->id,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get("/org/{$org->slug}/admin")
            ->assertOk()
            ->assertSee($org->name);
    }

    public function test_my_requests_page_accessible(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        $this->actingAs($user)
            ->get('/dashboard/requests')
            ->assertOk();
    }

    public function test_my_services_page_accessible(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        $this->actingAs($user)
            ->get('/dashboard/services')
            ->assertOk();
    }

    public function test_request_detail_accessible_by_owner(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        $request = ServiceRequest::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->get("/dashboard/requests/{$request->id}")
            ->assertOk()
            ->assertSee($request->title);
    }

    public function test_service_detail_accessible_by_owner(): void
    {
        [$org] = $this->createOrganizations();
        $user = $this->createUserForOrganization($org);

        $service = Service::factory()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingAs($user)
            ->get("/dashboard/services/{$service->id}")
            ->assertOk()
            ->assertSee($service->title);
    }
}
