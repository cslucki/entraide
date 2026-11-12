<?php

namespace Tests\Feature\Admin;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
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
            'organization_id' => $org->id,
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
            'organization_id' => $loop->organization_id,
        ]);
    }

    private function makeTransactionInOrg(Organization $org, ?User $buyer = null, ?User $seller = null): Transaction
    {
        $buyer ??= User::factory()->create();
        $seller ??= User::factory()->create();

        $tx = Transaction::factory()->create([
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
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
            'organization_id' => $transaction->organization_id,
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
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

        $this->actingAs($admin)->get(route('admin.messages'))->assertOk();
    }

    // ── Default filter ────────────────────────────────────────────────────────

    /**
     * TASK-1795 correctif 3 — ce test etait un FAUX VERT.
     *
     * Il s'appelait `test_default_filter_is_chatloop` et n'a jamais mesure le
     * defaut : `assertSee('ChatLoop')` rencontre l'ETIQUETTE DE L'ONGLET, que
     * la vue rend a chaque fois, quel que soit le filtre actif. Il serait reste
     * vert avec n'importe quel defaut — il l'est d'ailleurs reste apres le
     * passage a `all`, ce qui l'a trahi.
     *
     * Il demande desormais ce que son nom annonce, sur ce que la VUE a recu
     * plutot que sur du HTML : les deux defauts de l'ecran, type et
     * Organization.
     */
    public function test_default_filter_is_all_types_and_all_organizations(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

        $loop = $this->makeLoop($org, $admin);
        $this->addMember($loop, $admin);

        $this->makeLoopMessage($loop, $admin);

        $response = $this->actingAs($admin)->get(route('admin.messages'));

        $response->assertOk();
        $response->assertViewHas('filter', 'all');
        $response->assertViewHas('selectedOrganizationId', 'all');
    }

    // ── ChatLoop filter ───────────────────────────────────────────────────────

    public function test_chatloop_filter_shows_loop_messages(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

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
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id, 'organization_id' => $org->id]);

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
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);
        $member = User::factory()->create(['organization_id' => $org->id, 'organization_id' => $org->id]);

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

    /**
     * TASK-1795 correctif 3 — le filtre est desormais EXPLICITE dans ce test.
     *
     * Ce test ne reposait sur aucun filtre : il lisait le DEFAUT, qui valait
     * alors l'Organization par defaut — ici `$orgA`, premiere Organization
     * active (`DefaultOrganizationResolver`). Il mesurait donc un defaut, pas
     * la garantie que son nom annonce.
     *
     * Le defaut vaut maintenant « Toutes les organisations », a la demande de
     * Cyril : « Actuellement c'est sur `main`. Donc il faut passer par le menu,
     * et c'est perte de temps. » La garantie, elle, ne bouge pas — un
     * `organization_id` explicite restreint toujours — et c'est ELLE que ce
     * test nomme, donc c'est elle qu'il demande desormais.
     *
     * L'isolation reelle de l'ecran n'est pas portee par ce defaut : elle vit
     * dans `test_admin_cannot_view_message_detail_outside_organization`, qui
     * reste inchange, et `/admin/messages` est deja derriere `is_admin` — le
     * SuperAdmin PLATEFORME, qui pouvait deja choisir « Toutes » au menu.
     */
    public function test_admin_sees_only_own_organization_chatloop_messages(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();

        $adminA = $this->makeAdmin(['organization_id' => $orgA->id, 'organization_id' => $orgA->id]);
        $adminB = $this->makeAdmin(['organization_id' => $orgB->id, 'organization_id' => $orgB->id]);

        $loopA = $this->makeLoop($orgA, $adminA);
        $this->addMember($loopA, $adminA);
        $this->makeLoopMessage($loopA, $adminA, body: 'CHATLOOP ORG A MSG');

        $loopB = $this->makeLoop($orgB, $adminB);
        $this->addMember($loopB, $adminB);
        $this->makeLoopMessage($loopB, $adminB, body: 'CHATLOOP ORG B MSG');

        $response = $this->actingAs($adminA)
            ->get(route('admin.messages', [
                'filter' => 'chatloop',
                'organization_id' => $orgA->id,
            ]));

        $response->assertOk();
        $response->assertSee('CHATLOOP ORG A MSG');
        $response->assertDontSee('CHATLOOP ORG B MSG');
    }

    public function test_admin_sees_only_own_organization_exchange_messages(): void
    {
        $orgA = $this->makeOrg();
        $orgB = $this->makeOrg();

        $adminA = $this->makeAdmin(['organization_id' => $orgA->id, 'organization_id' => $orgA->id]);
        $memberA = User::factory()->create(['organization_id' => $orgA->id, 'organization_id' => $orgA->id]);

        $adminB = $this->makeAdmin(['organization_id' => $orgB->id, 'organization_id' => $orgB->id]);
        $memberB = User::factory()->create(['organization_id' => $orgB->id, 'organization_id' => $orgB->id]);

        $txA = $this->makeTransactionInOrg($orgA, $adminA, $memberA);
        $this->makeExchangeMessage($txA, $adminA, body: 'EXCHANGE ORG A MSG');

        $txB = $this->makeTransactionInOrg($orgB, $adminB, $memberB);
        $this->makeExchangeMessage($txB, $adminB, body: 'EXCHANGE ORG B MSG');

        app()->instance('current_organization', $orgA);

        $response = $this->actingAs($adminA)
            ->get(route('admin.messages', [
                'filter' => 'exchanges',
                'organization_id' => $orgA->id,
            ]));

        $response->assertOk();
        $response->assertSee('EXCHANGE ORG A MSG');
        $response->assertDontSee('EXCHANGE ORG B MSG');
    }

    // ── Empty states ──────────────────────────────────────────────────────────

    public function test_empty_state_chatloop(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'chatloop']))
            ->assertOk()
            ->assertSee('Aucun message ChatLoop');
    }

    public function test_empty_state_exchanges(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'exchanges']))
            ->assertOk()
            ->assertSee('Aucun échange');
    }

    public function test_empty_state_all(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

        $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'all']))
            ->assertOk()
            ->assertSee('Aucun message');
    }

    // ── Admin without org ─────────────────────────────────────────────────────

    /**
     * TASK-1795 correctif 3 — « sans org » voulait dire « hors de l'org visee ».
     *
     * L'administrateur a toujours eu une Organization (`UserFactory` lui en
     * donne une) : ce qu'il n'avait pas, c'est l'Organization qui porte le
     * message. Le test lisait le DEFAUT, qui valait alors sa propre
     * Organization — vide — d'ou l'etat vide attendu.
     *
     * Le defaut vaut maintenant « Toutes les organisations », et le message
     * existe : la liste n'est plus vide, et c'est voulu. Ce que le test dit
     * reellement — « mon Organization ne contient aucun message » — reste
     * vrai et se demande desormais explicitement.
     */
    public function test_admin_sees_an_empty_state_when_his_own_organization_has_no_message(): void
    {
        $admin = $this->makeAdmin();

        $org = $this->makeOrg();
        $loop = $this->makeLoop($org);
        $this->makeLoopMessage($loop);

        $response = $this->actingAs($admin)->get(route('admin.messages', [
            'filter' => 'chatloop',
            'organization_id' => $admin->organization_id,
        ]));

        $response->assertOk();
        $response->assertSee('Aucun message ChatLoop');
    }

    // ── Detail (org-scoped) ───────────────────────────────────────────────────

    public function test_admin_can_view_message_detail_within_organization(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

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

        $adminA = $this->makeAdmin(['organization_id' => $orgA->id, 'organization_id' => $orgA->id]);

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

    // ── Delete ────────────────────────────────────────────────────────────────

    public function test_admin_can_delete_exchange_message(): void
    {
        $admin = $this->makeAdmin();
        $org = $this->makeOrg();
        $tx = $this->makeTransactionInOrg($org);
        $message = $this->makeExchangeMessage($tx);

        $this->actingAs($admin)
            ->delete("/admin/messages/{$message->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
    }

    public function test_admin_can_delete_loop_message(): void
    {
        $admin = $this->makeAdmin();
        $org = $this->makeOrg();
        $loop = $this->makeLoop($org, $admin);
        $this->addMember($loop, $admin);
        $message = $this->makeLoopMessage($loop, $admin);

        $this->actingAs($admin)
            ->delete("/admin/loop-messages/{$message->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('loop_messages', ['id' => $message->id]);
    }

    // ── Unknown filter ────────────────────────────────────────────────────────

    /**
     * TASK-1795 correctif 3 — un filtre inconnu retombe sur `all`, plus sur
     * `chatloop`. Ce que le test garde : une valeur invalide ne lit JAMAIS la
     * chaine telle quelle, elle retombe sur le defaut de l'ecran. Seul ce
     * defaut a change.
     */
    public function test_unknown_filter_falls_back_to_the_default_tab(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeAdmin(['organization_id' => $org->id, 'organization_id' => $org->id]);

        $loop = $this->makeLoop($org, $admin);
        $this->addMember($loop, $admin);
        $this->makeLoopMessage($loop, $admin, body: 'FALLBACK CHATLOOP MSG');

        $tx = $this->makeTransactionInOrg($org, $admin, User::factory()->create());
        $this->makeExchangeMessage($tx, $admin, body: 'FALLBACK EXCHANGE MSG');

        $response = $this->actingAs($admin)
            ->get(route('admin.messages', ['filter' => 'invalid']));

        $response->assertOk();
        $response->assertViewHas('filter', 'all');

        // `all` melange les deux modeles : les DEUX messages sont la, et c'est
        // la preuve que la valeur « invalid » n'a pas ete lue telle quelle.
        $response->assertSee('FALLBACK CHATLOOP MSG');
        $response->assertSee('FALLBACK EXCHANGE MSG');
    }
}
