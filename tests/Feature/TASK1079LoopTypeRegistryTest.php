<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Loop type registry and its card presets.
 *
 * The rule under test throughout: a type is a starting point, never a cage.
 * Applying a preset adds what is missing and removes nothing — so a Loop can
 * move between types without ever losing a card or its content.
 */
class TASK1079LoopTypeRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    private function loop(string $type = 'general'): Loop
    {
        $org = Organization::factory()->create(['loops_enabled' => true]);

        return Loop::factory()->create(['organization_id' => $org->id, 'type' => $type]);
    }

    // ── Registry ────────────────────────────────────────────────────────────

    public function test_the_four_business_types_are_available_and_ordered(): void
    {
        $this->assertSame(
            ['general', 'project', 'training', 'peer_support'],
            $this->registry()->keys(),
        );
    }

    public function test_an_unknown_type_falls_back_to_the_default(): void
    {
        $this->assertFalse($this->registry()->exists('nope'));
        $this->assertSame('general', $this->registry()->resolve('nope'));
        $this->assertSame('general', $this->registry()->resolve(null));
    }

    public function test_the_legacy_custom_value_reads_as_general_without_being_migrated(): void
    {
        $loop = $this->loop('custom');

        $this->assertSame('general', $this->registry()->resolve($loop->type));
        // No backfill: the stored value is left exactly as it was.
        $this->assertSame('custom', $loop->fresh()->type);
    }

    public function test_a_preset_only_names_cards_the_catalogue_really_ships(): void
    {
        $catalogue = array_keys(config('loop_cards.cards'));

        foreach ($this->registry()->keys() as $type) {
            foreach ($this->registry()->cardsFor($type) as $card) {
                $this->assertContains($card, $catalogue, "Type {$type} references a card that does not exist");
            }
        }
    }

    public function test_every_type_includes_the_members_card_and_is_never_empty(): void
    {
        // The Manifesto used to be asserted here as universal. It is not any
        // more: a Dialogue Loop is a conversation and carries Membres alone
        // (CP5ter-E2). What still holds is that no type composes an empty
        // workspace, and that every one of them knows its members.
        foreach ($this->registry()->keys() as $type) {
            $cards = $this->registry()->cardsFor($type);

            $this->assertNotEmpty($cards, "Type {$type} composes an empty workspace");
            $this->assertContains('core.members', $cards, "Type {$type} has no members card");
        }
    }

    public function test_the_two_defined_types_carry_exactly_what_was_specified(): void
    {
        $this->assertSame(['core.members'], $this->registry()->cardsFor('general'));
        $this->assertEqualsCanonicalizing(
            ['core.ai_summary', 'core.manifesto', 'core.roadmap', 'core.members'],
            $this->registry()->cardsFor('project'),
        );
    }

    public function test_only_the_defined_types_may_be_chosen(): void
    {
        $this->assertSame(['general', 'project'], $this->registry()->availableKeys());
        $this->assertFalse($this->registry()->isAvailable('training'));
        $this->assertFalse($this->registry()->isAvailable('peer_support'));
    }

    public function test_a_loop_keeps_its_unavailable_type_in_the_choices(): void
    {
        // Otherwise reassigning anything else on the form would silently move a
        // Formation Loop off its type just because it could not be displayed.
        $this->assertArrayHasKey('training', $this->registry()->selectableFor('training'));
        $this->assertArrayNotHasKey('training', $this->registry()->selectableFor('general'));
    }

    // ── Application du preset ───────────────────────────────────────────────

    public function test_applying_a_preset_creates_the_cards(): void
    {
        $loop = $this->loop('project');

        $added = $this->registry()->applyPreset($loop);

        $this->assertEqualsCanonicalizing($this->registry()->cardsFor('project'), $added);
        $this->assertSame(count($added), LoopCard::where('loop_id', $loop->id)->count());
        $this->assertSame('project', LoopCard::where('loop_id', $loop->id)->first()->added_by_preset);
    }

    public function test_applying_a_preset_twice_adds_nothing_the_second_time(): void
    {
        $loop = $this->loop('project');

        $first = $this->registry()->applyPreset($loop);
        $second = $this->registry()->applyPreset($loop);

        $this->assertNotEmpty($first);
        $this->assertSame([], $second);
        $this->assertSame(count($first), LoopCard::where('loop_id', $loop->id)->count());
    }

    public function test_changing_type_never_removes_an_existing_card(): void
    {
        $loop = $this->loop('project');
        $this->registry()->applyPreset($loop);
        $before = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->all();

        // peer_support prescribes strictly fewer cards than project.
        $loop->update(['type' => 'peer_support']);
        $this->registry()->applyPreset($loop);

        $after = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->all();
        foreach ($before as $card) {
            $this->assertContains($card, $after, "Card {$card} was lost on type change");
        }
    }

    public function test_a_card_added_by_a_human_survives_every_type_change(): void
    {
        $loop = $this->loop('peer_support');
        $this->registry()->applyPreset($loop);
        LoopCard::create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'card_key' => 'core.roadmap',
            'enabled' => true,
            'added_by_preset' => null,
        ]);

        foreach (['general', 'project', 'training', 'peer_support'] as $type) {
            $loop->update(['type' => $type]);
            $this->registry()->applyPreset($loop);
        }

        $custom = LoopCard::where('loop_id', $loop->id)->where('card_key', 'core.roadmap')->get();
        $this->assertCount(1, $custom, 'The human-added card was duplicated or lost');
        $this->assertNull($custom->first()->added_by_preset);
    }

    public function test_the_full_type_rotation_loses_nothing_and_duplicates_nothing(): void
    {
        $loop = $this->loop('general');
        $this->registry()->applyPreset($loop);

        foreach (['project', 'training', 'peer_support', 'general'] as $type) {
            $loop->update(['type' => $type]);
            $this->registry()->applyPreset($loop);
        }

        $keys = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->all();

        $this->assertSame(count($keys), count(array_unique($keys)), 'A card was duplicated');
        // The union of every preset crossed on the way must all still be there.
        $expected = collect(['general', 'project', 'training', 'peer_support'])
            ->flatMap(fn ($t) => $this->registry()->cardsFor($t))->unique()->values()->all();
        $this->assertEqualsCanonicalizing($expected, $keys);
    }

    // ── Composition effective ───────────────────────────────────────────────

    public function test_active_cards_are_returned_in_catalogue_order(): void
    {
        $loop = $this->loop('general');
        $this->registry()->applyPreset($loop);

        $active = $this->registry()->activeCardsFor($loop);
        // Array access: card keys contain a dot, so config() dot-notation would
        // return null for every one of them and this assertion would pass on
        // nothing at all.
        $catalogue = config('loop_cards.cards');
        $orders = array_map(fn ($k) => $catalogue[$k]['order'], $active);

        $this->assertNotEmpty($orders);
        $this->assertNotContains(null, $orders);
        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders);
    }

    public function test_a_loop_predating_the_cards_table_falls_back_to_its_preset(): void
    {
        $loop = $this->loop('custom');

        // No rows at all — Loops created before TASK-1079 must not render empty.
        $this->assertSame(0, LoopCard::where('loop_id', $loop->id)->count());
        $this->assertNotEmpty($this->registry()->activeCardsFor($loop));
    }

    public function test_a_disabled_card_is_not_active(): void
    {
        $loop = $this->loop('general');
        $this->registry()->applyPreset($loop);
        LoopCard::where('loop_id', $loop->id)->where('card_key', 'core.roadmap')->update(['enabled' => false]);

        $this->assertNotContains('core.roadmap', $this->registry()->activeCardsFor($loop->fresh()));
    }

    public function test_card_labels_resolve_instead_of_showing_raw_keys(): void
    {
        $loop = $this->loop('general');
        $this->registry()->applyPreset($loop);

        foreach (LoopCard::where('loop_id', $loop->id)->get() as $card) {
            // Surfaced in the browser: the admin screens showed "core.manifesto"
            // because config() dot-notation splits on the dot inside the key.
            $this->assertNotSame($card->card_key, $card->label(), "Card {$card->card_key} renders as its raw key");
            $this->assertNotSame($card->card_key, $this->registry()->cardLabel($card->card_key));
        }
    }

    public function test_a_card_key_removed_from_the_catalogue_does_not_break_anything(): void
    {
        $loop = $this->loop('general');
        LoopCard::create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'card_key' => 'core.retired_card',
            'enabled' => true,
        ]);

        $this->assertNotContains('core.retired_card', $this->registry()->activeCardsFor($loop));
    }
}
