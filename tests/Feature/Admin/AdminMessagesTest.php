<?php

namespace Tests\Feature\Admin;

use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class AdminMessagesTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function makeMessageWithTransaction(): Message
    {
        $transaction = Transaction::factory()->create();
        return Message::factory()->forTransaction($transaction)->create();
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_guest_cannot_access_admin_messages(): void
    {
        $this->get(route('admin.messages'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_admin_messages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.messages'))->assertStatus(403);
    }

    public function test_non_admin_cannot_access_message_detail(): void
    {
        $user = User::factory()->create();
        $message = $this->makeMessageWithTransaction();
        $this->actingAs($user)->get(route('admin.messages.show', $message))->assertStatus(403);
    }

    public function test_non_admin_cannot_delete_message(): void
    {
        $user = User::factory()->create();
        $message = $this->makeMessageWithTransaction();
        $this->actingAs($user)->delete(route('admin.messages.destroy', $message))->assertStatus(403);
    }

    // ── List ──────────────────────────────────────────────────────────────────

    public function test_admin_can_view_messages_list(): void
    {
        $admin = $this->makeAdmin();
        $this->makeMessageWithTransaction();

        $this->actingAs($admin)->get(route('admin.messages'))->assertOk();
    }

    public function test_messages_list_shows_messages(): void
    {
        $admin = $this->makeAdmin();
        $transaction = Transaction::factory()->create();
        $message = Message::factory()->forTransaction($transaction)->create([
            'body' => 'Bonjour, je suis intéressé par votre service.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.messages'))
            ->assertOk()
            ->assertSee('Bonjour, je suis intéressé');
    }

    // ── Filters ───────────────────────────────────────────────────────────────

    public function test_filter_by_keyword_returns_matching_messages(): void
    {
        $admin = $this->makeAdmin();
        $transaction = Transaction::factory()->create();

        Message::factory()->forTransaction($transaction)->create(['body' => 'Message avec motclé spécial']);
        Message::factory()->forTransaction($transaction)->create(['body' => 'Autre contenu sans rapport']);

        $response = $this->actingAs($admin)
            ->get(route('admin.messages', ['search' => 'motclé spécial']));

        $response->assertOk()->assertSee('motclé spécial')->assertDontSee('Autre contenu sans rapport');
    }

    public function test_filter_by_user_returns_messages_from_sender(): void
    {
        $admin = $this->makeAdmin();
        $sender = User::factory()->create(['name' => 'Alice Dupont']);
        $other = User::factory()->create(['name' => 'Bob Martin']);

        $transaction = Transaction::factory()->create(['buyer_id' => $sender->id]);
        Message::factory()->forTransaction($transaction)->create([
            'sender_id' => $sender->id,
            'body' => 'Message d Alice',
        ]);

        $transaction2 = Transaction::factory()->create(['buyer_id' => $other->id]);
        Message::factory()->forTransaction($transaction2)->create([
            'sender_id' => $other->id,
            'body' => 'Message de Bob',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.messages', ['user' => 'Alice']));

        $response->assertOk()->assertSee('Message d Alice')->assertDontSee('Message de Bob');
    }

    public function test_filter_by_date_from_excludes_older_messages(): void
    {
        $admin = $this->makeAdmin();
        $transaction = Transaction::factory()->create();

        $old = Message::factory()->forTransaction($transaction)->create([
            'body' => 'Vieux message',
            'created_at' => now()->subDays(10),
        ]);
        $recent = Message::factory()->forTransaction($transaction)->create([
            'body' => 'Message récent',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.messages', ['date_from' => now()->subDay()->format('Y-m-d')]));

        $response->assertOk()->assertSee('Message récent')->assertDontSee('Vieux message');
    }

    // ── Detail ────────────────────────────────────────────────────────────────

    public function test_admin_can_view_message_detail(): void
    {
        $admin = $this->makeAdmin();
        $message = $this->makeMessageWithTransaction();

        $this->actingAs($admin)
            ->get(route('admin.messages.show', $message))
            ->assertOk()
            ->assertSee($message->body);
    }

    public function test_message_detail_shows_context(): void
    {
        $admin = $this->makeAdmin();
        $transaction = Transaction::factory()->create();

        $before = Message::factory()->forTransaction($transaction)->create([
            'body' => 'Message avant',
            'created_at' => now()->subMinutes(5),
        ]);
        $target = Message::factory()->forTransaction($transaction)->create([
            'body' => 'Message ciblé',
            'created_at' => now()->subMinutes(3),
        ]);
        $after = Message::factory()->forTransaction($transaction)->create([
            'body' => 'Message après',
            'created_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.messages.show', $target))
            ->assertOk()
            ->assertSee('Message avant')
            ->assertSee('Message ciblé')
            ->assertSee('Message après');
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_admin_can_delete_message(): void
    {
        $admin = $this->makeAdmin();
        $message = $this->makeMessageWithTransaction();

        $this->actingAs($admin)
            ->delete(route('admin.messages.destroy', $message))
            ->assertRedirect(route('admin.messages'));

        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_delete_message_shows_flash_confirmation(): void
    {
        $admin = $this->makeAdmin();
        $message = $this->makeMessageWithTransaction();

        $this->actingAs($admin)
            ->delete(route('admin.messages.destroy', $message))
            ->assertRedirect(route('admin.messages'))
            ->assertSessionHas('success');
    }
}
