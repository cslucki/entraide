<?php

namespace Tests\Feature\Livewire;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MessageThreadTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $seller;

    private Organization $organization;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->buyer = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 200]);
        $this->seller = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);

        app()->instance('current_organization', $this->organization);
    }

    public function test_component_renders_for_buyer(): void
    {
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->assertStatus(200);
    }

    public function test_buyer_can_send_message(): void
    {
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Bonjour, je suis disponible !')
            ->call('sendMessage')
            ->assertSet('newMessage', '');

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Bonjour, je suis disponible !',
            'type' => 'user',
        ]);
    }

    public function test_seller_can_send_message(): void
    {
        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Parfait, à demain.')
            ->call('sendMessage');

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Parfait, à demain.',
        ]);
    }

    public function test_outsider_cannot_send_message(): void
    {
        $outsider = User::factory()->create();

        Livewire::actingAs($outsider)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Message intrus')
            ->call('sendMessage');

        $this->assertDatabaseMissing('messages', [
            'transaction_id' => $this->transaction->id,
            'sender_id' => $outsider->id,
        ]);
    }

    public function test_cannot_send_empty_message(): void
    {
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', '')
            ->call('sendMessage')
            ->assertHasErrors(['newMessage']);
    }

    public function test_cannot_send_message_on_completed_transaction(): void
    {
        $this->transaction->update(['status' => 'completed']);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Trop tard')
            ->call('sendMessage');

        $this->assertDatabaseMissing('messages', [
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Trop tard',
            'type' => 'user',
        ]);
    }

    public function test_mount_marks_messages_as_read(): void
    {
        $unread = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message non lu',
            'type' => 'user',
            'read_at' => null,
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction]);

        $this->assertNotNull($unread->fresh()->read_at);
    }

    public function test_messages_are_displayed(): void
    {
        Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message affiché',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->assertSee('Message affiché');
    }

    public function test_reply_to_message_creates_reply_to_id(): void
    {
        $parent = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('replyTo', $parent->id)
            ->assertSet('replyToMessageId', $parent->id)
            ->set('newMessage', 'Ma réponse')
            ->call('sendMessage')
            ->assertSet('newMessage', '')
            ->assertSet('replyToMessageId', null);

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Ma réponse',
            'reply_to_id' => $parent->id,
        ]);
    }

    public function test_cancel_reply_clears_state(): void
    {
        $parent = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('replyTo', $parent->id)
            ->assertSet('replyToMessageId', $parent->id)
            ->call('cancelReply')
            ->assertSet('replyToMessageId', null)
            ->assertSet('replyingTo', null);
    }

    public function test_cannot_reply_to_message_from_another_transaction(): void
    {
        $otherTransaction = Transaction::factory()->create([
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
        ]);

        $otherMessage = Message::create([
            'transaction_id' => $otherTransaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message dans autre transaction',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('replyTo', $otherMessage->id)
            ->assertSet('replyToMessageId', null);
    }

    public function test_cannot_send_reply_on_completed_transaction(): void
    {
        $parent = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        $this->transaction->update(['status' => 'completed']);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('replyTo', $parent->id)
            ->assertSet('replyToMessageId', $parent->id)
            ->set('newMessage', 'Réponse trop tard')
            ->call('sendMessage');

        $this->assertDatabaseMissing('messages', [
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Réponse trop tard',
        ]);
    }

    public function test_normal_message_without_reply_still_works(): void
    {
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Message normal')
            ->call('sendMessage')
            ->assertSet('newMessage', '');

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message normal',
            'reply_to_id' => null,
        ]);
    }

    public function test_buyer_can_send_message_with_image(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('photo.jpg', 200, 200);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Message avec image')
            ->set('photo', $image)
            ->call('sendMessage')
            ->assertSet('newMessage', '')
            ->assertSet('photo', null);

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message avec image',
            'type' => 'user',
        ]);

        $message = Message::where('body', 'Message avec image')->first();
        $this->assertNotNull($message->image_path);
        $this->assertStringStartsWith('message-images/', $message->image_path);
        $this->assertStringEndsWith('.webp', $message->image_path);
        $this->assertStringContainsString($this->transaction->organization_id, $message->image_path);
        Storage::disk('public')->assertExists($message->image_path);
    }

    public function test_image_path_is_relative_not_url_in_messages(): void
    {
        Storage::fake('public');

        $image = UploadedFile::fake()->image('test.jpg');

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Image path test')
            ->set('photo', $image)
            ->call('sendMessage');

        $message = Message::where('body', 'Image path test')->first();
        $this->assertNotNull($message->image_path);
        $this->assertDoesNotMatchRegularExpression('/^https?:\/\//', $message->image_path);
        $this->assertStringStartsWith('message-images/', $message->image_path);
    }

    public function test_message_without_image_has_null_image_path(): void
    {
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Message sans image')
            ->call('sendMessage');

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message sans image',
            'image_path' => null,
        ]);
    }

    public function test_reply_with_image_in_messages_works(): void
    {
        Storage::fake('public');

        $parent = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        $image = UploadedFile::fake()->image('reply.jpg');

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('replyTo', $parent->id)
            ->set('newMessage', 'Réponse avec image')
            ->set('photo', $image)
            ->call('sendMessage')
            ->assertSet('newMessage', '')
            ->assertSet('replyToMessageId', null);

        $this->assertDatabaseHas('messages', [
            'body' => 'Réponse avec image',
            'reply_to_id' => $parent->id,
        ]);

        $message = Message::where('body', 'Réponse avec image')->first();
        $this->assertNotNull($message->image_path);
    }

    public function test_public_url_stores_preview_in_metadata(): void
    {
        $html = '<html><head><meta property="og:title" content="OG Title"><meta property="og:description" content="OG Description"></head></html>';

        Http::fake([
            '*valid-example.com*' => Http::response($html, 200),
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Check this https://valid-example.com/article')
            ->call('sendMessage')
            ->assertSet('newMessage', '');

        $message = Message::where('body', 'Check this https://valid-example.com/article')->first();
        $this->assertNotNull($message);
        $this->assertNotNull($message->metadata);
        $this->assertArrayHasKey('url_preview', $message->metadata);
        $this->assertSame('OG Title', $message->metadata['url_preview']['title']);
        $this->assertSame('OG Description', $message->metadata['url_preview']['description']);
        $this->assertSame('valid-example.com', $message->metadata['url_preview']['domain']);
    }

    public function test_message_without_url_has_no_url_preview_in_messages(): void
    {
        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Just a normal message')
            ->call('sendMessage');

        $message = Message::where('body', 'Just a normal message')->first();
        $this->assertNotNull($message);
        $this->assertNull($message->metadata);
    }

    public function test_blocked_url_sends_message_without_preview_in_messages(): void
    {
        Http::fake();

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Internal http://192.168.1.1/admin')
            ->call('sendMessage')
            ->assertSet('newMessage', '');

        $message = Message::where('body', 'Internal http://192.168.1.1/admin')->first();
        $this->assertNotNull($message);
        $this->assertNull($message->metadata);
        Http::assertNothingSent();
    }

    public function test_reply_and_url_coexist_in_messages(): void
    {
        $html = '<html><head><meta property="og:title" content="Reply URL"></head></html>';

        Http::fake([
            '*reply-example.com*' => Http::response($html, 200),
        ]);

        $parent = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('replyTo', $parent->id)
            ->set('newMessage', 'Réponse avec lien https://reply-example.com/page')
            ->call('sendMessage');

        $message = Message::where('body', 'Réponse avec lien https://reply-example.com/page')->first();
        $this->assertNotNull($message);
        $this->assertSame($parent->id, $message->reply_to_id);
        $this->assertNotNull($message->metadata);
        $this->assertArrayHasKey('url_preview', $message->metadata);
        $this->assertSame('Reply URL', $message->metadata['url_preview']['title']);
    }

    public function test_image_and_url_coexist_in_messages(): void
    {
        Storage::fake('public');

        $html = '<html><head><meta property="og:title" content="Image+URL"></head></html>';

        Http::fake([
            '*img-example.com*' => Http::response($html, 200),
        ]);

        $image = UploadedFile::fake()->image('photo.jpg', 200, 200);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Message avec image et lien https://img-example.com')
            ->set('photo', $image)
            ->call('sendMessage');

        $message = Message::where('body', 'Message avec image et lien https://img-example.com')->first();
        $this->assertNotNull($message);
        $this->assertNotNull($message->image_path);
        $this->assertNotNull($message->metadata);
        $this->assertArrayHasKey('url_preview', $message->metadata);
        $this->assertSame('Image+URL', $message->metadata['url_preview']['title']);
    }

    /* ---------- PINNED MESSAGE TESTS ---------- */

    public function test_buyer_can_pin_message(): void
    {
        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message à épingler',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('pinMessage', $msg->id);

        $fresh = $msg->fresh();
        $this->assertNotNull($fresh->pinned_at);
        $this->assertSame($this->buyer->id, $fresh->pinned_by_id);
    }

    public function test_seller_can_pin_message(): void
    {
        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message à épingler',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('pinMessage', $msg->id);

        $fresh = $msg->fresh();
        $this->assertNotNull($fresh->pinned_at);
        $this->assertSame($this->seller->id, $fresh->pinned_by_id);
    }

    public function test_pinned_message_is_shown_in_banner_in_messages(): void
    {
        Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message important',
            'type' => 'user',
            'pinned_at' => now(),
            'pinned_by_id' => $this->buyer->id,
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->assertSee('Message épinglé')
            ->assertSee('Message important');
    }

    public function test_outsider_cannot_pin_message(): void
    {
        $outsider = User::factory()->create();

        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($outsider)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('pinMessage', $msg->id);

        $fresh = $msg->fresh();
        $this->assertNull($fresh->pinned_at);
    }

    public function test_cannot_pin_message_from_another_transaction(): void
    {
        $otherTransaction = Transaction::factory()->create([
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'organization_id' => $this->organization->id,
        ]);

        $msg = Message::create([
            'transaction_id' => $otherTransaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message autre transaction',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('pinMessage', $msg->id);

        $fresh = $msg->fresh();
        $this->assertNull($fresh->pinned_at);
    }

    /* ---------- REACTION TESTS ---------- */

    public function test_buyer_can_add_reaction(): void
    {
        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseHas('reactions', [
            'user_id' => $this->buyer->id,
            'reactionable_id' => $msg->id,
            'reactionable_type' => Message::class,
            'reaction_type' => 'thumbs_up',
        ]);
    }

    public function test_seller_can_add_reaction(): void
    {
        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->seller)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $msg->id, 'thanks');

        $this->assertDatabaseHas('reactions', [
            'user_id' => $this->seller->id,
            'reactionable_id' => $msg->id,
            'reactionable_type' => Message::class,
            'reaction_type' => 'thanks',
        ]);
    }

    public function test_same_reaction_removes_it_in_messages(): void
    {
        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 1);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_different_reaction_replaces_in_messages(): void
    {
        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseHas('reactions', [
            'reactionable_id' => $msg->id,
            'reaction_type' => 'thumbs_up',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $msg->id, 'seen');

        $this->assertDatabaseMissing('reactions', [
            'reactionable_id' => $msg->id,
            'reaction_type' => 'thumbs_up',
        ]);

        $this->assertDatabaseHas('reactions', [
            'reactionable_id' => $msg->id,
            'reaction_type' => 'seen',
        ]);
    }

    public function test_outsider_cannot_react_in_messages(): void
    {
        $outsider = User::factory()->create();

        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($outsider)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_cannot_react_to_message_from_another_transaction(): void
    {
        $otherTransaction = Transaction::factory()->create([
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
        ]);

        $msg = Message::create([
            'transaction_id' => $otherTransaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message autre transaction',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_cannot_react_cross_tenant_in_messages(): void
    {
        $otherOrg = Organization::factory()->create();
        $crossUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        Livewire::actingAs($crossUser)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $msg->id, 'thumbs_up');

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_all_eight_reaction_types_work_in_messages(): void
    {
        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message',
            'type' => 'user',
        ]);

        foreach (Reaction::REACTION_TYPES as $type) {
            Livewire::actingAs($this->buyer)
                ->test(MessageThread::class, ['transaction' => $this->transaction])
                ->call('toggleReaction', $msg->id, $type);

            $this->assertDatabaseHas('reactions', [
                'reactionable_id' => $msg->id,
                'reaction_type' => $type,
            ]);
        }
    }

    public function test_reaction_coexists_with_reply_image_pin_in_messages(): void
    {
        Storage::fake('public');

        $parent = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message parent',
            'type' => 'user',
        ]);

        $pinnable = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->seller->id,
            'body' => 'Épinglé',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('pinMessage', $pinnable->id)
            ->call('replyTo', $parent->id)
            ->set('newMessage', 'Réaction + reply')
            ->call('sendMessage');

        $reply = Message::where('body', 'Réaction + reply')->first();
        $this->assertNotNull($reply);
        $this->assertSame($parent->id, $reply->reply_to_id);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('toggleReaction', $pinnable->id, 'thumbs_up');

        $this->assertDatabaseHas('reactions', [
            'reactionable_id' => $pinnable->id,
            'reaction_type' => 'thumbs_up',
        ]);
    }

    public function test_pin_replaces_previous_pin_in_messages(): void
    {
        $first = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Premier pin',
            'type' => 'user',
        ]);

        $second = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Second pin',
            'type' => 'user',
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('pinMessage', $first->id)
            ->call('pinMessage', $second->id);

        $firstFresh = $first->fresh();
        $this->assertNull($firstFresh->pinned_at);
        $this->assertNull($firstFresh->pinned_by_id);

        $secondFresh = $second->fresh();
        $this->assertNotNull($secondFresh->pinned_at);
        $this->assertSame($this->buyer->id, $secondFresh->pinned_by_id);
    }

    public function test_buyer_can_unpin_message(): void
    {
        $msg = Message::create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message à unpin',
            'type' => 'user',
            'pinned_at' => now(),
            'pinned_by_id' => $this->buyer->id,
        ]);

        Livewire::actingAs($this->buyer)
            ->test(MessageThread::class, ['transaction' => $this->transaction])
            ->call('unpinMessage');

        $fresh = $msg->fresh();
        $this->assertNull($fresh->pinned_at);
        $this->assertNull($fresh->pinned_by_id);
    }
}
