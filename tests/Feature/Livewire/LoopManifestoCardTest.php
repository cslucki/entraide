<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LoopManifestoCard;
use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoopManifestoCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $owner;

    private User $member;

    private User $moderator;

    private User $nonMember;

    private User $crossUser;

    private Loop $loop;

    private Loop $otherLoop;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->owner = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->moderator = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        app()->instance('current_organization', $this->organization);
        $this->service = new LoopService;

        $this->loop = $this->service->createLoop($this->owner, 'Manifesto Loop');
        $this->service->addMember($this->loop, $this->member, 'member');
        $this->service->addMember($this->loop, $this->moderator, 'moderator');

        $this->otherLoop = $this->service->createLoop($this->crossUser, 'Other Loop');
    }

    private function linkedPost(string $title = 'Doc'): BlogPost
    {
        $post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'title' => $title.' '.uniqid(),
            'content' => 'Body',
            'status' => 'draft',
        ]);
        $post->loops()->attach($this->loop->id);

        return $post;
    }

    public function test_member_sees_empty_state(): void
    {
        $this->actingAs($this->member);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.manifesto_pitch'));
    }

    public function test_moderator_can_no_longer_designate(): void
    {
        $post = $this->linkedPost('Manifeste');
        $this->actingAs($this->moderator);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $post->id);

        // TASK-1079: editing the Manifesto is reserved to the Loop owner, the
        // scoped Organization admin and the super-admin. The Manifesto is the
        // Loop's founding text, not day-to-day moderation, so a moderator no
        // longer qualifies.
        $this->assertNull($this->loop->fresh()->manifesto_blog_post_id);
    }

    public function test_regular_member_cannot_designate(): void
    {
        $post = $this->linkedPost();
        $this->actingAs($this->member);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $post->id);

        $this->assertNull($this->loop->fresh()->manifesto_blog_post_id);
    }

    public function test_cannot_designate_article_of_another_organization(): void
    {
        $foreign = BlogPost::create([
            'user_id' => $this->crossUser->id,
            'organization_id' => $this->otherOrganization->id,
            'title' => 'Foreign '.uniqid(),
            'content' => 'x',
            'status' => 'draft',
        ]);
        $foreign->loops()->attach($this->otherLoop->id);

        $this->actingAs($this->owner);
        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $foreign->id)
            ->assertSet('errorMessage', __('loops.manifesto_invalid_article'));

        $this->assertNull($this->loop->fresh()->manifesto_blog_post_id);
    }

    public function test_cannot_designate_article_not_linked_to_the_loop(): void
    {
        $unlinked = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->organization->id,
            'title' => 'Unlinked '.uniqid(),
            'content' => 'x',
            'status' => 'draft',
        ]); // same org but NOT attached to the loop

        $this->actingAs($this->owner);
        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $unlinked->id)
            ->assertSet('errorMessage', __('loops.manifesto_invalid_article'));

        $this->assertNull($this->loop->fresh()->manifesto_blog_post_id);
    }

    public function test_owner_can_remove_the_manifesto(): void
    {
        $post = $this->linkedPost();
        $this->loop->update(['manifesto_blog_post_id' => $post->id]);

        $this->actingAs($this->owner);
        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('removeManifesto');

        $this->assertNull($this->loop->fresh()->manifesto_blog_post_id);
        // The BlogPost itself is NOT deleted.
        $this->assertDatabaseHas('blog_posts', ['id' => $post->id]);
    }

    public function test_owner_can_create_a_draft_manifesto_and_is_redirected(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('createManifesto')
            ->assertRedirect();

        $this->loop->refresh();
        $this->assertNotNull($this->loop->manifesto_blog_post_id);

        $post = BlogPost::find($this->loop->manifesto_blog_post_id);
        $this->assertNotNull($post);
        $this->assertSame('draft', $post->status);
        $this->assertSame($this->organization->id, $post->organization_id);
        $this->assertSame($this->owner->id, $post->user_id);
        // Linked to the loop
        $this->assertTrue($post->loops()->whereKey($this->loop->id)->exists());
    }

    public function test_non_member_cannot_manage(): void
    {
        $post = $this->linkedPost();
        $this->actingAs($this->nonMember);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $post->id);

        $this->assertNull($this->loop->fresh()->manifesto_blog_post_id);
    }

    public function test_deactivated_member_cannot_manage(): void
    {
        $this->moderator->update(['banned_at' => now()]);
        $post = $this->linkedPost();
        $this->actingAs($this->moderator);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $post->id);

        $this->assertNull($this->loop->fresh()->manifesto_blog_post_id);
    }

    public function test_soft_deleted_manifesto_falls_back_to_empty_state(): void
    {
        $post = $this->linkedPost();
        $this->loop->update(['manifesto_blog_post_id' => $post->id]);
        $post->delete(); // soft delete

        $this->actingAs($this->member);
        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.manifesto_pitch'));
    }
}
