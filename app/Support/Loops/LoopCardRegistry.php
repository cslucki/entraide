<?php

namespace App\Support\Loops;

use App\Models\Loop;
use App\Models\User;

/**
 * The single declaration of what a Card is.
 *
 * Until now a Card was declared in three places that nothing kept in step:
 * `config/loop_cards.rendered`, `LoopController::RENDERED_CARDS`, and a chain of
 * `@if ($card['key'] === ...)` in loops/show.blade.php. All three happened to
 * agree on the same four keys; nothing made them. A fifth Card added to two of
 * them would have passed review and opened on an empty panel in production —
 * which is exactly the bill Sondage and Evenements were about to pay.
 *
 * One catalogue now answers every question: which Cards exist, which can be
 * rendered, which Livewire component renders them, which permission gates their
 * content, and which may never be switched off.
 *
 * This is a catalogue, not a plugin engine. Component names come from
 * configuration written by developers and are checked against it before use:
 * nothing a user submits can name a component, and a key nobody declared is
 * simply not rendered.
 */
class LoopCardRegistry
{
    /**
     * Every declared Card, in display order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $cards = config('loop_cards.cards', []);

        uasort($cards, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $cards;
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        // Array access, never config() dot-notation: card keys contain a dot,
        // which dot-notation would split into a nested lookup that never
        // resolves.
        return config('loop_cards.cards', [])[$key] ?? null;
    }

    public function exists(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Cards that have a renderer today.
     *
     * A Card declared with neither `component` nor `view` is a Card announced
     * ahead of its implementation: it stays in the catalogue, is never offered
     * for activation, and renders nothing. That is the whole mechanism that used
     * to need a hand-maintained `rendered` list.
     *
     * @return array<int, string>
     */
    public function renderableKeys(): array
    {
        return array_keys(array_filter(
            $this->all(),
            fn ($card) => ! empty($card['component']) || ! empty($card['view']),
        ));
    }

    public function isRenderable(string $key): bool
    {
        return in_array($key, $this->renderableKeys(), true);
    }

    /**
     * The Livewire component that renders a Card, or null when there is none.
     *
     * The name is read from the catalogue and re-checked against it, so the only
     * strings that ever reach Livewire are ones a developer wrote in
     * config/loop_cards.php. An unknown key returns null and the view renders
     * nothing — no 500, no arbitrary component.
     */
    public function componentFor(string $key): ?string
    {
        $component = $this->get($key)['component'] ?? null;

        return is_string($component) && $component !== '' ? $component : null;
    }

    /**
     * The Blade view that renders a Card, for the Cards that are markup rather
     * than a Livewire component.
     *
     * Same guarantee as componentFor(): the name comes from the catalogue and is
     * re-checked against it, so no user-supplied string ever reaches @include.
     */
    public function viewFor(string $key): ?string
    {
        $view = $this->get($key)['view'] ?? null;

        return is_string($view) && $view !== '' ? $view : null;
    }

    /**
     * The permission that gates a Card's *content*, distinct from having the
     * Card at all.
     *
     * A Card that declares none is readable by any active member: its presence
     * in the composition is the whole decision.
     */
    public function viewPermissionFor(string $key): ?string
    {
        $permission = $this->get($key)['view_permission'] ?? null;

        return is_string($permission) && $permission !== '' ? $permission : null;
    }

    /** A required Card can never be switched off. */
    public function isRequired(string $key): bool
    {
        return (bool) ($this->get($key)['required'] ?? false);
    }

    /**
     * Keys an administrator may switch on or off.
     *
     * ChatLoop is absent by construction: it is not a Card, it is never
     * switchable, and a Loop without conversation does not exist.
     *
     * @return array<int, string>
     */
    public function manageableKeys(): array
    {
        return $this->renderableKeys();
    }

    /**
     * The catalogue an administration screen may offer, keyed by card.
     *
     * Restricted to renderable Cards: a preset or a local composition must never
     * be able to name a Card that opens on nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    public function manageableCatalogue(): array
    {
        return array_intersect_key($this->all(), array_flip($this->manageableKeys()));
    }

    public function label(string $key): string
    {
        $card = $this->get($key);

        return $card ? __($card['label_key']) : $key;
    }

    public function description(string $key): string
    {
        $card = $this->get($key);

        return $card && isset($card['description_key']) ? __($card['description_key']) : '';
    }

    /**
     * The Cards a given person actually sees in a given Loop, ready to render.
     *
     * Three filters, in this order:
     *   1. the Loop's effective composition (LoopTypeRegistry::activeCardsFor) ;
     *   2. the existence of a renderer, so no button opens on nothing ;
     *   3. the read permission, so nothing is offered that the resolver refuses.
     *
     * Read-only: nothing here writes to loop_cards.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function workspaceCardsFor(Loop $loop, User $user): \Illuminate\Support\Collection
    {
        // Eager-load once: activeCardsFor() uses the loaded relation when it is
        // there, and the resolver calls it again for every card it gates.
        $loop->loadMissing('cards');

        $resolver = app(LoopPermissionResolver::class);

        return collect(app(LoopTypeRegistry::class)->activeCardsFor($loop))
            ->filter(fn ($key) => $this->isRenderable($key))
            ->filter(function ($key) use ($resolver, $user, $loop) {
                $permission = $this->viewPermissionFor($key);

                return $permission === null || $resolver->can($user, $loop, $permission);
            })
            ->map(fn ($key) => $this->get($key))
            ->values();
    }
}
