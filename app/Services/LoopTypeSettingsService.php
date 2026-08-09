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

    /**
     * Le libelle **en vigueur** : celui de l'Organization, sinon celui de la
     * Plateforme, sinon la traduction declaree en configuration.
     *
     * **Le libelle n'est pas la cle.** `key = training` reste `training` pour
     * toujours ; seul le mot change, et il peut differer d'une Organization a
     * l'autre. La cle porte sept ans de donnees — `loops.type`, les presets,
     * les permissions — et c'est precisement pour cela qu'elle est separee du
     * mot qu'on lit.
     */
    public function labelFor(string $type, ?Organization $organization = null): ?string
    {
        return $this->setting($type, $organization)?->label;
    }

    public function descriptionFor(string $type, ?Organization $organization = null): ?string
    {
        return $this->setting($type, $organization)?->description;
    }

    /**
     * Ce que **ce niveau** a lui-meme ecrit, sans aucun repli.
     *
     * Distinct de `labelFor()`, qui rend le mot en vigueur — celui dont on
     * herite compris. L'ecran a besoin des deux : le champ affiche ce que cette
     * portee a decide, et le texte en dessous ce dont elle heriterait si on le
     * vidait. Les confondre ferait recopier l'heritage dans l'override au
     * premier enregistrement, et le type cesserait de suivre son niveau
     * superieur sans que personne ne l'ait demande.
     *
     * @return array{label: ?string, description: ?string}
     */
    public function ownTextsFor(string $type, ?Organization $organization = null): array
    {
        $ligne = $this->rawSetting($type, $organization);

        return [
            'label' => $ligne?->label,
            'description' => $ligne?->description,
        ];
    }

    /**
     * Renommer un type dans une portee donnee.
     *
     * Un libelle vide, ou identique au niveau au-dessus, **ne stocke rien** :
     * le type continue alors de suivre ce niveau. C'est la meme regle que pour
     * les Cards, et pour la meme raison — un override inutile fige un type sans
     * que personne ne le sache.
     *
     * **Asymetrie assumee au niveau Plateforme** : la, un mot saisi est toujours
     * stocke, meme s'il reproduit la traduction du fichier. Le niveau au-dessus
     * n'est pas un autre reglage mais un fichier de langue, et sa valeur depend
     * de la locale de celui qui regarde : ne rien stocker rendrait « Formation »
     * a un lecteur francais et « Training » a un lecteur anglais. Ecrire le mot,
     * c'est le figer pour tout le monde — ce que veut dire renommer un type.
     */
    public function rename(string $type, ?string $label, ?string $description, ?Organization $organization = null): void
    {
        $label = $this->cleanText($label, 80);
        $description = $this->cleanText($description, 2000);

        $refLabel = $organization
            ? $this->labelFor($type)
            : null;

        $refDescription = $organization
            ? $this->descriptionFor($type)
            : null;

        $payload = [
            'label' => $label === $refLabel ? null : $label,
            'description' => $description === $refDescription ? null : $description,
        ];

        $existant = $this->rawSetting($type, $organization);

        // Rien a dire, et rien d'autre a garder : la ligne disparait plutot que
        // de rester vide. Mais si elle porte une composition, on ne retire que
        // le mot — personne n'a demande d'effacer les Cards.
        if ($payload['label'] === null && $payload['description'] === null
            && ($existant === null || ($existant->cards === null && $existant->available === null))) {
            $this->deleteSetting($type, $organization);

            return;
        }

        $this->writeSetting($type, $organization, $payload);
    }

    private function cleanText(?string $texte, int $max): ?string
    {
        $texte = trim((string) $texte);

        return $texte === '' ? null : mb_substr($texte, 0, $max);
    }

    /** True when this type departs from its configured defaults. */
    public function isCustomised(string $type, ?Organization $organization = null): bool
    {
        $setting = $this->setting($type, $organization);

        return $setting !== null && ($setting->cards !== null
            || $setting->available !== null
            || $setting->label !== null
            || $setting->description !== null);
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

        $existant = $this->rawSetting($type, $organization);

        // **Symetrique de `rename()`.** La ligne porte aussi le libelle : la
        // supprimer parce que la composition rejoint le niveau au-dessus
        // effacerait un renommage que personne n'a demande de retirer. L'ecran
        // enregistre les deux d'un meme geste, donc les deux se croisent.
        if ($payload['cards'] === null && $payload['available'] === null
            && ($existant === null || ($existant->label === null && $existant->description === null))) {
            $this->deleteSetting($type, $organization);

            return;
        }

        $this->writeSetting($type, $organization, $payload);
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
        $this->deleteSetting($type, $organization);
    }

    // ── Les deux seules ecritures ───────────────────────────────────────────
    //
    // **Le memo s'invalide ici, et nulle part ailleurs.** Il l'etait avant dans
    // les appelants, avant leur ecriture : `rename()` relisait ensuite la table
    // pour savoir s'il restait quelque chose a garder, ce qui repeuplait le memo
    // avec l'etat **d'avant** l'ecriture. L'ecran d'administration reaffichait
    // alors l'ancien libelle dans la seconde qui suivait l'enregistrement.
    //
    // Le geste est donc porte par les primitives : aucun appelant ne peut plus
    // se tromper d'ordre.

    /** @param  array<string, mixed>  $payload */
    private function writeSetting(string $type, ?Organization $organization, array $payload): void
    {
        LoopTypeSetting::updateOrCreate(
            ['loop_type' => $type, 'organization_id' => $organization?->id],
            $payload,
        );

        $this->memo = null;
    }

    private function deleteSetting(string $type, ?Organization $organization): void
    {
        $requete = LoopTypeSetting::where('loop_type', $type);

        $organization
            ? $requete->where('organization_id', $organization->id)
            : $requete->whereNull('organization_id');

        $requete->delete();

        $this->memo = null;
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
