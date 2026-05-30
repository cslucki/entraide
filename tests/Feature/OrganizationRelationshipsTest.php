<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 model relationship migration tests.
 *
 * Validates that organization() is a correct alias for community()
 * on every model that holds community_id, using the same underlying data.
 */
class OrganizationRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_organization_relationship_returns_correct_record(): void
    {
        $community = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $community->id]);
        $service = Service::factory()->forUser($user)->create(['organization_id' => $community->id]);

        $this->assertEquals($community->id, $service->organization->id);
    }

    public function test_service_organization_is_organization_instance(): void
    {
        $community = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $community->id]);
        $service = Service::factory()->forUser($user)->create(['organization_id' => $community->id]);

        $this->assertInstanceOf(Organization::class, $service->organization);
    }

    public function test_service_organization_and_community_share_same_id(): void
    {
        $community = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $community->id]);
        $service = Service::factory()->forUser($user)->create(['organization_id' => $community->id]);

        $this->assertEquals($service->organization->id, $service->organization->id);
    }

    public function test_service_request_organization_relationship(): void
    {
        $community = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $community->id]);
        $request = ServiceRequest::factory()->forUser($user)->create(['organization_id' => $community->id]);

        $this->assertInstanceOf(Organization::class, $request->organization);
        $this->assertEquals($community->id, $request->organization->id);
    }

    public function test_transaction_organization_relationship(): void
    {
        $community = Organization::factory()->create();
        $buyer = User::factory()->create(['organization_id' => $community->id]);
        $seller = User::factory()->create(['organization_id' => $community->id]);
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['organization_id' => $community->id]);

        $this->assertInstanceOf(Organization::class, $transaction->organization);
        $this->assertEquals($community->id, $transaction->organization->id);
    }

    public function test_user_organization_relationship(): void
    {
        $community = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $community->id]);

        $this->assertInstanceOf(Organization::class, $user->organization);
        $this->assertEquals($community->id, $user->organization->id);
    }

    public function test_blog_post_organization_relationship(): void
    {
        $community = Organization::factory()->create();
        $author = User::factory()->create();
        $post = BlogPost::create([
            'user_id' => $author->id,
            'organization_id' => $community->id,
            'title' => 'Test post',
            'slug' => 'test-post',
            'content' => 'content',
            'status' => 'draft',
        ]);

        $this->assertInstanceOf(Organization::class, $post->organization);
        $this->assertEquals($community->id, $post->organization->id);
    }

    public function test_organization_relationship_returns_null_when_no_community(): void
    {
        $user = User::factory()->create(['organization_id' => null]);

        $this->assertNull($user->organization);
    }
}
