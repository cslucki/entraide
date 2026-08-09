<?php

namespace App\Services;

use App\Models\LoopTypeSetting;
use App\Models\Organization;
use App\Support\Loops\LoopCardRegistry;

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
    public function cardsFor(string $type, ?Organization $organization = null): array
    {
        $override = $this->setting($type, $organization)?->cards;

        return $this->normalizeCards(
            $override ?? (config('loop_types.types.'.$type.'.cards') ?? []),
        );
    }

    /** True when the type may be chosen in a form or assigned to a Loop. */
    public function isAvailable(string $type, ?Organization $organization = null): bool
    {
        $override = $this->setting($type, $organization)?->available;

        if ($override !== null) {
            return $override;
        }

        // A type that says nothing is available: only an explicit `false` in
        // the configuration withholds it.
        return (bool) (config('loop_types.types.'.$type.'.available') ?? true);
    }

    /** True when this type departs from its configured defaults. */
    public function isCustomised(string $type, ?Organization $organization = null): bool
    {
        $setting = $this->setting($type, $organization);

        return $setting !== null && ($setting->cards !== null || $setting->available !== null);
    }

    /**
     * Cette Organization a-t-elle son propre reglage pour ce type ?
     *
     * Distinct de `isCustomised()`, qui repond « ce type s'ecarte de sa
     * configuration » sans dire **a quel niveau**. L'ecran doit pouvoir
     * afficher « herite » ou « personnalise », et ce n'est pas la meme
     * question.
     */
    public function hasOrganizationOverride(string $type, Organization $organization): bool
    {
        return $this->rawSetting($type, $organization) !== null;
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
    public function save(string $type, array $cards, bool $available, ?Organization $organization = null): void
    {
        $cards = $this->normalizeCards($cards);

        // **La reference n'est pas la meme selon la portee.** Un reglage
        // d'Organization qui reproduit le niveau Plateforme n'a rien a stocker :
        // le comparer a la configuration du fichier ecrirait un override
        // inutile, et ce type cesserait alors de suivre les changements de la
        // Plateforme.
        $refCards = $organization
            ? $this->cardsFor($type)
            : $this->normalizeCards(config('loop_types.types.'.$type.'.cards') ?? []);

        $refAvailable = $organization
            ? $this->isAvailable($type)
            : (bool) (config('loop_types.types.'.$type.'.available') ?? true);

        $payload = [
            'cards' => $cards === $refCards ? null : $cards,
            'available' => $available === $refAvailable ? null : $available,
        ];

        $this->memo = null;

        if ($payload['cards'] === null && $payload['available'] === null) {
            $this->deleteSetting($type, $organization);

            return;
        }

        LoopTypeSetting::updateOrCreate(
            ['loop_type' => $type, 'organization_id' => $organization?->id],
            $payload,
        );
    }

    /**
     * Revenir aux reglages du niveau au-dessus.
     *
     * **Ne touche que la portee demandee** : reinitialiser une Organization ne
     * defait rien chez sa voisine ni au niveau Plateforme. Et cela ne change
     * **jamais** la composition effective d'une Boucle existante — celle-ci vit
     * dans `loop_cards`, que ce service n'ecrit pas.
     */
    public function reset(string $type, ?Organization $organization = null): void
    {
        $this->memo = null;

        $this->deleteSetting($type, $organization);
    }

    private function deleteSetting(string $type, ?Organization $organization): void
    {
        $requete = LoopTypeSetting::where('loop_type', $type);

        $organization
            ? $requete->where('organization_id', $organization->id)
            : $requete->whereNull('organization_id');

        $requete->delete();
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /**
     * Le reglage qui **s'applique** : celui de l'Organization s'il existe,
     * sinon celui de la Plateforme.
     *
     * C'est la meme grammaire que les permissions : l'absence de valeur veut
     * dire « le niveau au-dessus ». Sans Organization, seul le niveau
     * Plateforme est consulte — un appelant qui n'a pas de contexte tenant ne
     * doit pas heriter d'une Organization au hasard.
     */
    private function setting(string $type, ?Organization $organization = null): ?LoopTypeSetting
    {
        if ($organization) {
            $propre = $this->rawSetting($type, $organization);

            if ($propre) {
                return $propre;
            }
        }

        return $this->rawSetting($type, null);
    }

    /** Le reglage d'un niveau donne, sans repli. */
    private function rawSetting(string $type, ?Organization $organization): ?LoopTypeSetting
    {
        $this->memo ??= LoopTypeSetting::all()
            ->keyBy(fn (LoopTypeSetting $s) => ($s->organization_id ?? 'plateforme').'|'.$s->loop_type)
            ->all();

        return $this->memo[($organization?->id ?? 'plateforme').'|'.$type] ?? null;
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
        $registry = app(LoopCardRegistry::class);

        return collect($cards)
            ->filter(fn ($key) => is_string($key) && $registry->exists($key))
            ->unique()
            ->sortBy(fn ($key) => $registry->get($key)['order'] ?? 0)
            ->values()
            ->all();
    }
}
