<?php

namespace Tests\Feature\Livewire;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MessageThreadTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $seller;
    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create(['points_balance' => 200]);
        $this->seller = User::factory()->create();
        $this->transaction = Transaction::factory()->create([
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);
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
}
