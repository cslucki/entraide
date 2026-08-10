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

    // ── Placement (TASK-1090) ───────────────────────────────────────────────

    public const PLACEMENT_GRID = 'grid';

    public const PLACEMENT_FRAME = 'frame';

    public const PLACEMENT_CHAT_ACTION = 'chat_action';

    /**
     * Ou une Card vit a l'ecran.
     *
     * `grid` par defaut : une Card qui ne dit rien de son placement est un outil
     * ordinaire, et c'est le cas le plus frequent.
     */
    public function placementOf(string $key): string
    {
        $placement = $this->get($key)['placement'] ?? self::PLACEMENT_GRID;

        return in_array($placement, [self::PLACEMENT_GRID, self::PLACEMENT_FRAME, self::PLACEMENT_CHAT_ACTION], true)
            ? $placement
            : self::PLACEMENT_GRID;
    }

    /**
     * Le nombre de Cards que la zone principale accepte.
     *
     * Trois : au-dela, la barre cesse d'annoncer une intention.
     */
    public function gridSlots(): int
    {
        return max(1, (int) config('loop_cards.grid_slots', 3));
    }

    /**
     * Les Cards du cadre permanent — presentes partout, hors de la grille.
     *
     * Elles restent declarees, gardees par leurs permissions et comptees par
     * l'administration : seule leur place a l'ecran change.
     *
     * @return array<int, string>
     */
    public function frameKeys(): array
    {
        return $this->keysWithPlacement(self::PLACEMENT_FRAME);
    }

    /** @return array<int, string> */
    public function gridKeys(): array
    {
        return $this->keysWithPlacement(self::PLACEMENT_GRID);
    }

    /** @return array<int, string> */
    public function chatActionKeys(): array
    {
        return $this->keysWithPlacement(self::PLACEMENT_CHAT_ACTION);
    }

    /** @return array<int, string> */
    private function keysWithPlacement(string $placement): array
    {
        return array_values(array_filter(
            $this->renderableKeys(),
            fn ($key) => $this->placementOf($key) === $placement,
        ));
    }

    // ── Dependances (TASK-1090) ─────────────────────────────────────────────

    /**
     * Les Cards dont celle-ci a besoin pour avoir un sens.
     *
     * Declarees dans le catalogue, jamais deduites du type : c'est ce qui permet
     * a une dependance de valoir dans toutes les Boucles, quel que soit leur
     * preset.
     *
     * @return array<int, string>
     */
    public function requirementsOf(string $key): array
    {
        return array_values(array_filter(
            (array) ($this->get($key)['requires'] ?? []),
            fn ($k) => is_string($k) && $this->exists($k),
        ));
    }

    /** @return array<int, string> */
    public function incompatibilitiesOf(string $key): array
    {
        return array_values(array_filter(
            (array) ($this->get($key)['incompatible_with'] ?? []),
            fn ($k) => is_string($k) && $this->exists($k),
        ));
    }

    /** Une Card remplacable peut etre echangee dans un emplacement distinctif. */
    public function isReplaceable(string $key): bool
    {
        return (bool) ($this->get($key)['replaceable'] ?? true);
    }

    public function categoryOf(string $key): string
    {
        return (string) ($this->get($key)['category'] ?? 'other');
    }

    public function scopeOf(string $key): string
    {
        return (string) ($this->get($key)['scope'] ?? 'universal');
    }

    /**
     * Ce qui empeche d'activer une Card ici, s'il y a quelque chose.
     *
     * Retourne les cles manquantes et les cles en conflit — de quoi expliquer un
     * refus plutot que de le subir. La verification vaut aussi pour une requete
     * forgee : c'est le service de composition qui l'appelle, pas la vue.
     *
     * @param  array<int, string>  $activeKeys
     * @return array{missing: array<int, string>, conflicting: array<int, string>}
     */
    public function blockersFor(string $key, array $activeKeys): array
    {
        $missing = array_values(array_diff($this->requirementsOf($key), $activeKeys));
        $conflicting = array_values(array_intersect($this->incompatibilitiesOf($key), $activeKeys));

        return ['missing' => $missing, 'conflicting' => $conflicting];
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

    /**
     * Le nom d'une Card **dans une Boucle donnee**.
     *
     * La Roadmap est une seule Card technique, mais chaque preset lui donne son
     * mot : « Roadmap » en Projet, « Engagements » en Pair-aidance, « Suivi de
     * coaching » en Coaching. La spec produit le pose ainsi — des presets de
     * vocabulaire, **pas des Cards distinctes**.
     *
     * Le detour passe par `LoopTypeRegistry`, qui lit une declaration : aucune
     * vue et aucun service ne teste `$loop->type`.
     */
    public function labelFor(Loop $loop, string $key): string
    {
        return $this->labelForType($loop->type, $key);
    }

    /**
     * Le meme nom, connu **du seul type**.
     *
     * L'ecran de choix du type annonce ce que chaque preset construit, avant
     * qu'aucune Boucle n'existe : il ne peut pas appeler `labelFor()`. Sans
     * cette variante il listait « Roadmap » pour la Pair-aidance, la ou la
     * matrice produit dit « Engagements » — c'est-a-dire au moment precis ou le
     * vocabulaire sert a decider.
     */
    public function labelForType(?string $type, string $key): string
    {
        if ($key === 'core.roadmap') {
            return app(LoopTypeRegistry::class)->roadmapLabel($type);
        }

        return $this->label($key);
    }

    public function description(string $key): string
    {
        $card = $this->get($key);

        return $card && isset($card['description_key']) ? __($card['description_key']) : '';
    }

    /**
     * The Cards a given person actually sees in a given Loop, ready to render.
     *
     * **Depuis TASK-1090, seules les Cards `grid` y figurent**, et trois au
     * maximum. Le cadre permanent — Manifeste, Membres — se lit par
     * frameCardsFor(), et le Resume IA par chatActionCardsFor().
     *
     * Read-only: nothing here writes to loop_cards.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function workspaceCardsFor(Loop $loop, User $user): \Illuminate\Support\Collection
    {
        // **Plus de plafond ici.** Le `take(gridSlots())` d'avant TASK-1124
        // masquait purement et simplement la 4e Card active : active en base,
        // ses donnees vivantes, et introuvable dans la Boucle. Le workspace
        // rend desormais tout ce qui est actif, et separe la mise en avant de
        // l'activation — voir primaryWorkspaceCardsFor() / secondary…().
        return $this->visibleCardsFor($loop, $user)
            ->filter(fn (array $card) => $this->placementOf($card['key']) === self::PLACEMENT_GRID)
            ->values();
    }

    /**
     * Les cles des Cards de grille **actives**, dans l'ordre du catalogue.
     *
     * L'ordre canonique dont derivent les outils principaux d'une Boucle qui
     * n'en a jamais choisi. Sans utilisateur : c'est la composition qui
     * compte, pas ce qu'une personne a le droit de lire.
     *
     * @return array<int, string>
     */
    public function activeGridKeysFor(Loop $loop): array
    {
        return collect(app(LoopTypeRegistry::class)->activeCardsFor($loop))
            ->filter(fn (string $key) => $this->exists($key)
                && $this->placementOf($key) === self::PLACEMENT_GRID)
            ->sortBy(fn (string $key) => $this->get($key)['order'] ?? 0)
            ->values()
            ->all();
    }

    /**
     * Les outils **mis en avant** que cette personne voit, dans l'ordre choisi.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function primaryWorkspaceCardsFor(Loop $loop, User $user): \Illuminate\Support\Collection
    {
        $principaux = app(\App\Services\Loops\LoopCardCompositionService::class)->primaryKeysFor($loop);
        $visibles = $this->workspaceCardsFor($loop, $user)->keyBy('key');

        return collect($principaux)
            ->map(fn (string $key) => $visibles->get($key))
            ->filter()
            ->values();
    }

    /**
     * Les autres outils actifs — accessibles, jamais masques.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function secondaryWorkspaceCardsFor(Loop $loop, User $user): \Illuminate\Support\Collection
    {
        $principaux = app(\App\Services\Loops\LoopCardCompositionService::class)->primaryKeysFor($loop);

        return $this->workspaceCardsFor($loop, $user)
            ->reject(fn (array $card) => in_array($card['key'], $principaux, true))
            ->values();
    }

    /**
     * Les Cards du cadre permanent que cette personne voit ici.
     *
     * Memes filtres que la grille — composition, renderer, permission de lecture
     * — mais sans plafond : le cadre n'est pas un emplacement qu'on se dispute.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function frameCardsFor(Loop $loop, User $user): \Illuminate\Support\Collection
    {
        return $this->visibleCardsFor($loop, $user)
            ->filter(fn (array $card) => $this->placementOf($card['key']) === self::PLACEMENT_FRAME)
            ->values();
    }

    /**
     * Les Cards qui vivent dans les actions IA de ChatLoop.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function chatActionCardsFor(Loop $loop, User $user): \Illuminate\Support\Collection
    {
        return $this->visibleCardsFor($loop, $user)
            ->filter(fn (array $card) => $this->placementOf($card['key']) === self::PLACEMENT_CHAT_ACTION)
            ->values();
    }

    /**
     * Le socle commun aux trois accesseurs : ce que cette personne peut voir de
     * la composition de cette Boucle, sans distinction de placement.
     *
     * Read-only : rien n'ecrit dans loop_cards.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function visibleCardsFor(Loop $loop, User $user): \Illuminate\Support\Collection
    {
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
