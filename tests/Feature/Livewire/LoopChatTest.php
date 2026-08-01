<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Ai\AiMarkdownSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class LoopChatTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $member;

    private User $nonMember;

    private User $crossUser;

    private Loop $loop;

    private LoopService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->nonMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->crossUser = User::factory()->create(['organization_id' => $this->otherOrganization->id]);

        $this->service = new LoopService;
        $this->loop = $this->service->createLoop($this->member, 'Test Chat Loop');

        app()->instance('current_organization', $this->organization);
    }

    public function test_component_loads_messages_for_loop(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Hello from the loop!',
            'type' => 'user',
        ]);

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->nonMember->id,
            'body' => 'Second message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Hello from the loop!')
            ->assertSee('Second message');
    }

    public function test_component_initially_loads_only_the_latest_thirty_messages(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            LoopMessage::create([
                'loop_id' => $this->loop->id,
                'sender_id' => $this->member->id,
                'body' => sprintf('Paged message %02d', $i),
                'type' => 'user',
                'organization_id' => $this->loop->organization_id,
                'created_at' => now()->addMinutes($i),
            ]);
        }

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSee('Paged message 01')
            ->assertSee('Paged message 06')
            ->assertSee('Paged message 35')
            ->assertSet('hasOlderMessages', true);
    }

    public function test_member_can_load_older_messages(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            LoopMessage::create([
                'loop_id' => $this->loop->id,
                'sender_id' => $this->member->id,
                'body' => sprintf('Older batch message %02d', $i),
                'type' => 'user',
                'organization_id' => $this->loop->organization_id,
                'created_at' => now()->addMinutes($i),
            ]);
        }

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSee('Older batch message 01')
            ->call('loadOlderMessages')
            ->assertSee('Older batch message 01')
            ->assertSet('hasOlderMessages', false);
    }

    public function test_empty_loop_shows_empty_state(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.no_messages'));
    }

    public function test_member_can_send_message(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Bonjour tout le monde !')
            ->call('sendMessage')
            ->assertSet('body', '');

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Bonjour tout le monde !',
            'type' => 'user',
        ]);
    }

    public function test_non_member_cannot_send_message(): void
    {
        Livewire::actingAs($this->nonMember)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Intrus message')
            ->call('sendMessage');

        $this->assertDatabaseMissing('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->nonMember->id,
            'body' => 'Intrus message',
        ]);
    }

    public function test_cross_organization_user_cannot_send_message(): void
    {
        Livewire::actingAs($this->crossUser)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Cross org message')
            ->call('sendMessage');

        $this->assertDatabaseMissing('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->crossUser->id,
        ]);
    }

    public function test_requires_body_to_send(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', '')
            ->set('photo', null)
            ->call('sendMessage')
            ->assertHasErrors(['body' => 'required_without']);
    }

    public function test_body_max_length_is_5000(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', str_repeat('a', 5001))
            ->call('sendMessage')
            ->assertHasErrors(['body' => 'max']);
    }

    public function test_non_member_does_not_see_composer(): void
    {
        Livewire::actingAs($this->nonMember)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSee(__('messages.write_message'));
    }

    public function test_member_sees_composer(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('messages.write_message'));
    }

    public function test_help_request_messages_are_displayed(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'I need help with design',
            'type' => 'help_request',
            'metadata' => [
                'title' => 'Design Help',
                'need' => 'I need help with design',
                'expected_help_type' => 'graphic design',
            ],
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.help_request_badge'))
            ->assertSee('Design Help');
    }

    public function test_body_not_lost_during_render(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Ongoing message')
            ->assertSet('body', 'Ongoing message');
    }

    public function test_reply_to_message_creates_reply_to_id(): void
    {
        $parent = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $parent->id)
            ->assertSet('replyToMessageId', $parent->id)
            ->assertSet('body', '')
            ->set('body', 'Ma réponse')
            ->call('sendMessage')
            ->assertSet('body', '')
            ->assertSet('replyToMessageId', null);

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Ma réponse',
            'reply_to_id' => $parent->id,
        ]);
    }

    public function test_author_can_edit_own_user_message(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message avant édition',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('editMessage', $message->id)
            ->assertSet('editingMessageId', $message->id)
            ->set('editingBody', 'Message après édition')
            ->call('saveEdit')
            ->assertSet('editingMessageId', null);

        $fresh = $message->fresh();

        $this->assertSame('Message après édition', $fresh->body);
        $this->assertNotNull($fresh->edited_at);
        $this->assertSame($this->member->id, $fresh->sender_id);
        $this->assertSame($this->loop->id, $fresh->loop_id);
        $this->assertSame($this->loop->organization_id, $fresh->organization_id);
        $this->assertSame('user', $fresh->type);
    }

    public function test_non_author_cannot_edit_message(): void
    {
        $otherMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->service->addMember($this->loop, $otherMember);

        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message protégé',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        Livewire::actingAs($otherMember)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Modification interdite')
            ->call('saveEdit');

        $this->assertSame('Message protégé', $message->fresh()->body);
        $this->assertNull($message->fresh()->edited_at);
    }

    public function test_ai_message_cannot_be_edited(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Réponse IA',
            'type' => 'ai',
            'organization_id' => $this->loop->organization_id,
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('editMessage', $message->id)
            ->assertSet('editingMessageId', null);
    }

    public function test_cancel_reply_clears_state(): void
    {
        $parent = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $parent->id)
            ->assertSet('replyToMessageId', $parent->id)
            ->call('cancelReply')
            ->assertSet('replyToMessageId', null)
            ->assertSet('replyingTo', null);
    }

    public function test_cannot_reply_to_message_from_another_loop(): void
    {
        $otherLoop = $this->service->createLoop($this->member, 'Other Loop');
        $otherMessage = LoopMessage::create([
            'loop_id' => $otherLoop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message dans autre boucle',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $otherMessage->id)
            ->assertSet('replyToMessageId', null);
    }

    public function test_normal_message_without_reply_still_works(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Message normal')
            ->call('sendMessage')
            ->assertSet('body', '');

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message normal',
            'reply_to_id' => null,
        ]);
    }

    public function test_member_can_send_message_with_image(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('photo.jpg', 200, 200);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Message avec image')
            ->set('photo', $image)
            ->call('sendMessage')
            ->assertSet('body', '')
            ->assertSet('photo', null);

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message avec image',
            'type' => 'user',
        ]);

        $message = LoopMessage::where('body', 'Message avec image')->first();
        $this->assertNotNull($message->image_path);
        $this->assertStringStartsWith('message-images/', $message->image_path);
        $this->assertStringEndsWith('.webp', $message->image_path);
        $this->assertStringContainsString($this->organization->id, $message->image_path);
        Storage::disk('public')->assertExists($message->image_path);
    }

    public function test_image_path_is_relative_not_url(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('test.jpg');

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Image path test')
            ->set('photo', $image)
            ->call('sendMessage');

        $message = LoopMessage::where('body', 'Image path test')->first();
        $this->assertNotNull($message->image_path);
        $this->assertDoesNotMatchRegularExpression('/^https?:\/\//', $message->image_path);
        $this->assertStringStartsWith('message-images/', $message->image_path);
    }

    public function test_message_without_image_has_null_image_path(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Message sans image')
            ->call('sendMessage');

        $this->assertDatabaseHas('loop_messages', [
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message sans image',
            'image_path' => null,
        ]);
    }

    public function test_reply_with_image_works(): void
    {
        Storage::fake('public');

        $parent = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        $image = UploadedFile::fake()->image('reply.jpg');

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $parent->id)
            ->set('body', 'Réponse avec image')
            ->set('photo', $image)
            ->call('sendMessage')
            ->assertSet('body', '')
            ->assertSet('replyToMessageId', null);

        $this->assertDatabaseHas('loop_messages', [
            'body' => 'Réponse avec image',
            'reply_to_id' => $parent->id,
        ]);

        $message = LoopMessage::where('body', 'Réponse avec image')->first();
        $this->assertNotNull($message->image_path);
    }

    public function test_image_is_scaled_down(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('large.jpg', 3000, 2000);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Large image')
            ->set('photo', $image)
            ->call('sendMessage');

        $message = LoopMessage::where('body', 'Large image')->first();
        $this->assertNotNull($message->image_path);

        $stored = Storage::disk('public')->get($message->image_path);
        $decoded = imagecreatefromstring($stored);
        $this->assertNotFalse($decoded);

        $this->assertLessThanOrEqual(1200, imagesx($decoded));
        $this->assertLessThanOrEqual(800, imagesy($decoded));
        imagedestroy($decoded);
    }

    public function test_public_url_stores_preview_in_metadata(): void
    {
        $html = '<html><head><meta property="og:title" content="OG Title"><meta property="og:description" content="OG Description"></head></html>';

        Http::fake([
            '*valid-example.com*' => Http::response($html, 200),
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Check this https://valid-example.com/article')
            ->call('sendMessage')
            ->assertSet('body', '');

        $message = LoopMessage::where('body', 'Check this https://valid-example.com/article')->first();
        $this->assertNotNull($message);
        $this->assertNotNull($message->metadata);
        $this->assertArrayHasKey('url_preview', $message->metadata);
        $this->assertSame('OG Title', $message->metadata['url_preview']['title']);
        $this->assertSame('OG Description', $message->metadata['url_preview']['description']);
        $this->assertSame('valid-example.com', $message->metadata['url_preview']['domain']);
    }

    public function test_message_without_url_has_no_url_preview(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Just a normal message')
            ->call('sendMessage');

        $message = LoopMessage::where('body', 'Just a normal message')->first();
        $this->assertNotNull($message);
        $this->assertNull($message->metadata);
    }

    public function test_blocked_url_sends_message_without_preview(): void
    {
        Http::fake();

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Internal http://192.168.1.1/admin')
            ->call('sendMessage')
            ->assertSet('body', '');

        $message = LoopMessage::where('body', 'Internal http://192.168.1.1/admin')->first();
        $this->assertNotNull($message);
        $this->assertNull($message->metadata);
        Http::assertNothingSent();
    }

    public function test_reply_and_url_coexist(): void
    {
        $html = '<html><head><meta property="og:title" content="Reply URL"></head></html>';

        Http::fake([
            '*reply-example.com*' => Http::response($html, 200),
        ]);

        $parent = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $parent->id)
            ->set('body', 'Réponse avec lien https://reply-example.com/page')
            ->call('sendMessage');

        $message = LoopMessage::where('body', 'Réponse avec lien https://reply-example.com/page')->first();
        $this->assertNotNull($message);
        $this->assertSame($parent->id, $message->reply_to_id);
        $this->assertNotNull($message->metadata);
        $this->assertArrayHasKey('url_preview', $message->metadata);
        $this->assertSame('Reply URL', $message->metadata['url_preview']['title']);
    }

    public function test_image_and_url_coexist(): void
    {
        Storage::fake('public');

        $html = '<html><head><meta property="og:title" content="Image+URL"></head></html>';

        Http::fake([
            '*img-example.com*' => Http::response($html, 200),
        ]);

        $image = UploadedFile::fake()->image('photo.jpg', 200, 200);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Message avec image et lien https://img-example.com')
            ->set('photo', $image)
            ->call('sendMessage');

        $message = LoopMessage::where('body', 'Message avec image et lien https://img-example.com')->first();
        $this->assertNotNull($message);
        $this->assertNotNull($message->image_path);
        $this->assertNotNull($message->metadata);
        $this->assertArrayHasKey('url_preview', $message->metadata);
        $this->assertSame('Image+URL', $message->metadata['url_preview']['title']);
    }

    /* ---------- PINNED MESSAGE TESTS ---------- */

    public function test_member_can_pin_message(): void
    {
        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message à épingler',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('pinMessage', $msg->id);

        $this->assertDatabaseHas('loop_messages', [
            'id' => $msg->id,
            'pinned_by_id' => $this->member->id,
        ]);

        $fresh = $msg->fresh();
        $this->assertNotNull($fresh->pinned_at);
        $this->assertSame($this->member->id, $fresh->pinned_by_id);
    }

    public function test_pinned_message_is_shown_in_banner(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message important',
            'type' => 'user',
            'pinned_at' => now(),
            'pinned_by_id' => $this->member->id,
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('messages.pinned_message'))
            ->assertSee('Message important');
    }

    public function test_pinning_second_message_replaces_first(): void
    {
        $first = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Premier message épinglé',
            'type' => 'user',
        ]);

        $second = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Second message épinglé',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('pinMessage', $first->id);

        $this->assertDatabaseHas('loop_messages', ['id' => $first->id, 'pinned_by_id' => $this->member->id]);
        $this->assertDatabaseMissing('loop_messages', ['id' => $second->id, 'pinned_by_id' => $this->member->id]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('pinMessage', $second->id);

        $this->assertDatabaseHas('loop_messages', ['id' => $second->id, 'pinned_by_id' => $this->member->id]);

        $firstFresh = $first->fresh();
        $this->assertNull($firstFresh->pinned_at);
        $this->assertNull($firstFresh->pinned_by_id);
    }

    public function test_member_can_unpin_message(): void
    {
        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message à désépingler',
            'type' => 'user',
            'pinned_at' => now(),
            'pinned_by_id' => $this->member->id,
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('unpinMessage');

        $fresh = $msg->fresh();
        $this->assertNull($fresh->pinned_at);
        $this->assertNull($fresh->pinned_by_id);
    }

    public function test_owner_can_logically_delete_message_and_unpin_it(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message à supprimer logiquement',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
            'pinned_at' => now(),
            'pinned_by_id' => $this->member->id,
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('deleteMessage', $message->id)
            ->assertSee(__('messages.deleted_message_placeholder'))
            ->assertDontSee('Message à supprimer logiquement');

        $fresh = $message->fresh();

        $this->assertNotNull($fresh->deleted_at);
        $this->assertSame($this->member->id, $fresh->deleted_by);
        $this->assertNull($fresh->pinned_at);
        $this->assertNull($fresh->pinned_by_id);
    }

    public function test_regular_member_cannot_delete_message(): void
    {
        $regularMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->service->addMember($this->loop, $regularMember, 'member');

        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message réservé à la modération',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        Livewire::actingAs($regularMember)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('deleteMessage', $message->id);

        $this->assertNull($message->fresh()->deleted_at);
    }

    public function test_moderator_can_delete_message(): void
    {
        $moderator = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->service->addMember($this->loop, $moderator, 'moderator');

        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message modéré',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        Livewire::actingAs($moderator)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('deleteMessage', $message->id);

        $this->assertNotNull($message->fresh()->deleted_at);
        $this->assertSame($moderator->id, $message->fresh()->deleted_by);
    }

    public function test_super_admin_can_delete_message_without_being_loop_member(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message supprimé par super-admin',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
        ]);

        Livewire::actingAs($admin)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('deleteMessage', $message->id);

        $this->assertNotNull($message->fresh()->deleted_at);
        $this->assertSame($admin->id, $message->fresh()->deleted_by);
    }

    public function test_deleted_message_hides_body_image_and_preview(): void
    {
        $message = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Secret supprimé à ne pas rendre',
            'type' => 'user',
            'organization_id' => $this->loop->organization_id,
            'image_path' => 'message-images/'.$this->organization->id.'/secret.webp',
            'metadata' => ['url_preview' => ['title' => 'Preview supprimée', 'domain' => 'example.test']],
            'deleted_at' => now(),
            'deleted_by' => $this->member->id,
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('messages.deleted_message_placeholder'))
            ->assertDontSee($message->body)
            ->assertDontSee('secret.webp')
            ->assertDontSee('Preview supprimée');
    }

    public function test_pinned_message_can_load_its_target_when_outside_initial_batch(): void
    {
        $oldest = null;

        for ($i = 1; $i <= 40; $i++) {
            $message = LoopMessage::create([
                'loop_id' => $this->loop->id,
                'sender_id' => $this->member->id,
                'body' => sprintf('Pinned target message %02d', $i),
                'type' => 'user',
                'organization_id' => $this->loop->organization_id,
                'created_at' => now()->addMinutes($i),
            ]);

            $oldest ??= $message;
        }

        $oldest->pin($this->member);

        $component = Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop]);

        $this->assertNotContains($oldest->id, $component->instance()->loadedMessageIds);

        $component->call('showMessageInThread', $oldest->id);

        $this->assertContains($oldest->id, $component->instance()->loadedMessageIds);
    }

    public function test_non_member_cannot_pin_message(): void
    {
        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->nonMember)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('pinMessage', $msg->id);

        $fresh = $msg->fresh();
        $this->assertNull($fresh->pinned_at);
    }

    public function test_cannot_pin_message_from_another_loop(): void
    {
        $otherLoop = $this->service->createLoop($this->member, 'Other Loop');

        $msg = LoopMessage::create([
            'loop_id' => $otherLoop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message autre boucle',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('pinMessage', $msg->id);

        $fresh = $msg->fresh();
        $this->assertNull($fresh->pinned_at);
    }

    public function test_pin_does_not_break_reply(): void
    {
        $parent = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message épinglé',
            'type' => 'user',
            'pinned_at' => now(),
            'pinned_by_id' => $this->member->id,
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('replyTo', $parent->id)
            ->assertSet('replyToMessageId', $parent->id)
            ->set('body', 'Réponse')
            ->call('sendMessage');

        $this->assertDatabaseHas('loop_messages', [
            'body' => 'Réponse',
            'reply_to_id' => $parent->id,
        ]);
    }

    /* ---------- REACTION TESTS ---------- */

    public function test_member_can_add_thumbs_up_reaction(): void
    {
        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message avec réaction',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseHas('reactions', [
            'user_id' => $this->member->id,
            'reactionable_id' => $msg->id,
            'reactionable_type' => LoopMessage::class,
            'reaction_type' => 'thumbs_up',
        ]);
    }

    public function test_same_reaction_removes_it(): void
    {
        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 1);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_different_reaction_replaces(): void
    {
        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseHas('reactions', [
            'reactionable_id' => $msg->id,
            'reaction_type' => 'thumbs_up',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thanks');

        $this->assertDatabaseHas('reactions', [
            'reactionable_id' => $msg->id,
            'reaction_type' => 'thanks',
        ]);

        $this->assertDatabaseMissing('reactions', [
            'reactionable_id' => $msg->id,
            'reaction_type' => 'thumbs_up',
        ]);
    }

    public function test_reaction_counts_are_correct(): void
    {
        $otherMember = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->service->addMember($this->loop, $otherMember);

        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        Livewire::actingAs($otherMember)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertEquals(2, Reaction::where('reactionable_id', $msg->id)
            ->where('reaction_type', 'thumbs_up')
            ->count());
    }

    public function test_non_member_cannot_react(): void
    {
        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->nonMember)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_cannot_react_to_message_from_another_loop(): void
    {
        $otherLoop = $this->service->createLoop($this->member, 'Other Loop');

        $msg = LoopMessage::create([
            'loop_id' => $otherLoop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message autre boucle',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_cross_organization_user_cannot_react(): void
    {
        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->crossUser)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_all_eight_reaction_types_work(): void
    {
        $msg = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        foreach (Reaction::REACTION_TYPES as $type) {
            Livewire::actingAs($this->member)
                ->test(LoopChat::class, ['loop' => $this->loop])
                ->call('toggleReaction', $msg->id, $type);

            $this->assertDatabaseHas('reactions', [
                'reactionable_id' => $msg->id,
                'reaction_type' => $type,
            ]);
        }
    }

    public function test_reaction_coexists_with_reply_image_pin(): void
    {
        Storage::fake('public');

        $parent = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        $pinnable = LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Épinglé',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('pinMessage', $pinnable->id)
            ->call('replyTo', $parent->id)
            ->set('body', 'Réaction + reply')
            ->call('sendMessage');

        $reply = LoopMessage::where('body', 'Réaction + reply')->first();
        $this->assertNotNull($reply);
        $this->assertSame($parent->id, $reply->reply_to_id);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('toggleReaction', $pinnable->id, 'thumbs_up');

        $this->assertDatabaseHas('reactions', [
            'reactionable_id' => $pinnable->id,
            'reaction_type' => 'thumbs_up',
        ]);
    }

    public function test_pin_does_not_break_image(): void
    {
        Storage::fake('public');

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->member->id,
            'body' => 'Message épinglé',
            'type' => 'user',
            'pinned_at' => now(),
            'pinned_by_id' => $this->member->id,
        ]);

        $image = UploadedFile::fake()->image('photo.jpg', 200, 200);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Message avec image')
            ->set('photo', $image)
            ->call('sendMessage')
            ->assertSet('body', '');

        $message = LoopMessage::where('body', 'Message avec image')->first();
        $this->assertNotNull($message->image_path);
    }

    public function test_member_sees_ask_ai_button(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.ask_ai'))
            ->assertSee('/loops/'.$this->loop->id.'/ask-ai');
    }

    public function test_non_member_does_not_see_ask_ai_button(): void
    {
        Livewire::actingAs($this->nonMember)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSee(__('loops.ask_ai'));
    }

    public function test_ask_ai_button_has_loading_state_bindings(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('x-bind:disabled="submitting"')
            ->assertSeeHtml('x-on:submit="submitting = true"')
            ->assertSeeHtml('x-show="submitting"')
            ->assertSeeHtml('x-show="!submitting"');
    }

    public function test_member_sees_ask_question_button(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.ask_question'));
    }

    public function test_ask_question_modal_is_rendered(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('name="action" value="ask"')
            ->assertSeeHtml('id="ai-question"')
            ->assertSeeHtml(__('loops.ask_question_placeholder'))
            ->assertSeeHtml(__('loops.ask_question_submit'))
            ->assertSeeHtml(__('loops.cancel'));
    }

    public function test_non_member_does_not_see_ask_question_button(): void
    {
        Livewire::actingAs($this->nonMember)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSee(__('loops.ask_question'));
    }

    public function test_ai_message_renders_with_facilitator_and_requested_by(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Réponse de l\'IA.',
            'type' => 'ai',
            'metadata' => [
                'requested_by' => $this->member->id,
                'action' => 'answer',
            ],
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.ai_facilitator'))
            ->assertSee(__('loops.ai_requested_by', ['name' => $this->member->publicDisplayName()]))
            ->assertSee('Réponse de l\'IA.', false);
    }

    public function test_ai_message_without_requested_by_renders_without_subtitle(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Réponse IA sans demandeur',
            'type' => 'ai',
            'metadata' => [
                'action' => 'answer',
            ],
        ]);

        $subtitlePrefix = trim(explode(':', __('loops.ai_requested_by', ['name' => '']))[0]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.ai_facilitator'))
            ->assertSee('Réponse IA sans demandeur')
            ->assertDontSee($subtitlePrefix);
    }

    public function test_ai_message_with_unknown_requester_does_not_crash(): void
    {
        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => 'Réponse IA orpheline',
            'type' => 'ai',
            'metadata' => [
                'requested_by' => 99999999,
                'action' => 'answer',
            ],
        ]);

        $subtitlePrefix = trim(explode(':', __('loops.ai_requested_by', ['name' => '']))[0]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee(__('loops.ai_facilitator'))
            ->assertSee('Réponse IA orpheline')
            ->assertDontSee($subtitlePrefix);
    }

    public function test_ai_error_flash_is_rendered_locally(): void
    {
        $flashValue = __('loops.ai_error');

        $response = $this->actingAs($this->member)
            ->withSession(['error' => $flashValue])
            ->get(route('loops.show', $this->loop));

        $response->assertOk();
        $response->assertSee($flashValue);
    }

    public function test_org_scoped_show_page_uses_org_scoped_ask_ai_url(): void
    {
        $expectedUrl = route('organization.loops.ai', [
            'organization' => $this->organization,
            'loop' => $this->loop,
        ]);

        $response = $this->actingAs($this->member)
            ->get(route('organization.loops.show', [
                'organization' => $this->organization,
                'loop' => $this->loop,
            ]));

        $response->assertOk();
        $response->assertSee($expectedUrl);
    }

    public function test_ai_message_renders_markdown(): void
    {
        $body = AiMarkdownSanitizer::sanitize(
            "## Résumé\n\n"
            .'**gras** et *italique*'."\n\n"
            ."- Premier point\n- Deuxième point\n\n"
            ."> Citation\n\n"
            ."[lien](https://example.com)\n\n"
            ."```php\n\$ok = true;\n```"
        );

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => $body,
            'type' => 'ai',
            'metadata' => ['requested_by' => $this->member->id, 'action' => 'answer'],
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSeeHtml('<h2>Résumé</h2>')
            ->assertSeeHtml('<strong>gras</strong>')
            ->assertSeeHtml('<em>italique</em>')
            ->assertSeeHtml('<li>Premier point</li>')
            ->assertSeeHtml('<blockquote>')
            ->assertSeeHtml('<a href="https://example.com">')
            ->assertSeeHtml('<pre><code');
    }

    public function test_ai_message_never_renders_h1_or_unsafe_content(): void
    {
        $body = AiMarkdownSanitizer::sanitize(
            "# Titre interdit\n\n"
            .'[piège](javascript:alert(1))'."\n\n"
            .'<script>alert(2)</script>'
        );

        LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => null,
            'body' => $body,
            'type' => 'ai',
            'metadata' => ['requested_by' => $this->member->id, 'action' => 'answer'],
        ]);

        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertDontSeeHtml('<h1')
            ->assertDontSee('javascript:')
            ->assertDontSee('<script>');
    }
}
