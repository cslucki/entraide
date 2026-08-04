<?php

namespace App\Services;

use App\Models\LoopTypeSetting;

/**
 * The only place that reads or writes what a Loop type is made of.
 *
 * Two things are administrable per type: its card preset, and whether it may be
 * chosen at all. Both default to config/loop_types.php and are overridden here
 * — so the super-admin can define a type's composition without a deployment,
 * and the configuration file still states the intent in code.
 *
 * Everything is normalised on the way in *and* on the way out: a payload edited
 * by hand in the database cannot smuggle an unknown card key into a preset, and
 * a key retired from the catalogue silently stops applying instead of breaking
 * a workspace.
 */
class LoopTypeSettingsService
{
    /**
     * Per-request memo. cardsFor() sits on hot paths — every workspace render
     * asks for it — and this is a handful of rows that cannot change within a
     * request that is not itself the one writing them.
     *
     * @var array<string, LoopTypeSetting|null>|null
     */
    private ?array $memo = null;

    // ── Lecture ─────────────────────────────────────────────────────────────

    /**
     * Card preset in effect for a type: the override if one was saved,
     * otherwise the configured default.
     *
     * @return array<int, string>
     */
    public function cardsFor(string $type): array
    {
        $override = $this->setting($type)?->cards;

        return $this->normalizeCards(
            $override ?? (config('loop_types.types.'.$type.'.cards') ?? []),
        );
    }

    /** True when the type may be chosen in a form or assigned to a Loop. */
    public function isAvailable(string $type): bool
    {
        $override = $this->setting($type)?->available;

        if ($override !== null) {
            return $override;
        }

        // A type that says nothing is available: only an explicit `false` in
        // the configuration withholds it.
        return (bool) (config('loop_types.types.'.$type.'.available') ?? true);
    }

    /** True when this type departs from its configured defaults. */
    public function isCustomised(string $type): bool
    {
        $setting = $this->setting($type);

        return $setting !== null && ($setting->cards !== null || $setting->available !== null);
    }

    // ── Écriture ────────────────────────────────────────────────────────────

    /**
     * Save a type's composition and availability.
     *
     * Passing the configured default is not an error — it simply stores no
     * override, which keeps the table sparse and lets a later change to
     * config/loop_types.php flow through.
     *
     * @param  array<int, string>  $cards
     */
    public function save(string $type, array $cards, bool $available): void
    {
        $cards = $this->normalizeCards($cards);
        $defaultCards = $this->normalizeCards(config('loop_types.types.'.$type.'.cards') ?? []);
        $defaultAvailable = (bool) (config('loop_types.types.'.$type.'.available') ?? true);

        $payload = [
            'cards' => $cards === $defaultCards ? null : $cards,
            'available' => $available === $defaultAvailable ? null : $available,
        ];

        $this->memo = null;

        if ($payload['cards'] === null && $payload['available'] === null) {
            LoopTypeSetting::where('loop_type', $type)->delete();

            return;
        }

        LoopTypeSetting::updateOrCreate(['loop_type' => $type], $payload);
    }

    /** Drop every override for a type, returning it to configuration. */
    public function reset(string $type): void
    {
        $this->memo = null;

        LoopTypeSetting::where('loop_type', $type)->delete();
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function setting(string $type): ?LoopTypeSetting
    {
        if ($this->memo === null) {
            $this->memo = LoopTypeSetting::all()->keyBy('loop_type')->all();
        }

        return $this->memo[$type] ?? null;
    }

    /**
     * Keep only keys the card catalogue really ships, in catalogue order.
     *
     * Array access rather than config() dot-notation: card keys contain a dot
     * ("core.manifesto"), which dot-notation would split into a nested lookup
     * that never resolves.
     *
     * @param  array<int, mixed>  $cards
     * @return array<int, string>
     */
    private function normalizeCards(array $cards): array
    {
        $catalogue = config('loop_cards.cards', []);

        return collect($cards)
            ->filter(fn ($key) => is_string($key) && isset($catalogue[$key]))
            ->unique()
            ->sortBy(fn ($key) => $catalogue[$key]['order'] ?? 0)
            ->values()
            ->all();
    }
}
