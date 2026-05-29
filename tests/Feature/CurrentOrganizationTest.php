<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the CurrentOrganization runtime resolution class and
 * the currentOrganization() global helper function.
 */
class CurrentOrganizationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // CurrentOrganization::get()
    // -------------------------------------------------------------------------

    public function test_get_returns_current_organization_when_bound(): void
    {
        $org = Organization::factory()->create();

        app()->instance('current_organization', $org);

        $this->assertSame($org, CurrentOrganization::get());
    }

    public function test_get_returns_null_when_nothing_bound(): void
    {
        $this->assertNull(CurrentOrganization::get());
    }

    // -------------------------------------------------------------------------
    // CurrentOrganization::id()
    // -------------------------------------------------------------------------

    public function test_id_returns_organization_id_when_bound(): void
    {
        $org = Organization::factory()->create();

        app()->instance('current_organization', $org);

        $this->assertEquals($org->id, CurrentOrganization::id());
    }

    public function test_id_returns_null_when_nothing_bound(): void
    {
        $this->assertNull(CurrentOrganization::id());
    }

    // -------------------------------------------------------------------------
    // currentOrganization() global helper
    // -------------------------------------------------------------------------

    public function test_helper_returns_current_organization_when_bound(): void
    {
        $org = Organization::factory()->create();

        app()->instance('current_organization', $org);

        $this->assertSame($org, currentOrganization());
    }

    public function test_helper_returns_null_when_nothing_bound(): void
    {
        $this->assertNull(currentOrganization());
    }
}
