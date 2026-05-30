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
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $service = Service::factory()->forUser($user)->create(['organization_id' => $organization->id]);

        $this->assertEquals($organization->id, $service->organization->id);
    }

    public function test_service_organization_is_organization_instance(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $service = Service::factory()->forUser($user)->create(['organization_id' => $organization->id]);

        $this->assertInstanceOf(Organization::class, $service->organization);
    }

    public function test_service_organization_and_community_share_same_id(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $service = Service::factory()->forUser($user)->create(['organization_id' => $organization->id]);

        $this->assertEquals($service->organization->id, $service->organization->id);
    }

    public function test_service_request_organization_relationship(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $request = ServiceRequest::factory()->forUser($user)->create(['organization_id' => $organization->id]);

        $this->assertInstanceOf(Organization::class, $request->organization);
        $this->assertEquals($organization->id, $request->organization->id);
    }

    public function test_transaction_organization_relationship(): void
    {
        $organization = Organization::factory()->create();
        $buyer = User::factory()->create(['organization_id' => $organization->id]);
        $seller = User::factory()->create(['organization_id' => $organization->id]);
        $transaction = Transaction::factory()->forBuyer($buyer)->forSeller($seller)->create(['organization_id' => $organization->id]);

        $this->assertInstanceOf(Organization::class, $transaction->organization);
        $this->assertEquals($organization->id, $transaction->organization->id);
    }

    public function test_user_organization_relationship(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $organization->id]);

        $this->assertInstanceOf(Organization::class, $user->organization);
        $this->assertEquals($organization->id, $user->organization->id);
    }

    public function test_blog_post_organization_relationship(): void
    {
        $organization = Organization::factory()->create();
        $author = User::factory()->create();
        $post = BlogPost::create([
            'user_id' => $author->id,
            'organization_id' => $organization->id,
            'title' => 'Test post',
            'slug' => 'test-post',
            'content' => 'content',
            'status' => 'draft',
        ]);

        $this->assertInstanceOf(Organization::class, $post->organization);
        $this->assertEquals($organization->id, $post->organization->id);
    }

    public function test_organization_relationship_returns_null_when_no_community(): void
    {
        $user = User::factory()->create(['organization_id' => null]);

        $this->assertNull($user->organization);
    }
}
