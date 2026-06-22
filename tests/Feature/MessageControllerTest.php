<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class MessageControllerTest extends TestCase
{
    public function test_show_with_user_creates_direct_conversation_when_none_exists(): void
    {
        $organization = Organization::factory()->create();
        $sender = User::factory()->create(['organization_id' => $organization->id]);
        $recipient = User::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($sender)->get(route('messages.with', $recipient));

        $transaction = Transaction::withoutGlobalScopes()->first();

        $this->assertNotNull($transaction);
        $this->assertTrue($transaction->isDirectConversation());
        $this->assertSame($organization->id, $transaction->organization_id);
        $this->assertSame($sender->id, $transaction->buyer_id);
        $this->assertSame($recipient->id, $transaction->seller_id);

        $response->assertRedirect(route('messages.show', $transaction));

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $transaction->id,
            'sender_id' => null,
            'body' => 'Conversation directe démarrée.',
            'type' => 'system',
            'organization_id' => $organization->id,
        ]);
    }

    public function test_show_with_user_reuses_existing_direct_conversation(): void
    {
        $organization = Organization::factory()->create();
        $sender = User::factory()->create(['organization_id' => $organization->id]);
        $recipient = User::factory()->create(['organization_id' => $organization->id]);

        $transaction = Transaction::factory()->create([
            'organization_id' => $organization->id,
            'buyer_id' => $sender->id,
            'seller_id' => $recipient->id,
            'service_id' => null,
            'request_id' => null,
            'points_proposed' => 0,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($sender)->get(route('messages.with', $recipient));

        $response->assertRedirect(route('messages.show', $transaction));
        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
        $this->assertSame(0, Message::count());
    }
}
