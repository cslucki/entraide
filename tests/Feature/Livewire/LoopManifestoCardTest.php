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

        // A Projets Loop: the Manifesto card belongs to that type since
        // CP5ter-E2, and a Dialogue Loop legitimately has none.
        $this->loop = $this->service->createLoop($this->owner, 'Manifesto Loop', type: 'project');
        $this->service->addMember($this->loop, $this->member, 'member');
        $this->service->addMember($this->loop, $this->moderator, 'moderator');

        $this->otherLoop = $this->service->createLoop($this->crossUser, 'Other Loop', type: 'project');
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

    public function test_a_legacy_moderator_designates_as_a_facilitator(): void
    {
        $post = $this->linkedPost('Manifeste');
        $this->actingAs($this->moderator);

        Livewire::test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('designate', $post->id);

        // Third state of this assertion, and the reasoning is worth recording.
        // Originally a moderator could designate. CP5bis narrowed editing to
        // owners because "moderation" was too wide a justification for touching
        // the founding text. CP5ter introduces `facilitator` as the canonical
        // role for exactly that responsibility, and `moderator` is now a legacy
        // alias of it — so this capability returns, but named correctly and
        // resolved centrally. Publishing remains a separate permission the
        // facilitator does not hold by default.
        $this->assertSame($post->id, $this->loop->fresh()->manifesto_blog_post_id);
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
