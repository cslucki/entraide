<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopManifestoTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $owner;

    protected Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        app()->instance('current_organization', $this->organization);
        $this->loop = (new LoopService)->createLoop($this->owner, 'Manifesto Loop');
    }

    private function makePost(string $title): BlogPost
    {
        $post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'title' => $title,
            'content' => 'Body',
            'status' => 'draft',
        ]);
        $post->loops()->attach($this->loop->id);

        return $post;
    }

    public function test_manifesto_is_null_by_default(): void
    {
        $this->assertNull($this->loop->manifesto);
    }

    public function test_manifesto_relation_returns_the_designated_post(): void
    {
        $post = $this->makePost('Notre Manifeste');
        $this->loop->update(['manifesto_blog_post_id' => $post->id]);

        $this->assertSame($post->id, $this->loop->fresh()->manifesto->id);
    }

    public function test_manifesto_is_null_when_the_post_is_soft_deleted(): void
    {
        $post = $this->makePost('À supprimer');
        $this->loop->update(['manifesto_blog_post_id' => $post->id]);
        $this->assertNotNull($this->loop->fresh()->manifesto);

        $post->delete(); // soft delete

        $this->assertNull($this->loop->fresh()->manifesto);
    }
}
