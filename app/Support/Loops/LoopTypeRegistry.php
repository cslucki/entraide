<?php

namespace App\Support\Loops;

use App\Models\Loop;
use App\Models\LoopCard;
use Illuminate\Support\Facades\DB;

/**
 * Central authority on Loop types and the card composition they imply.
 *
 * Everything a type is lives in config/loop_types.php; this class is the only
 * thing that reads it. Controllers, views, policies and admin screens ask here
 * rather than growing their own `match ($loop->type)` — which is what makes a
 * fifth type a one-file change later on.
 */
class LoopTypeRegistry
{
    /** @return array<string, array<string, mixed>> keyed by type */
    public function all(): array
    {
        $types = config('loop_types.types', []);

        uasort($types, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $types;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function default(): string
    {
        return config('loop_types.default', 'general');
    }

    public function exists(?string $type): bool
    {
        return $type !== null && array_key_exists($type, config('loop_types.types', []));
    }

    /**
     * Resolve a stored value to a real type.
     *
     * Existing rows carry `custom`, from before typed Loops existed. It is read
     * as `general` rather than migrated: no backfill, no destructive rewrite,
     * and nothing breaks if a row still holds an unknown value.
     */
    public function resolve(?string $type): string
    {
        if ($this->exists($type)) {
            return $type;
        }

        $alias = config('loop_types.legacy_aliases', [])[$type] ?? null;

        return $this->exists($alias) ? $alias : $this->default();
    }

    /** @return array<string, mixed>|null */
    public function definition(?string $type): ?array
    {
        return config('loop_types.types.'.$this->resolve($type));
    }

    public function label(?string $type): string
    {
        $definition = $this->definition($type);

        return $definition ? __($definition['label_key']) : (string) $type;
    }

    /**
     * Card keys a type prescribes, filtered to those the card catalogue really
     * ships. A preset may name a card ahead of its implementation; it simply
     * has no effect until that card exists.
     *
     * @return array<int, string>
     */
    public function cardsFor(?string $type): array
    {
        $catalogue = array_keys(config('loop_cards.cards', []));

        return array_values(array_intersect(
            $this->definition($type)['cards'] ?? [],
            $catalogue,
        ));
    }

    /**
     * Human label of a card key.
     *
     * Array access rather than config() dot-notation: keys contain a dot
     * ("core.manifesto"), which dot-notation would split into a nested lookup.
     */
    public function cardLabel(string $cardKey): string
    {
        $definition = config('loop_cards.cards', [])[$cardKey] ?? null;

        return $definition ? __($definition['label_key']) : $cardKey;
    }

    /**
     * Apply a type's preset to a Loop: add what is missing, remove nothing.
     *
     * Idempotent — running it twice adds nothing the second time — and
     * deliberately additive: a card a Loop already has, whether from an earlier
     * type or added by a human, survives every type change, content included.
     *
     * @return array<int, string> the card keys actually added, for reporting
     */
    public function applyPreset(Loop $loop): array
    {
        $wanted = $this->cardsFor($loop->type);

        if ($wanted === []) {
            return [];
        }

        return DB::transaction(function () use ($loop, $wanted) {
            $existing = LoopCard::where('loop_id', $loop->id)
                ->lockForUpdate()
                ->pluck('card_key')
                ->all();

            $missing = array_values(array_diff($wanted, $existing));

            foreach ($missing as $key) {
                LoopCard::create([
                    'organization_id' => $loop->organization_id,
                    'loop_id' => $loop->id,
                    'card_key' => $key,
                    'enabled' => true,
                    'added_by_preset' => $this->resolve($loop->type),
                ]);
            }

            return $missing;
        });
    }

    /**
     * Cards a Loop actually has, in catalogue order.
     *
     * Falls back to the type preset when the Loop has no rows yet: Loops created
     * before this table existed must not render an empty workspace.
     *
     * @return array<int, string>
     */
    public function activeCardsFor(Loop $loop): array
    {
        $keys = $loop->relationLoaded('cards')
            ? $loop->cards->where('enabled', true)->pluck('card_key')->all()
            : LoopCard::where('loop_id', $loop->id)->where('enabled', true)->pluck('card_key')->all();

        if ($keys === []) {
            $keys = $this->cardsFor($loop->type);
        }

        $catalogue = config('loop_cards.cards', []);

        return collect($keys)
            ->filter(fn ($k) => isset($catalogue[$k]))
            ->sortBy(fn ($k) => $catalogue[$k]['order'] ?? 0)
            ->values()
            ->all();
    }
}
