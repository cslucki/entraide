<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\Organization;
use App\Support\Loops\LoopTypeRegistry;

/**
 * Propager le socle d'un type aux Boucles qui le portent — explicitement.
 *
 * Jusqu'ici, modifier le socle d'un type dans /admin/loop-types ne touchait
 * aucune Boucle existante, et l'ecran ne le disait pas. Un SuperAdmin ajoutait
 * une Card au type « Projets » et repartait en croyant l'avoir donnee aux
 * Boucles Projets. Ce service est la reponse, et il est appele par deux
 * chemins seulement : la commande `loops:sync-presets` et l'ecran des types.
 *
 * Trois regles, non negociables :
 *
 *   - **additive** : on ajoute ce qui manque, on ne retire jamais rien ;
 *   - **jamais a la lecture** : ouvrir une Boucle n'ecrit pas une ligne ;
 *   - **la composition locale gagne** : une Card desactivee a la main reste
 *     desactivee, une Card ajoutee a la main reste presente.
 *
 * La troisieme decoule de la structure et non d'une precaution : `applyPreset()`
 * compare des cles sans filtrer sur `enabled`, donc une Card presente-mais-eteinte
 * n'est jamais « manquante », donc jamais rallumee.
 */
class LoopPresetSyncService
{
    public function __construct(private LoopTypeRegistry $types) {}

    /**
     * Ce qu'une synchronisation ferait, sans rien ecrire.
     *
     * C'est ce que `--dry-run` affiche et ce que l'ecran des types montre avant
     * de faire valider : la meme mesure des deux cotes, pas deux calculs qui
     * pourraient diverger.
     *
     * @return array{
     *     types: array<int, string>,
     *     loops_scanned: int,
     *     loops_affected: int,
     *     cards_to_add: array<string, int>,
     *     disabled_preserved: int,
     *     local_preserved: int,
     * }
     */
    public function preview(?string $type = null, ?Organization $organization = null): array
    {
        $summary = [
            'types' => [],
            'loops_scanned' => 0,
            'loops_affected' => 0,
            'cards_to_add' => [],
            'disabled_preserved' => 0,
            'local_preserved' => 0,
        ];

        foreach ($this->loopsInScope($type, $organization) as $loop) {
            $summary['loops_scanned']++;

            $resolved = $this->types->resolve($loop->type);
            if (! in_array($resolved, $summary['types'], true)) {
                $summary['types'][] = $resolved;
            }

            // **Le socle de l'Organization de la Boucle**, comme `applyPreset()`.
            // Sans l'Organization, la mesure lisait le socle Plateforme pendant
            // que l'ecriture appliquait celui du locataire : deux socles
            // differents depuis que les types sont scopables, donc un chiffre
            // annonce que le geste ne produisait pas.
            $wanted = $this->types->cardsFor($loop->type, $this->organizationOf($loop));
            $rows = LoopCard::where('loop_id', $loop->id)->get();
            $existing = $rows->pluck('card_key')->all();

            // Comptees ici pour pouvoir affirmer, chiffres a l'appui, que la
            // synchronisation ne les a pas touchees.
            $summary['disabled_preserved'] += $rows->where('enabled', false)->count();
            $summary['local_preserved'] += $rows->whereNull('added_by_preset')->count();

            $missing = array_values(array_diff($wanted, $existing));

            if ($missing === []) {
                continue;
            }

            $summary['loops_affected']++;

            foreach ($missing as $key) {
                $summary['cards_to_add'][$key] = ($summary['cards_to_add'][$key] ?? 0) + 1;
            }
        }

        sort($summary['types']);

        return $summary;
    }

    /**
     * Appliquer reellement. Idempotent : deux passages de suite n'ajoutent rien
     * la seconde fois.
     *
     * @return array{loops_scanned: int, loops_affected: int, cards_added: array<string, int>}
     */
    public function sync(?string $type = null, ?Organization $organization = null): array
    {
        $result = ['loops_scanned' => 0, 'loops_affected' => 0, 'cards_added' => []];

        foreach ($this->loopsInScope($type, $organization) as $loop) {
            $result['loops_scanned']++;

            // Une seule ecriture, celle du registre : la regle additive et son
            // verrou vivent la, et ce service ne la reimplemente pas.
            $added = $this->types->applyPreset($loop);

            if ($added === []) {
                continue;
            }

            $result['loops_affected']++;

            foreach ($added as $key) {
                $result['cards_added'][$key] = ($result['cards_added'][$key] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * L'impact d'un socle qu'on s'apprete a enregistrer, avant de l'enregistrer.
     *
     * Les Cards du socle a venir sont comparees a ce que chaque Boucle possede
     * deja — sans passer par cardsFor(), qui lirait le socle *actuel*.
     *
     * @param  array<int, string>  $futureCards
     * @return array{loops: int, cards_to_add: array<string, int>, loops_affected: int}
     */
    public function previewForCards(string $type, array $futureCards, ?Organization $organization = null): array
    {
        $out = ['loops' => 0, 'cards_to_add' => [], 'loops_affected' => 0];

        foreach ($this->loopsInScope($type, $organization) as $loop) {
            $out['loops']++;

            $existing = LoopCard::where('loop_id', $loop->id)->pluck('card_key')->all();
            $missing = array_values(array_diff($futureCards, $existing));

            if ($missing === []) {
                continue;
            }

            $out['loops_affected']++;

            foreach ($missing as $key) {
                $out['cards_to_add'][$key] = ($out['cards_to_add'][$key] ?? 0) + 1;
            }
        }

        return $out;
    }

    /** @var array<string, Organization|null> */
    private array $organizations = [];

    /**
     * L'Organization d'une Boucle, lue une fois par locataire.
     *
     * Le parcours est un curseur : `$loop->organization` ferait une requete par
     * Boucle. Le memo est borne par le nombre d'Organizations, pas par la taille
     * du parc.
     */
    private function organizationOf(Loop $loop): ?Organization
    {
        if ($loop->organization_id === null) {
            return null;
        }

        return $this->organizations[$loop->organization_id] ??= Organization::find($loop->organization_id);
    }

    /**
     * Les Boucles concernees, alias legacy replies.
     *
     * `custom` est l'ancien nom de `general` : filtrer sur le seul nom canonique
     * laisserait de cote six Boucles reelles de cette base.
     *
     * Les Boucles archivees sont incluses. Ajouter une Card a une archive
     * n'ecrit aucune donnee metier et evite qu'une Boucle reactivee se reveille
     * avec un socle perime — ce qui serait une surprise, pas une protection.
     *
     * **`$organization` restreint a un seul locataire.** Depuis que les socles
     * sont scopables, le SuperAdmin peut regler un type pour une Organization
     * donnee : l'impact annonce doit alors se compter chez elle seule. L'ecriture
     * etait deja juste — `applyPreset()` lit le socle de l'Organization **de la
     * Boucle** — mais la mesure, elle, portait sur tout le parc et annoncait un
     * chiffre que le geste ne produisait pas.
     *
     * @return \Illuminate\Support\LazyCollection<int, Loop>
     */
    private function loopsInScope(?string $type, ?Organization $organization = null): \Illuminate\Support\LazyCollection
    {
        $query = Loop::query()->orderBy('id');

        if ($type !== null) {
            $aliases = array_keys(array_filter(
                config('loop_types.legacy_aliases', []),
                fn ($target) => $target === $type,
            ));

            $query->whereIn('type', array_merge([$type], $aliases));
        }

        if ($organization !== null) {
            $query->where('organization_id', $organization->id);
        }

        return $query->cursor();
    }
}
