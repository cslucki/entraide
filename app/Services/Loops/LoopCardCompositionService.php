<?php

namespace App\Services\Loops;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\LoopEvent;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Support\Facades\DB;

/**
 * The only place that writes loop_cards.enabled.
 *
 * Two screens use it — the platform admin and the Organization admin — and
 * neither touches LoopCard directly, so the rules below cannot be bypassed by a
 * forgotten caller.
 *
 * The three states the engine already distinguishes are preserved exactly:
 *
 *   no row              never added
 *   enabled = true      active
 *   enabled = false     deliberately switched off here
 *
 * That last one is what makes local composition survive: applyPreset() compares
 * card keys without filtering on `enabled`, so a card switched off is never
 * switched back on by a preset synchronisation. This service must never break
 * that by deleting the row instead of flagging it.
 */
class LoopCardCompositionService
{
    public function __construct(private LoopTypeRegistry $types) {}

    /**
     * Cards the workspace can actually render.
     *
     * ChatLoop is absent by construction: it is not a card, it is never
     * switchable, and a Loop without conversation does not exist.
     *
     * @return array<int, string>
     */
    public function manageableKeys(): array
    {
        return app(LoopCardRegistry::class)->manageableKeys();
    }

    /**
     * The composition of one Loop, ready to display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function compositionFor(Loop $loop): array
    {
        $registry = app(LoopCardRegistry::class);
        $preset = $this->types->cardsFor($loop->type);
        $rows = LoopCard::where('loop_id', $loop->id)->get()->keyBy('card_key');
        $catalogue = config('loop_cards.cards', []);

        $out = [];

        foreach ($this->manageableKeys() as $key) {
            $row = $rows->get($key);
            $inPreset = in_array($key, $preset, true);

            $out[] = [
                'key' => $key,
                'label' => __($catalogue[$key]['label_key']),
                'description' => __($catalogue[$key]['description_key']),
                'enabled' => (bool) ($row?->enabled),
                'exists' => $row !== null,
                // Where it comes from, so an administrator can tell a type's
                // baseline from a deliberate local addition.
                'origin' => match (true) {
                    $row === null => 'available',
                    $row->added_by_preset !== null => 'preset',
                    default => 'local',
                },
                'in_preset' => $inPreset,
                // Never disable the last thing that makes a workspace usable.
                // Declared by the catalogue, not hard-coded here: a second
                // required Card would otherwise have to be remembered twice.
                'protected' => $registry->isRequired($key),
                'data_count' => $this->dataCount($loop, $key),
            ];
        }

        return $out;
    }

    /** Turn a card on, creating its row if it never existed. */
    public function enable(Loop $loop, string $key): void
    {
        $this->assertManageable($key);

        DB::transaction(function () use ($loop, $key) {
            $row = LoopCard::where('loop_id', $loop->id)->where('card_key', $key)->lockForUpdate()->first();

            if ($row) {
                // Only flip the flag: recreating the row would lose
                // added_by_preset and, with it, the card's origin.
                $row->forceFill(['enabled' => true])->save();

                return;
            }

            LoopCard::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'card_key' => $key,
                'enabled' => true,
                // Added here, not by a preset. Saying otherwise would make a
                // local decision look like the type's doing.
                'added_by_preset' => null,
            ]);
        });
    }

    /**
     * Turn a card off. Nothing is deleted, ever.
     *
     * The card leaves the workspace — TASK-1081 made the workspace read the
     * effective composition — and `requires_card` keeps refusing direct access.
     * Its content waits, untouched, for the card to come back.
     */
    public function disable(Loop $loop, string $key): void
    {
        $this->assertManageable($key);

        if (app(LoopCardRegistry::class)->isRequired($key)) {
            throw new \RuntimeException('This card is required and cannot be switched off.');
        }

        DB::transaction(function () use ($loop, $key) {
            $row = LoopCard::where('loop_id', $loop->id)->where('card_key', $key)->lockForUpdate()->first();

            // A card that was never added is already off. Creating a disabled
            // row would only add noise.
            $row?->forceFill(['enabled' => false])->save();
        });
    }

    /**
     * How much a card already holds, so switching it off is never a surprise.
     *
     * Bounded on purpose: a per-card lookup, not a generic introspection
     * engine. A card the catalogue gains later simply reports null until
     * someone adds its case here.
     */
    private function dataCount(Loop $loop, string $key): ?int
    {
        return match ($key) {
            'core.members' => LoopMember::where('loop_id', $loop->id)->where('status', 'active')->count(),
            'core.roadmap' => LoopRoadmapItem::where('loop_id', $loop->id)->count(),
            'core.ai_summary' => $loop->messages()->where('type', 'ai')->count(),
            'core.manifesto' => $this->rootDocumentExists($loop) ? 1 : 0,
            'core.polls' => LoopPoll::where('loop_id', $loop->id)->count(),
            'core.events' => LoopEvent::where('loop_id', $loop->id)->count(),
            default => null,
        };
    }

    private function rootDocumentExists(Loop $loop): bool
    {
        $id = Dossier::where('loop_id', $loop->id)->value('root_blog_post_id') ?? $loop->manifesto_blog_post_id;

        return $id !== null && BlogPost::whereKey($id)->exists();
    }

    private function assertManageable(string $key): void
    {
        if (! in_array($key, $this->manageableKeys(), true)) {
            throw new \RuntimeException('Unknown or unmanageable card key.');
        }
    }
}
