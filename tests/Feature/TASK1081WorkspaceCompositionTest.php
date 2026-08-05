<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The workspace shows the Loop's own cards, not the catalogue's.
 *
 * The defect this file guards against: loops/show.blade.php built the card list
 * from config/loop_cards.php filtered on `default_enabled`, so every Loop showed
 * every card whatever its composition — and three of them opened on an empty
 * panel because `requires_card` denied the read.
 *
 * The rule under test is the **effective composition**, never the type preset:
 * a Loop keeps what it has, whatever its type says today.
 */
class TASK1081WorkspaceCompositionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $this->member = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function loop(string $type = 'general', bool $applyPreset = true): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => $type,
        ]);

        if ($applyPreset) {
            app(LoopTypeRegistry::class)->applyPreset($loop);
        }

        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->member->id, 'joined_at' => now(),
        ]);

        return $loop;
    }

    private function workspace(Loop $loop, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->member)->get(route('loops.show', $loop));
    }

    private function label(string $key): string
    {
        return __(config('loop_cards.cards')[$key]['label_key']);
    }

    // ── Composition ─────────────────────────────────────────────────────────

    public function test_a_dialogue_loop_shows_only_its_members_card(): void
    {
        $loop = $this->loop('general');

        $this->workspace($loop)->assertOk()
            ->assertSee($this->label('core.members'))
            ->assertDontSee($this->label('core.manifesto'))
            ->assertDontSee($this->label('core.roadmap'))
            ->assertDontSee($this->label('core.ai_summary'));
    }

    public function test_the_refused_cards_are_not_even_offered(): void
    {
        // The heart of the defect: the buttons existed, and opened on nothing.
        $html = $this->workspace($this->loop('general'))->assertOk()->getContent();

        $this->assertStringNotContainsString('core.manifesto', $html);
        $this->assertStringNotContainsString('core.roadmap', $html);
        $this->assertStringNotContainsString('core.ai_summary', $html);
    }

    public function test_a_project_loop_shows_the_cards_it_actually_has(): void
    {
        $loop = $this->loop('project');

        $response = $this->workspace($loop)->assertOk();

        foreach (app(LoopTypeRegistry::class)->cardsFor('project') as $key) {
            $response->assertSee($this->label($key));
        }
    }

    public function test_a_locally_disabled_card_is_hidden(): void
    {
        $loop = $this->loop('project');
        LoopCard::where('loop_id', $loop->id)->where('card_key', 'core.roadmap')->update(['enabled' => false]);

        $this->workspace($loop)->assertOk()
            ->assertSee($this->label('core.manifesto'))
            ->assertDontSee($this->label('core.roadmap'));
    }

    public function test_a_card_added_locally_outside_the_preset_is_shown(): void
    {
        // Dialogue does not prescribe the Roadmap. A Loop that adds it anyway
        // must see it: the composition is the Loop's, not the type's.
        $loop = $this->loop('general');
        LoopCard::create([
            'organization_id' => $this->org->id, 'loop_id' => $loop->id,
            'card_key' => 'core.roadmap', 'enabled' => true,
        ]);

        $this->workspace($loop)->assertOk()->assertSee($this->label('core.roadmap'));
    }

    public function test_a_historic_loop_keeps_every_card_it_was_given(): void
    {
        // Loops predating the type presets had their four cards materialised by
        // migration 2026_08_04_090100. A Dialogue Loop among them must NOT be
        // reduced to Membres — the effective composition wins over the preset.
        $loop = $this->loop('general', applyPreset: false);

        foreach (array_keys(config('loop_cards.cards')) as $key) {
            LoopCard::create([
                'organization_id' => $this->org->id, 'loop_id' => $loop->id,
                'card_key' => $key, 'enabled' => true,
            ]);
        }

        $response = $this->workspace($loop)->assertOk();

        foreach (array_keys(config('loop_cards.cards')) as $key) {
            $response->assertSee($this->label($key));
        }
    }

    public function test_an_unknown_card_key_never_breaks_the_workspace(): void
    {
        $loop = $this->loop('general');
        LoopCard::create([
            'organization_id' => $this->org->id, 'loop_id' => $loop->id,
            'card_key' => 'core.does_not_exist', 'enabled' => true,
        ]);

        $this->workspace($loop)->assertOk()->assertDontSee('core.does_not_exist');
    }

    public function test_chatloop_stays_present_whatever_the_composition(): void
    {
        // ChatLoop is not a card and is never withdrawn by composition.
        $loop = $this->loop('general');
        LoopCard::where('loop_id', $loop->id)->update(['enabled' => false]);

        $this->workspace($loop)->assertOk()->assertSeeHtml('data-loop-workspace-chat');
    }

    public function test_rendering_the_workspace_writes_nothing(): void
    {
        $loop = $this->loop('project');
        $before = LoopCard::where('loop_id', $loop->id)->get(['card_key', 'enabled'])->toArray();

        $this->workspace($loop)->assertOk();

        $this->assertSame($before, LoopCard::where('loop_id', $loop->id)->get(['card_key', 'enabled'])->toArray());
    }

    // ── Permissions et rôles ────────────────────────────────────────────────

    public function test_every_canonical_role_sees_the_cards_of_its_loop(): void
    {
        $loop = $this->loop('project');

        foreach (['owner', 'facilitator', 'member'] as $role) {
            $user = User::factory()->create(['organization_id' => $this->org->id]);
            LoopMember::factory()->create([
                'loop_id' => $loop->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
            ]);

            $this->workspace($loop, $user)->assertOk()->assertSee($this->label('core.members'));
        }
    }

    public function test_a_non_member_gets_the_presentation_not_the_cards(): void
    {
        $loop = $this->loop('project');
        $loop->update(['visibility' => 'public']);
        $outsider = User::factory()->create(['organization_id' => $this->org->id]);

        $this->workspace($loop, $outsider)->assertOk()
            ->assertDontSeeHtml('data-loop-workspace-panel');
    }

    public function test_a_user_of_another_organization_is_refused(): void
    {
        $loop = $this->loop('project');
        $otherOrg = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->workspace($loop, $stranger)->assertNotFound();
    }
}
