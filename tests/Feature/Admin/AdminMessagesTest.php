<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use Tests\TestCase;

class AdminMessagesTest extends TestCase
{
    private function makeAdmin(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['is_admin' => true], $overrides));
    }

    private function makeOrg(): Organization
    {
        return Organization::factory()->create(['is_active' => true]);
    }

    private function makeLoop(Organization $org, ?User $creator = null): Loop
    {
        return Loop::factory()->create([
            'community_id' => $org->id,
            'created_by' => $creator?->id ?? User::factory(),
            'type' => 'custom',
            'status' => 'active',
        ]);
    }

    private function addMember(Loop $loop, User $user): LoopMember
    {
        return LoopMember::factory()->create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    private function makeLoopMessage(Loop $loop, ?User $sender = null, string $body = 'ChatLoop message test'): LoopMessage
    {
        return LoopMessage::factory()->create([
            'loop_id' => $loop->id,
            'sender_id' => $sender?->id ?? User::factory(),
            'body' => $body,
        ]);
    }

    private function makeTransactionInOrg(Organization $org, ?User $buyer = null, ?User $seller = null): Transaction
    {
        $buyer ??= User::factory()->create();
        $seller ??= User::factory()->create();

        $tx = Transaction::factory()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'community_id' => $org->id,
            'organization_id' => $org->id,
        ]);

        return $tx;
    }

    private function makeExchangeMessage(Transaction $transaction, ?User $sender = null, string $body = 'Exchange message test'): Message
    {
        return Message::factory()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $sender?->id ?? User::factory(),
            'body' => $body,
        ]);
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

    public function test_admin_can_access_admin_messages(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);

        $this->actingAs($admin)->get(route('admin.messages'))->assertOk();
    }

    // ── Default filter ────────────────────────────────────────────────────────

    public function test_default_filter_is_chatloop(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);

        $loop = $this->makeLoop($org, $admin);
        $this->addMember($loop, $admin);

        $this->makeLoopMessage($loop, $admin);

        $response = $this->actingAs($admin)->get(route('admin.messages'));

        $response->assertOk();
        $response->assertSee('ChatLoop');
    }

    // ── ChatLoop filter ───────────────────────────────────────────────────────

    public function test_chatloop_filter_shows_loop_messages(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);

        $loop = $this->makeLoop($org, $admin);
        $this->addMember($loop, $admin);

        $msg = $this->makeLoopMessage($loop, $admin, body: 'CHATLOOP LOCAL MSG');

        $response = $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'chatloop']));

        $response->assertOk();
        $response->assertSee('CHATLOOP LOCAL MSG');
    }

    // ── Exchanges filter ──────────────────────────────────────────────────────

    public function test_exchanges_filter_shows_transaction_messages(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id, 'community_id' => $org->id]);

        $tx = $this->makeTransactionInOrg($org, $admin, $member);
        $msg = $this->makeExchangeMessage($tx, $admin, body: 'EXCHANGE LOCAL MSG');

        app()->instance('current_organization', $org);

        $response = $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'exchanges']));

        $response->assertOk();
        $response->assertSee('EXCHANGE LOCAL MSG');
    }

    // ── All filter ────────────────────────────────────────────────────────────

    public function test_all_filter_shows_both_types(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id, 'community_id' => $org->id]);

        $loop = $this->makeLoop($org, $admin);
        $this->addMember($loop, $admin);
        $this->makeLoopMessage($loop, $admin, body: 'ALL CHATLOOP MSG');

        $tx = $this->makeTransactionInOrg($org, $admin, $member);
        $this->makeExchangeMessage($tx, $admin, body: 'ALL EXCHANGE MSG');

        app()->instance('current_organization', $org);

        $response = $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'all']));

        $response->assertOk();
        $response->assertSee('ALL CHATLOOP MSG');
        $response->assertSee('ALL EXCHANGE MSG');
        $response->assertSee('ChatLoop');
        $response->assertSee('Échange');
    }

    // ── Tenant isolation ──────────────────────────────────────────────────────

    public function test_admin_sees_only_own_organization_chatloop_messages(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();

        $adminA = $this->makeAdmin(['organization_id' => $orgA->id, 'community_id' => $orgA->id]);
        $adminB = $this->makeAdmin(['organization_id' => $orgB->id, 'community_id' => $orgB->id]);

        $loopA = $this->makeLoop($orgA, $adminA);
        $this->addMember($loopA, $adminA);
        $this->makeLoopMessage($loopA, $adminA, body: 'CHATLOOP ORG A MSG');

        $loopB = $this->makeLoop($orgB, $adminB);
        $this->addMember($loopB, $adminB);
        $this->makeLoopMessage($loopB, $adminB, body: 'CHATLOOP ORG B MSG');

        $response = $this->actingAs($adminA)
            ->get(route('admin.messages', ['filter' => 'chatloop']));

        $response->assertOk();
        $response->assertSee('CHATLOOP ORG A MSG');
        $response->assertDontSee('CHATLOOP ORG B MSG');
    }

    public function test_admin_sees_only_own_organization_exchange_messages(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();

        $adminA = $this->makeAdmin(['organization_id' => $orgA->id, 'community_id' => $orgA->id]);
        $memberA = User::factory()->create(['organization_id' => $orgA->id, 'community_id' => $orgA->id]);

        $adminB = $this->makeAdmin(['organization_id' => $orgB->id, 'community_id' => $orgB->id]);
        $memberB = User::factory()->create(['organization_id' => $orgB->id, 'community_id' => $orgB->id]);

        $txA = $this->makeTransactionInOrg($orgA, $adminA, $memberA);
        $this->makeExchangeMessage($txA, $adminA, body: 'EXCHANGE ORG A MSG');

        $txB = $this->makeTransactionInOrg($orgB, $adminB, $memberB);
        $this->makeExchangeMessage($txB, $adminB, body: 'EXCHANGE ORG B MSG');

        app()->instance('current_organization', $orgA);

        $response = $this->actingAs($adminA)
            ->get(route('admin.messages', ['filter' => 'exchanges']));

        $response->assertOk();
        $response->assertSee('EXCHANGE ORG A MSG');
        $response->assertDontSee('EXCHANGE ORG B MSG');
    }

    // ── Empty states ──────────────────────────────────────────────────────────

    public function test_empty_state_chatloop(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'chatloop']))
            ->assertOk()
            ->assertSee('Aucun message ChatLoop');
    }

    public function test_empty_state_exchanges(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'exchanges']))
            ->assertOk()
            ->assertSee('Aucun échange');
    }

    public function test_empty_state_all(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'all']))
            ->assertOk()
            ->assertSee('Aucun message');
    }

    // ── Admin without org ─────────────────────────────────────────────────────

    public function test_admin_without_org_sees_no_messages(): void
    {
        $admin = $this->makeAdmin();

        $org = $this->makeOrg();
        $loop = $this->makeLoop($org);
        $this->makeLoopMessage($loop);

        $response = $this->actingAs($admin)->get(route('admin.messages'));

        $response->assertOk();
        $response->assertSee('Aucun message ChatLoop');
    }

    // ── Detail (org-scoped) ───────────────────────────────────────────────────

    public function test_admin_can_view_message_detail_within_organization(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);

        $tx = $this->makeTransactionInOrg($org);
        $message = $this->makeExchangeMessage($tx);

        app()->instance('current_organization', $org);

        $this->actingAs($admin)
            ->get(route('admin.messages.show', $message))
            ->assertOk()
            ->assertSee($message->body);
    }

    public function test_admin_cannot_view_message_detail_outside_organization(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();

        $adminA = $this->makeAdmin(['organization_id' => $orgA->id, 'community_id' => $orgA->id]);

        $txB = $this->makeTransactionInOrg($orgB);
        $messageB = $this->makeExchangeMessage($txB);

        $this->actingAs($adminA)
            ->get(route('admin.messages.show', $messageB))
            ->assertNotFound();
    }

    public function test_admin_without_org_cannot_view_message_detail(): void
    {
        $admin = $this->makeAdmin();

        $org = $this->makeOrg();
        $tx = $this->makeTransactionInOrg($org);
        $message = $this->makeExchangeMessage($tx);

        $this->actingAs($admin)
            ->get(route('admin.messages.show', $message))
            ->assertNotFound();
    }

    // ── Delete disabled (read-only) ───────────────────────────────────────────

    public function test_admin_cannot_delete_message(): void
    {
        $admin = $this->makeAdmin();
        $org = $this->makeOrg();
        $tx = $this->makeTransactionInOrg($org);
        $message = $this->makeExchangeMessage($tx);

        $this->actingAs($admin)
            ->delete("/admin/messages/{$message->id}")
            ->assertStatus(405);
    }

    // ── Unknown filter ────────────────────────────────────────────────────────

    public function test_unknown_filter_falls_back_to_chatloop(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'community_id' => $org->id]);

        $loop = $this->makeLoop($org, $admin);
        $this->addMember($loop, $admin);
        $this->makeLoopMessage($loop, $admin, body: 'FALLBACK CHATLOOP MSG');

        $tx = $this->makeTransactionInOrg($org, $admin, User::factory()->create());
        $this->makeExchangeMessage($tx, $admin, body: 'FALLBACK EXCHANGE MSG');

        $response = $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'invalid']));

        $response->assertOk();
        $response->assertSee('FALLBACK CHATLOOP MSG');
        $response->assertDontSee('FALLBACK EXCHANGE MSG');
    }
}
