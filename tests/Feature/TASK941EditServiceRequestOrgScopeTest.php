<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Tests\TestCase;

class TASK941EditServiceRequestOrgScopeTest extends TestCase
{
    private Organization $org;

    private User $owner;

    private User $other;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // TASK-1230 : noms DETERMINISTES (Faker `company()`, `firstName()`,
        // `lastName()` sinon) — les pages service/demande rendent le nom du
        // proprietaire, celui de l'Organization et celui de l'utilisateur
        // connecte ; `assertDontSee('Modifier')` fouille tout le HTML.
        // Meme correction a la racine que TASK-1218 / TASK-1228.
        $this->org = Organization::factory()->create([
            'is_active' => true,
            'name' => 'Scope Sentinel Org',
        ]);
        $this->owner = User::factory()->complete()->create([
            'organization_id' => $this->org->id,
            'name' => 'Owner',
            'first_name' => 'Sentinel',
        ]);
        $this->other = User::factory()->complete()->create([
            'organization_id' => $this->org->id,
            'name' => 'Other',
            'first_name' => 'Sentinel',
        ]);
        $this->category = Category::factory()->create(['organization_id' => $this->org->id]);
    }

    public function test_owner_sees_modifier_on_service_show_org(): void
    {
        $service = Service::factory()
            ->forUser($this->owner)
            ->forCategory($this->category)
            ->create(['organization_id' => $this->org->id]);

        $response = $this->actingAs($this->owner)
            ->get("/org/{$this->org->slug}/services/{$service->id}");

        $response->assertOk();
        $response->assertSee('Modifier');
    }

    public function test_owner_can_access_service_edit_org(): void
    {
        $service = Service::factory()
            ->forUser($this->owner)
            ->forCategory($this->category)
            ->create(['organization_id' => $this->org->id]);

        $response = $this->actingAs($this->owner)
            ->get("/org/{$this->org->slug}/services/{$service->id}/edit");

        $response->assertOk();
    }

    public function test_non_owner_does_not_see_modifier_on_service_show_org(): void
    {
        $service = Service::factory()
            ->forUser($this->owner)
            ->forCategory($this->category)
            ->create(['organization_id' => $this->org->id]);

        $response = $this->actingAs($this->other)
            ->get("/org/{$this->org->slug}/services/{$service->id}");

        $response->assertOk();
        $response->assertDontSee('Modifier');

        $response = $this->actingAs($this->other)
            ->get("/org/{$this->org->slug}/services/{$service->id}/edit");

        $response->assertForbidden();
    }

    public function test_owner_sees_modifier_on_request_show_org(): void
    {
        $request = ServiceRequest::factory()
            ->forUser($this->owner)
            ->create([
                'organization_id' => $this->org->id,
                'category_id' => $this->category->id,
            ]);

        $response = $this->actingAs($this->owner)
            ->get("/org/{$this->org->slug}/requests/{$request->id}");

        $response->assertOk();
        $response->assertSee('Modifier');
    }

    public function test_owner_can_access_request_edit_org(): void
    {
        $request = ServiceRequest::factory()
            ->forUser($this->owner)
            ->create([
                'organization_id' => $this->org->id,
                'category_id' => $this->category->id,
            ]);

        $response = $this->actingAs($this->owner)
            ->get("/org/{$this->org->slug}/requests/{$request->id}/edit");

        $response->assertOk();
    }

    public function test_dashboard_services_shows_modifier_when_authorized(): void
    {
        $service = Service::factory()
            ->forUser($this->owner)
            ->forCategory($this->category)
            ->create(['organization_id' => $this->org->id]);

        $response = $this->actingAs($this->owner)
            ->get("/org/{$this->org->slug}/dashboard/services");

        $response->assertOk();
        $response->assertSee(__('dashboard.edit_service'));
    }

    public function test_dashboard_services_hides_modifier_for_non_owner(): void
    {
        $service = Service::factory()
            ->forUser($this->owner)
            ->forCategory($this->category)
            ->create(['organization_id' => $this->org->id]);

        $response = $this->actingAs($this->other)
            ->get("/org/{$this->org->slug}/dashboard/services");

        $response->assertOk();
        $response->assertDontSee(__('dashboard.edit_service'));
    }

    public function test_dashboard_requests_shows_modifier_when_authorized(): void
    {
        $req = ServiceRequest::factory()
            ->forUser($this->owner)
            ->create([
                'organization_id' => $this->org->id,
                'category_id' => $this->category->id,
            ]);

        $response = $this->actingAs($this->owner)
            ->get("/org/{$this->org->slug}/dashboard/requests");

        $response->assertOk();
        $response->assertSee(__('dashboard.edit_request'));
    }

    public function test_dashboard_requests_hides_modifier_for_non_owner(): void
    {
        $req = ServiceRequest::factory()
            ->forUser($this->owner)
            ->create([
                'organization_id' => $this->org->id,
                'category_id' => $this->category->id,
            ]);

        $response = $this->actingAs($this->other)
            ->get("/org/{$this->org->slug}/dashboard/requests");

        $response->assertOk();
        $response->assertDontSee(__('dashboard.edit_request'));
    }

    public function test_root_service_show_and_edit_still_work(): void
    {
        $service = Service::factory()
            ->forUser($this->owner)
            ->forCategory($this->category)
            ->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $response = $this->actingAs($this->owner)
            ->get(route('services.show', $service));
        $response->assertOk();

        $response = $this->actingAs($this->owner)
            ->get(route('services.edit', $service));
        $response->assertOk();
    }

    public function test_root_request_show_still_works(): void
    {
        $req = ServiceRequest::factory()
            ->forUser($this->owner)
            ->create([
                'organization_id' => $this->org->id,
                'category_id' => $this->category->id,
            ]);

        app()->instance('current_organization', $this->org);

        $response = $this->actingAs($this->owner)
            ->get(route('requests.show', $req));
        $response->assertOk();
    }
}
