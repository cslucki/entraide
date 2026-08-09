<?php

namespace App\Support\Loops;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\LoopCard;
use App\Models\LoopRoadmapItem;
use App\Services\LoopTypeSettingsService;
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
    /**
     * Types that may be chosen — in a creation form, or when reassigning a
     * Loop.
     *
     * An unavailable type is withheld from choices, never hidden from the
     * admin and never taken away from the Loops that already carry it: it is a
     * type under construction, not a deleted one.
     *
     * @return array<string, array<string, mixed>> keyed by type
     */
    public function available(): array
    {
        return array_filter($this->all(), fn ($_, $key) => $this->isAvailable($key), ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<int, string> */
    public function availableKeys(): array
    {
        return array_keys($this->available());
    }

    public function isAvailable(?string $type, ?Organization $organization = null): bool
    {
        return $this->exists($type) && app(LoopTypeSettingsService::class)->isAvailable($type, $organization);
    }

    /**
     * Types offered to someone, keeping whatever the Loop already carries.
     *
     * Reassigning a Loop must never silently move it off an unavailable type
     * just because the form could not show it, so its current type stays in the
     * list — marked, but present.
     *
     * @return array<string, array<string, mixed>> keyed by type
     */
    public function selectableFor(?string $currentType): array
    {
        $types = $this->available();
        $current = $this->resolve($currentType);

        if (! isset($types[$current]) && $this->exists($current)) {
            $types[$current] = $this->all()[$current];
        }

        uasort($types, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $types;
    }

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

    /**
     * Ce type peut-il etre **assigne** a cette Boucle ?
     *
     * Un type retire des choix ne s'assigne pas. Mais **garder celui que la
     * Boucle porte deja n'est pas une assignation** : sans cette nuance, un
     * formulaire d'edition refuserait d'enregistrer le nom ou la description
     * d'une Boucle dont le type a ete ferme entre-temps.
     *
     * La regle vivait dans un seul controleur (`AdminLoopController`), et
     * manquait aux deux autres chemins. Elle est ici, **au registre**, pour que
     * le prochain appelant n'ait rien a redecouvrir.
     */
    public function isAssignableTo(?string $wanted, ?string $current): bool
    {
        if (! $this->exists($wanted)) {
            return false;
        }

        if ($this->isAvailable($wanted)) {
            return true;
        }

        // **Comparaison sur les valeurs brutes, jamais sur `resolve()`.**
        //
        // `resolve()` confond deux choses : « alias de » et « repli sur le
        // defaut ». Toute valeur stockee hors catalogue — `custom` (le defaut
        // de la colonne), `ai_agent`, `system` — y retombe sur `general`. Une
        // Boucle Agent IA « portait » donc `general` sans l'avoir jamais porte,
        // et fermer `general` ne la protegeait plus : les trois chemins gardes
        // ecrivaient ce type ferme. Ce n'est pas la nuance « garder le sien »,
        // c'est une assignation, et elle annulait l'objet de la garde.
        if ($current === $wanted) {
            return true;
        }

        // L'alias, lui, est une vraie equivalence : `custom` **est** `general`.
        return (config('loop_types.legacy_aliases', [])[$current] ?? null) === $wanted;
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

    /**
     * Le nom **affiche** d'un type.
     *
     * Il peut etre surcharge — globalement, ou pour une seule Organization —
     * sans que la **cle technique** bouge jamais. `key = training` reste
     * `training` : c'est elle que portent `loops.type`, les presets et les
     * permissions.
     *
     * Sans override, la traduction declaree en configuration.
     */
    public function label(?string $type, ?Organization $organization = null): string
    {
        $resolu = $this->resolve($type);

        $override = app(LoopTypeSettingsService::class)->labelFor($resolu, $organization);

        if ($override !== null) {
            return $override;
        }

        $definition = $this->definition($type);

        return $definition ? __($definition['label_key']) : (string) $type;
    }

    /** Meme regle pour la description. */
    public function description(?string $type, ?Organization $organization = null): string
    {
        $resolu = $this->resolve($type);

        $override = app(LoopTypeSettingsService::class)->descriptionFor($resolu, $organization);

        if ($override !== null) {
            return $override;
        }

        $definition = $this->definition($type);

        return $definition && isset($definition['description_key']) ? __($definition['description_key']) : '';
    }

    /**
     * The name this type gives to the root document.
     *
     * Same concept everywhere, different word: Manifeste for a project, Cadre
     * du dialogue for a Dialogue Loop, Programme for a Formation. Read here so
     * that no controller and no view ever branches on $loop->type.
     */
    public function rootDocumentLabel(?string $type): string
    {
        $key = $this->definition($type)['root_document_label_key'] ?? null;

        return $key ? __($key) : __('loops.root_document.general');
    }

    /**
     * Le nom que ce type donne a la Roadmap.
     *
     * **Une seule Card technique**, plusieurs vocabulaires : « Roadmap » pour un
     * Projet, « Engagements » pour une Pair-aidance, « Suivi de coaching » pour
     * un Coaching. La spec produit le dit explicitement — ce sont des presets de
     * vocabulaire et de colonnes, **pas des Cards distinctes**.
     *
     * Lu ici, comme `rootDocumentLabel()`, pour qu'aucune vue et aucun service
     * ne teste `$loop->type`.
     */
    public function roadmapLabel(?string $type): string
    {
        $key = $this->definition($type)['roadmap_label_key'] ?? null;

        return $key ? __($key) : __('loops.cards.roadmap.label');
    }

    /**
     * Le nom que ce type donne a chacune des trois colonnes.
     *
     * Les **statuts ne changent pas** — `todo`, `in_progress`, `done` restent
     * les memes en base, et un item deplace d'un preset a l'autre garde le
     * sien. Seul le mot change : « A faire » devient « Pris », « Fait » devient
     * « Tenu ». Renommer en base aurait fait deux verites sur le meme etat.
     *
     * @return array<string, string> statut => libelle traduit
     */
    public function roadmapColumnLabels(?string $type): array
    {
        $declares = $this->definition($type)['roadmap_column_keys'] ?? [];

        $defauts = [
            LoopRoadmapItem::STATUS_TODO => 'loops.roadmap_status_todo',
            LoopRoadmapItem::STATUS_IN_PROGRESS => 'loops.roadmap_status_in_progress',
            LoopRoadmapItem::STATUS_DONE => 'loops.roadmap_status_done',
        ];

        $out = [];

        foreach ($defauts as $statut => $defaut) {
            $out[$statut] = __($declares[$statut] ?? $defaut);
        }

        return $out;
    }

    /**
     * Translation keys of the sections the starting template lays out.
     *
     * A template, not a form: none of these sections has to be filled in for
     * the document to be usable.
     *
     * @return array<int, string>
     */
    public function rootDocumentSections(?string $type): array
    {
        return $this->definition($type)['root_document_sections'] ?? [];
    }

    /**
     * Card keys a type prescribes, filtered to those the card catalogue really
     * ships. A preset may name a card ahead of its implementation; it simply
     * has no effect until that card exists.
     *
     * @return array<int, string>
     */
    public function cardsFor(?string $type, ?Organization $organization = null): array
    {
        // Through the settings service, never straight from config: the
        // super-admin composes types from /admin/loop-types, and the saved
        // preset is what a new Loop must be built from.
        return app(LoopTypeSettingsService::class)->cardsFor($this->resolve($type), $organization);
    }

    /**
     * Human label of a card key.
     *
     * Array access rather than config() dot-notation: keys contain a dot
     * ("core.manifesto"), which dot-notation would split into a nested lookup.
     */
    public function cardLabel(string $cardKey): string
    {
        return app(LoopCardRegistry::class)->label($cardKey);
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
        // L'Organization vient de la **Boucle**, jamais de la requete : c'est
        // la regle posee par TASK-1103 et reappliquee depuis.
        $wanted = $this->cardsFor($loop->type, $loop->organization);

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
            $keys = $this->cardsFor($loop->type, $loop->organization);
        }

        $registry = app(LoopCardRegistry::class);

        return collect($keys)
            ->filter(fn ($k) => $registry->exists($k))
            ->sortBy(fn ($k) => $registry->get($k)['order'] ?? 0)
            ->values()
            ->all();
    }
}
