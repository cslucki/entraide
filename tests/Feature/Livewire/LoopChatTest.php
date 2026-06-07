<?php

namespace Tests\Feature\Livewire;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->call('sendMessage')
            ->assertHasErrors(['body' => 'required']);
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
            ->assertDontSee('Écrivez un message');
    }

    public function test_member_sees_composer(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->assertSee('Écrivez un message');
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
            ->assertSee('Demande d\'aide', false)
            ->assertSee('Design Help');
    }

    public function test_body_not_lost_during_render(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Ongoing message')
            ->assertSet('body', 'Ongoing message');
    }
}
