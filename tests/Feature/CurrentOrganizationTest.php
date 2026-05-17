<?php

namespace Tests\Feature;

use App\Models\Community;
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
        $org = Community::factory()->create();

        app()->instance('current_organization', $org);

        $this->assertSame($org, CurrentOrganization::get());
    }

    public function test_get_falls_back_to_current_community(): void
    {
        $community = Community::factory()->create();

        app()->instance('current_community', $community);

        $this->assertSame($community, CurrentOrganization::get());
    }

    public function test_get_prefers_organization_over_community(): void
    {
        $org = Community::factory()->create();
        $community = Community::factory()->create();

        app()->instance('current_organization', $org);
        app()->instance('current_community', $community);

        $this->assertSame($org, CurrentOrganization::get());
    }

    public function test_get_uses_organization_when_values_differ(): void
    {
        $org = Community::factory()->create();
        $community = Community::factory()->create();

        app()->instance('current_organization', $org);
        app()->instance('current_community', $community);

        $result = CurrentOrganization::get();

        $this->assertNotEquals($community->id, $result->id);
        $this->assertEquals($org->id, $result->id);
    }

    public function test_get_fallbacks_to_community_only_as_legacy_bound(): void
    {
        $community = Community::factory()->create();

        app()->instance('current_community', $community);

        $result = CurrentOrganization::get();

        $this->assertNotNull($result);
        $this->assertEquals($community->id, $result->id);
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
        $org = Community::factory()->create();

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
        $org = Community::factory()->create();

        app()->instance('current_organization', $org);

        $this->assertSame($org, currentOrganization());
    }

    public function test_helper_falls_back_to_current_community(): void
    {
        $community = Community::factory()->create();

        app()->instance('current_community', $community);

        $this->assertSame($community, currentOrganization());
    }

    public function test_helper_returns_null_when_nothing_bound(): void
    {
        $this->assertNull(currentOrganization());
    }
}
