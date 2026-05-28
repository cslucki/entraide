<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T1401ScopeDivergentCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_filters_by_organization_id_not_community_id(): void
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();

        $user = User::factory()->create();

        $serviceId = (string) \Illuminate\Support\Str::uuid();
        \DB::table('services')->insert([
            'id'              => $serviceId,
            'user_id'         => $user->id,
            'organization_id' => $org1->id,
            'organization_id' => $org2->id,
            'title'           => 'Divergent service',
            'description'     => 'test',
            'category_id'     => \App\Models\Category::factory()->create()->id,
            'delivery_mode'   => 'remote',
            'points_cost'     => 100,
            'status'          => 'active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        app()->instance('current_organization', $org2);

        $this->assertNotNull(
            Service::find($serviceId),
            'Service avec organization_id=org2 doit être visible quand current_organization=org2'
        );

        app()->instance('current_organization', $org1);

        $this->assertNull(
            Service::find($serviceId),
            'Service avec organization_id=org2 ne doit PAS être visible quand current_organization=org1'
        );
    }
}
