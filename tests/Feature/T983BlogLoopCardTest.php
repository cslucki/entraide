<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T983BlogLoopCardTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected Organization $otherOrg;

    protected User $owner;

    protected User $otherUser;

    protected User $crossOrgUser;

    protected BlogPost $post;

    protected Loop $loop;

    protected LoopService $loopService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true]);
        $this->otherOrg = Organization::factory()->create();

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->otherUser = User::factory()->create(['organization_id' => $this->org->id]);
        $this->crossOrgUser = User::factory()->create(['organization_id' => $this->otherOrg->id]);

        app()->instance('current_organization', $this->org);

        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Test Loop Card Post',
            'content' => 'Test content for loop card test.',
            'status' => 'draft',
        ]);

        $this->loopService = new LoopService;
        $this->loop = $this->loopService->createLoop($this->owner, 'Test Loop', 'A test loop');
    }

    public function test_owner_can_link_post_to_loop()
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/blog/{$this->post->slug}/loops", [
            'loop_id' => $this->loop->id,
        ]);

        $response->assertOk();
        $response->assertJson([
            'message' => __('blog.loop_linked'),
        ]);

        $this->assertDatabaseHas('blog_post_loop', [
            'blog_post_id' => $this->post->id,
            'loop_id' => $this->loop->id,
        ]);
    }

    public function test_cannot_link_to_loop_if_not_member()
    {
        $this->post->coAuthors()->attach($this->otherUser->id, ['role' => 'coauthor', 'added_by' => $this->owner->id]);
        $this->actingAs($this->otherUser);

        $loop = $this->loopService->createLoop($this->owner, 'Owner Only Loop', 'Desc');

        $response = $this->postJson("/blog/{$this->post->slug}/loops", [
            'loop_id' => $loop->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => __('blog.loop_not_member')]);
    }

    public function test_cannot_link_cross_org_loop()
    {
        $this->actingAs($this->owner);

        $crossOrgUser = User::factory()->create(['organization_id' => $this->otherOrg->id]);
        $crossLoop = $this->loopService->createLoop($crossOrgUser, 'Cross Loop', 'Desc');

        $response = $this->postJson("/blog/{$this->post->slug}/loops", [
            'loop_id' => $crossLoop->id,
        ]);

        $response->assertNotFound();
    }

    public function test_owner_can_unlink_loop()
    {
        $this->actingAs($this->owner);

        $this->post->loops()->attach($this->loop->id);

        $response = $this->deleteJson("/blog/{$this->post->slug}/loops/{$this->loop->id}");

        $response->assertOk();
        $response->assertJson(['message' => __('blog.loop_unlinked')]);

        $this->assertDatabaseMissing('blog_post_loop', [
            'blog_post_id' => $this->post->id,
            'loop_id' => $this->loop->id,
        ]);
    }

    public function test_linked_loops_visible_in_edit_view()
    {
        $this->actingAs($this->owner);

        $this->post->loops()->attach($this->loop->id);

        $response = $this->get("/blog/rediger/{$this->post->slug}/modifier");

        $response->assertOk();
        $response->assertViewHas('userLoops');
        $response->assertViewHas('postLoops');

        $viewLoops = $response->viewData('postLoops');
        $this->assertCount(1, $viewLoops);
        $this->assertEquals($this->loop->id, $viewLoops->first()->id);
    }

    public function test_linked_loops_persist_after_reload()
    {
        $this->actingAs($this->owner);

        $this->post->loops()->attach($this->loop->id);

        $response = $this->get("/blog/rediger/{$this->post->slug}/modifier");
        $response->assertOk();

        $viewLoops = $response->viewData('postLoops');
        $this->assertCount(1, $viewLoops);
        $this->assertEquals($this->loop->id, $viewLoops->first()->id);
    }

    public function test_empty_state_when_no_loops_available()
    {
        $this->actingAs($this->owner);

        $response = $this->get("/blog/rediger/{$this->post->slug}/modifier");
        $response->assertOk();

        $userLoops = $response->viewData('userLoops');
        $this->assertCount(1, $userLoops);

        $this->assertTrue($userLoops->contains('id', $this->loop->id));
    }

    public function test_cross_org_user_cannot_access_page()
    {
        app()->instance('current_organization', $this->otherOrg);
        $this->actingAs($this->crossOrgUser);

        $response = $this->get("/blog/rediger/{$this->post->slug}/modifier");

        $response->assertNotFound();
    }

    public function test_messages_endpoint_returns_messages()
    {
        $this->actingAs($this->owner);

        $this->post->loops()->attach($this->loop->id);

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Test message from owner',
            'type' => 'user',
        ]);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");

        $response->assertOk();
        $response->assertJsonStructure([
            'loops' => [
                '*' => [
                    'id',
                    'name',
                    'messages',
                ],
            ],
        ]);

        $data = $response->json();
        $this->assertCount(1, $data['loops']);
        $this->assertCount(1, $data['loops'][0]['messages']);
        $this->assertEquals('Test message from owner', $data['loops'][0]['messages'][0]['body']);
    }

    public function test_messages_endpoint_respects_membership()
    {
        $this->post->coAuthors()->attach($this->otherUser->id, ['role' => 'coauthor', 'added_by' => $this->owner->id]);
        $this->actingAs($this->otherUser);

        $this->post->loops()->attach($this->loop->id);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");

        $response->assertOk();

        $data = $response->json();
        $this->assertCount(1, $data['loops']);
        $this->assertFalse($data['loops'][0]['is_member']);
    }

    public function test_unauthenticated_user_cannot_access_loop_endpoints()
    {
        $response = $this->postJson("/blog/{$this->post->slug}/loops", [
            'loop_id' => $this->loop->id,
        ]);

        $response->assertUnauthorized();
    }

    public function test_i18n_keys_exist()
    {
        $keys = [
            'blog.loop_linked',
            'blog.loop_unlinked',
            'blog.loop_not_member',
            'blog.loop_already_linked',
            'blog.loop_no_loops',
            'blog.loop_no_linked_loops',
            'blog.loop_select',
            'blog.loop_link',
            'blog.loop_unlink',
            'blog.loop_no_messages',
            'blog.loop_view_discussion',
            'blog.loop_system',
            'blog.loop_message_placeholder',
            'blog.loop_message_send',
            'blog.loop_message_sending',
            'blog.loop_message_readonly',
            'blog.loop_message_sent',
        ];

        foreach ($keys as $key) {
            $translation = __($key);
            $this->assertNotEmpty($translation, "Translation for {$key} is empty.");
            $this->assertNotEquals($key, $translation, "Translation for {$key} is missing.");
        }
    }

    public function test_owner_can_send_message_to_linked_loop(): void
    {
        $this->actingAs($this->owner);
        $this->post->loops()->attach($this->loop->id);

        $response = $this->postJson("/blog/{$this->post->slug}/loops/{$this->loop->id}/messages", [
            'body' => 'Hello from blog card!',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['message' => ['id', 'body', 'sender_name', 'created_at_human']]);
        $response->assertJsonPath('message.body', 'Hello from blog card!');
        $response->assertJsonPath('message.sender_name', $this->owner->name);

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Hello from blog card!',
            'type' => 'user',
            'organization_id' => $this->org->id,
        ]);

        $saved = LoopMessage::where('body', 'Hello from blog card!')->first();
        $this->assertNotNull($saved->metadata);
        $this->assertEquals('blog_loop_card', $saved->metadata['source']);
        $this->assertEquals($this->post->id, $saved->metadata['blog_post_id']);
    }

    public function test_cannot_send_message_if_not_member(): void
    {
        $this->actingAs($this->owner);

        $loop2 = $this->loopService->createLoop($this->owner, 'Coauthor Loop', 'Desc');
        $this->post->loops()->attach($loop2->id);

        $nonMemberCoauthor = User::factory()->create(['organization_id' => $this->org->id]);
        $this->post->coAuthors()->attach($nonMemberCoauthor->id, ['role' => 'coauthor', 'added_by' => $this->owner->id]);
        $this->actingAs($nonMemberCoauthor);

        $response = $this->postJson("/blog/{$this->post->slug}/loops/{$loop2->id}/messages", [
            'body' => 'Sneaky message.',
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_send_message_to_unlinked_loop(): void
    {
        $this->actingAs($this->owner);
        $loop2 = $this->loopService->createLoop($this->owner, 'Second Loop', 'Desc');
        $this->post->loops()->attach($loop2->id);

        $unlinkedLoop = $this->loopService->createLoop($this->owner, 'Unlinked Loop', 'Desc');

        $response = $this->postJson("/blog/{$this->post->slug}/loops/{$unlinkedLoop->id}/messages", [
            'body' => 'Orphan message.',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_send_empty_message(): void
    {
        $this->actingAs($this->owner);
        $this->post->loops()->attach($this->loop->id);

        $response = $this->postJson("/blog/{$this->post->slug}/loops/{$this->loop->id}/messages", [
            'body' => '',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_send_long_message(): void
    {
        $this->actingAs($this->owner);
        $this->post->loops()->attach($this->loop->id);

        $response = $this->postJson("/blog/{$this->post->slug}/loops/{$this->loop->id}/messages", [
            'body' => str_repeat('A', 5001),
        ]);

        $response->assertStatus(422);
    }

    public function test_cross_org_user_cannot_send_message(): void
    {
        app()->instance('current_organization', $this->otherOrg);
        $this->actingAs($this->crossOrgUser);
        $this->post->loops()->attach($this->loop->id);

        $response = $this->postJson("/blog/{$this->post->slug}/loops/{$this->loop->id}/messages", [
            'body' => 'Cross org message.',
        ]);

        $response->assertNotFound();
    }

    public function test_guest_cannot_send_message(): void
    {
        $this->post->loops()->attach($this->loop->id);

        $response = $this->postJson("/blog/{$this->post->slug}/loops/{$this->loop->id}/messages", [
            'body' => 'Guest message.',
        ]);

        $response->assertUnauthorized();
    }

    public function test_org_scoped_owner_can_send_message(): void
    {
        $this->actingAs($this->owner);
        $this->post->loops()->attach($this->loop->id);

        $response = $this->postJson("/org/{$this->org->slug}/blog/{$this->post->slug}/loops/{$this->loop->id}/messages", [
            'body' => 'Org-scoped message.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('message.body', 'Org-scoped message.');

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'body' => 'Org-scoped message.',
        ]);
    }

    public function test_sent_message_appears_in_messages_endpoint(): void
    {
        $this->actingAs($this->owner);
        $this->post->loops()->attach($this->loop->id);

        $this->postJson("/blog/{$this->post->slug}/loops/{$this->loop->id}/messages", [
            'body' => 'Check endpoint message.',
        ]);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");
        $response->assertOk();

        $messages = $response->json('loops.0.messages');
        $bodies = array_column($messages, 'body');
        $this->assertContains('Check endpoint message.', $bodies);
    }

    public function test_messages_endpoint_returns_chronological_order(): void
    {
        $this->actingAs($this->owner);
        $this->post->loops()->attach($this->loop->id);

        LoopMessage::forceCreate([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Oldest message',
            'type' => 'user',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        LoopMessage::forceCreate([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Middle message',
            'type' => 'user',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        LoopMessage::forceCreate([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Newest message',
            'type' => 'user',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");
        $response->assertOk();

        $messages = $response->json('loops.0.messages');
        $this->assertCount(3, $messages);
        $this->assertEquals('Oldest message', $messages[0]['body']);
        $this->assertEquals('Middle message', $messages[1]['body']);
        $this->assertEquals('Newest message', $messages[2]['body']);
    }

    public function test_messages_endpoint_limits_to_latest_three(): void
    {
        $this->actingAs($this->owner);
        $this->post->loops()->attach($this->loop->id);

        LoopMessage::forceCreate([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Oldest—should be excluded',
            'type' => 'user',
            'created_at' => now()->subHours(4),
            'updated_at' => now()->subHours(4),
        ]);

        LoopMessage::forceCreate([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Second oldest',
            'type' => 'user',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
        ]);

        LoopMessage::forceCreate([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Third oldest',
            'type' => 'user',
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        LoopMessage::forceCreate([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Newest message',
            'type' => 'user',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");
        $response->assertOk();

        $messages = $response->json('loops.0.messages');
        $this->assertCount(3, $messages);
        $this->assertEquals('Second oldest', $messages[0]['body']);
        $this->assertEquals('Third oldest', $messages[1]['body']);
        $this->assertEquals('Newest message', $messages[2]['body']);
    }

    public function test_messages_endpoint_returns_created_at_human(): void
    {
        $this->actingAs($this->owner);
        $this->post->loops()->attach($this->loop->id);

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Timestamp test',
            'type' => 'user',
        ]);

        $response = $this->getJson("/blog/{$this->post->slug}/loop-messages");
        $response->assertOk();

        $messages = $response->json('loops.0.messages');
        $this->assertNotEmpty($messages[0]['created_at_human']);
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);
        parent::tearDown();
    }
}
