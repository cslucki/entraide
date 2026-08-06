<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Livewire\LoopEventsCard;
use App\Livewire\LoopPollsCard;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopPollService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Une activite publiee par une Card doit se voir dans ChatLoop tout de suite.
 *
 * L'audit a corrige la premisse de cette tache. ChatLoop porte deja
 * `wire:poll.3s`, et `LoopChat::render()` appelle `syncNewerMessages()` a chaque
 * rendu : le message **apparaissait deja**, avec jusqu'a trois secondes de
 * latence. Ce qui manquait, ce n'est pas l'affichage — c'est l'immediatete.
 *
 * Les premiers tests de ce fichier caracterisent donc l'existant avant tout
 * changement, pour que la suite mesure un gain reel et non un defaut imaginaire.
 */
class TASK1093ChatRefreshTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $member;

    private User $stranger;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create();
        $this->org = Organization::factory()->create(['is_active' => true, 'admin_id' => $admin->id]);
        $admin->update(['organization_id' => $this->org->id]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->member = User::factory()->create(['organization_id' => $this->org->id]);
        $this->stranger = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle de travail',
            'slug' => 'boucle-de-travail',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $this->owner->id,
        ]);

        app(LoopTypeRegistry::class)->applyPreset($this->loop);

        $this->join($this->owner, 'owner');
        $this->join($this->member, 'member');

        app()->instance('current_organization', $this->org);
    }

    private function join(User $user, string $role): void
    {
        LoopMember::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function poll(): LoopPoll
    {
        return app(LoopPollService::class)->create(
            $this->owner, $this->loop, 'Une question ?', '',
            LoopPoll::TYPE_SINGLE, ['Oui', 'Non'], false, null,
        );
    }

    private function chatMessages(User $as): array
    {
        return Livewire::actingAs($as)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->viewData('messages')
            ->pluck('type')
            ->all();
    }

    // ── Caracterisation de l'existant ───────────────────────────────────────

    public function test_a_freshly_mounted_chat_already_carries_the_card_activity(): void
    {
        // La premisse corrigee : rien n'empeche le message d'apparaitre. Un
        // ChatLoop monte apres l'action le porte deja.
        Livewire::actingAs($this->owner)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->set('question', 'Quand se voit-on ?')
            ->set('options', ['Lundi', 'Mardi'])
            ->call('save');

        $this->assertContains('poll_event', $this->chatMessages($this->owner));
    }

    public function test_an_already_open_chat_catches_up_on_its_next_render(): void
    {
        // Le mecanisme qui rendait le defaut invisible : syncNewerMessages()
        // tourne a chaque rendu, donc a chaque battement de `wire:poll.3s`.
        $chat = Livewire::actingAs($this->owner)
            ->test(LoopChat::class, ['loop' => $this->loop]);

        $avant = count($chat->viewData('messages'));

        $poll = $this->poll();
        app(\App\Services\LoopMessageService::class)
            ->sendPollEventMessage($this->loop, $this->owner, $poll, 'created');

        // Un simple nouveau rendu suffit : c'est ce que fait le poll.
        $chat->call('$refresh');

        $this->assertGreaterThan($avant, count($chat->viewData('messages')));
    }

    // ── L'evenement, la ou il doit etre ─────────────────────────────────────

    public function test_creating_a_poll_announces_the_activity(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->set('question', 'On y va ?')
            ->set('options', ['Oui', 'Non'])
            ->call('save')
            ->assertDispatched('loop-activity-published', loopId: $this->loop->id);
    }

    public function test_closing_a_poll_announces_the_activity(): void
    {
        $poll = $this->poll();

        Livewire::actingAs($this->owner)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->call('close', $poll->id)
            ->assertDispatched('loop-activity-published', loopId: $this->loop->id);
    }

    public function test_the_payload_carries_the_loop_it_belongs_to(): void
    {
        // Sans identifiant de Boucle, un ChatLoop voisin ne saurait pas si
        // l'evenement le concerne.
        Livewire::actingAs($this->owner)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->set('question', 'Une autre ?')
            ->set('options', ['A', 'B'])
            ->call('save')
            ->assertDispatched('loop-activity-published', fn ($name, $params) => ($params['loopId'] ?? null) === $this->loop->id);
    }

    public function test_a_refused_action_announces_nothing(): void
    {
        // Rien ne doit etre annonce avant que le geste metier ait reussi.
        Livewire::actingAs($this->owner)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->set('question', '')
            ->set('options', ['Oui', 'Non'])
            ->call('save')
            ->assertNotDispatched('loop-activity-published');
    }

    public function test_a_non_member_announces_nothing(): void
    {
        Livewire::actingAs($this->stranger)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->set('question', 'Puis-je ?')
            ->set('options', ['Oui', 'Non'])
            ->call('save')
            ->assertNotDispatched('loop-activity-published');
    }

    // ── ChatLoop ecoute, et seulement ce qui le concerne ────────────────────

    public function test_the_chat_takes_the_event_of_its_own_loop(): void
    {
        $poll = $this->poll();
        app(\App\Services\LoopMessageService::class)
            ->sendPollEventMessage($this->loop, $this->owner, $poll, 'created');

        $chat = Livewire::actingAs($this->owner)->test(LoopChat::class, ['loop' => $this->loop]);
        $avant = count($chat->viewData('messages'));

        // Un message arrive pendant que le fil est ouvert.
        $poll2 = $this->poll();
        app(\App\Services\LoopMessageService::class)
            ->sendPollEventMessage($this->loop, $this->owner, $poll2, 'created');

        $chat->dispatch('loop-activity-published', loopId: $this->loop->id);

        $this->assertGreaterThan($avant, count($chat->viewData('messages')));
    }

    public function test_the_chat_ignores_the_event_of_another_loop(): void
    {
        $autre = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Une autre Boucle',
            'slug' => 'une-autre-boucle',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $this->owner->id,
        ]);

        $chat = Livewire::actingAs($this->owner)->test(LoopChat::class, ['loop' => $this->loop]);
        $avant = $chat->viewData('messages')->pluck('id')->all();

        $chat->dispatch('loop-activity-published', loopId: $autre->id);

        $this->assertSame($avant, $chat->viewData('messages')->pluck('id')->all());
    }

    public function test_no_message_of_another_loop_ever_enters_the_thread(): void
    {
        $autre = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle voisine',
            'slug' => 'boucle-voisine',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $this->owner->id,
        ]);

        LoopMessage::create([
            'loop_id' => $autre->id,
            'organization_id' => $this->org->id,
            'sender_id' => $this->owner->id,
            'body' => 'Message de la Boucle voisine',
            'type' => 'user',
        ]);

        $chat = Livewire::actingAs($this->owner)->test(LoopChat::class, ['loop' => $this->loop]);
        $chat->dispatch('loop-activity-published', loopId: $this->loop->id);

        $this->assertStringNotContainsString('Message de la Boucle voisine', $chat->html());
    }

    public function test_no_message_of_another_organization_ever_enters_the_thread(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $autreUser = User::factory()->create(['organization_id' => $autreOrg->id]);

        $autreLoop = Loop::create([
            'organization_id' => $autreOrg->id,
            'name' => 'Boucle etrangere',
            'slug' => 'boucle-etrangere',
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'private',
            'created_by' => $autreUser->id,
        ]);

        LoopMessage::create([
            'loop_id' => $autreLoop->id,
            'organization_id' => $autreOrg->id,
            'sender_id' => $autreUser->id,
            'body' => 'Secret d une autre Organization',
            'type' => 'user',
        ]);

        $chat = Livewire::actingAs($this->owner)->test(LoopChat::class, ['loop' => $this->loop]);
        $chat->dispatch('loop-activity-published', loopId: $this->loop->id);

        $this->assertStringNotContainsString('Secret d une autre Organization', $chat->html());
    }

    // ── Ce que le rafraichissement ne doit pas casser ───────────────────────

    public function test_the_draft_being_typed_survives_the_refresh(): void
    {
        // Le reproche le plus concret qu'on pourrait faire a un rafraichissement.
        $chat = Livewire::actingAs($this->owner)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Un message que je suis en train d ecrire');

        $poll = $this->poll();
        app(\App\Services\LoopMessageService::class)
            ->sendPollEventMessage($this->loop, $this->owner, $poll, 'created');

        $chat->dispatch('loop-activity-published', loopId: $this->loop->id);

        $chat->assertSet('body', 'Un message que je suis en train d ecrire');
    }

    public function test_the_same_message_never_appears_twice(): void
    {
        $poll = $this->poll();
        app(\App\Services\LoopMessageService::class)
            ->sendPollEventMessage($this->loop, $this->owner, $poll, 'created');

        $chat = Livewire::actingAs($this->owner)->test(LoopChat::class, ['loop' => $this->loop]);

        // Plusieurs evenements successifs, comme quand on enchaine les gestes.
        foreach (range(1, 4) as $i) {
            $chat->dispatch('loop-activity-published', loopId: $this->loop->id);
        }

        $ids = $chat->viewData('messages')->pluck('id')->all();

        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_a_message_already_loaded_is_not_loaded_again(): void
    {
        $poll = $this->poll();
        app(\App\Services\LoopMessageService::class)
            ->sendPollEventMessage($this->loop, $this->owner, $poll, 'created');

        $chat = Livewire::actingAs($this->owner)->test(LoopChat::class, ['loop' => $this->loop]);
        $avant = count($chat->viewData('messages'));

        $chat->dispatch('loop-activity-published', loopId: $this->loop->id);

        $this->assertSame($avant, count($chat->viewData('messages')));
    }

    // ── Boucle archivee et Card eteinte ─────────────────────────────────────

    public function test_an_archived_loop_publishes_nothing(): void
    {
        $this->loop->update(['status' => 'archived', 'archived_at' => now()]);

        Livewire::actingAs($this->owner)
            ->test(LoopPollsCard::class, ['loop' => $this->loop->fresh()])
            ->set('question', 'Malgre l archivage ?')
            ->set('options', ['Oui', 'Non'])
            ->call('save')
            ->assertNotDispatched('loop-activity-published');
    }

    public function test_a_disabled_card_publishes_nothing(): void
    {
        app(LoopCardCompositionService::class)->disable($this->loop, 'core.polls');
        $this->loop->refresh();

        Livewire::actingAs($this->owner)
            ->test(LoopPollsCard::class, ['loop' => $this->loop])
            ->set('question', 'Card eteinte ?')
            ->set('options', ['Oui', 'Non'])
            ->call('save')
            ->assertNotDispatched('loop-activity-published');
    }
}
