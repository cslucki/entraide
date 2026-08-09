<?php

namespace App\Services\Loops;

use App\Models\CustomLoopType;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;

/**
 * Creer un type de Boucle, et le retirer tant que personne ne s'en sert.
 *
 * Le seul point d'ecriture de `custom_loop_types` — comme
 * `LoopTypeSettingsService` l'est pour les surcharges. Ce qui compte ici tient
 * en trois regles :
 *
 *   - **la cle est forgee, jamais saisie** : elle porte un prefixe
 *     d'Organization et devient immuable a la seconde ou elle existe ;
 *   - **« Partir de » copie, ne suit pas** : la composition du modele est
 *     recopiee a la creation et vit sa vie ensuite. Suivre ferait bouger un type
 *     tout seul quand un autre change, ce que personne n'a demande ;
 *   - **un type porte par des Boucles ne se supprime pas** : elles resteraient
 *     avec une cle sans catalogue, donc affichees comme le type par defaut.
 */
class LoopTypeCreationService
{
    public function __construct(
        private LoopTypeRegistry $types,
        private LoopCardRegistry $cards,
    ) {}

    /**
     * @param  array<int, string>|null  $cards  null = reprendre celles du modele
     *
     * @throws \InvalidArgumentException
     */
    public function create(
        ?Organization $organization,
        string $label,
        ?string $description = null,
        ?string $basedOn = null,
        ?array $cards = null,
        ?User $author = null,
    ): CustomLoopType {
        $label = trim($label);

        if ($label === '') {
            throw new \InvalidArgumentException(__('loops.types_admin_error_label_required'));
        }

        $label = mb_substr($label, 0, 80);
        $key = $this->uniqueKey($organization, $label);

        // **« Partir de » copie une composition, il n'en herite pas.** Le modele
        // doit exister ; sinon on partirait de rien en croyant partir de
        // quelque chose.
        if ($basedOn !== null && ! $this->types->exists($basedOn)) {
            throw new \InvalidArgumentException(__('loops.types_admin_error_unknown_base'));
        }

        $composition = $cards ?? ($basedOn !== null
            ? $this->types->cardsFor($basedOn, $organization)
            : []);

        $composition = $this->normalize($composition);

        // Un type qu'on peut choisir doit composer quelque chose : la meme regle
        // que l'ecran des socles, et pour la meme raison — un socle vide donne
        // un espace de travail vide.
        $available = $composition !== [];

        $type = CustomLoopType::create([
            'organization_id' => $organization?->id,
            'key' => $key,
            'label' => $label,
            'description' => $description !== null ? mb_substr(trim($description), 0, 2000) : null,
            'based_on' => $basedOn,
            'icon' => $basedOn !== null ? ($this->types->definition($basedOn)['icon'] ?? null) : null,
            'cards' => $composition,
            'available' => $available,
            'order' => $this->nextOrder(),
            'created_by' => $author?->id,
        ]);

        $this->types->forgetCatalogue();

        return $type;
    }

    /**
     * Retirer un type cree.
     *
     * **Refuse tant qu'une Boucle le porte.** Supprimer la definition laisserait
     * ces Boucles avec une cle que le catalogue ne connait plus : `resolve()`
     * les ferait retomber sur le type par defaut, et elles s'afficheraient comme
     * des Communautes sans que personne ne l'ait decide. Fermer le type — le
     * rendre indisponible — est le geste qui existe pour cela.
     *
     * @throws \InvalidArgumentException
     */
    public function delete(CustomLoopType $type): void
    {
        $portee = Loop::query()->where('type', $type->key)->count();

        if ($portee > 0) {
            throw new \InvalidArgumentException(
                __('loops.types_admin_error_type_in_use', ['count' => $portee]),
            );
        }

        $type->delete();

        $this->types->forgetCatalogue();
    }

    /**
     * Une cle libre, forgee depuis le mot saisi.
     *
     * La cle est **prefixee par l'Organization** et ne changera plus jamais :
     * c'est elle que porteront `loops.type`, les socles et les permissions.
     * Renommer le type plus tard ne la touchera pas — c'est tout l'objet de
     * TASK-1116.
     */
    private function uniqueKey(?Organization $organization, string $label): string
    {
        $base = CustomLoopType::forgeKey($organization, $label);
        $key = $base;
        $n = 2;

        // Deux types de meme nom dans la meme portee sont legitimes ; deux
        // memes cles ne le sont pas.
        while ($this->keyTaken($organization, $key)) {
            $suffixe = '_'.$n;
            $key = mb_substr($base, 0, 80 - strlen($suffixe)).$suffixe;
            $n++;
        }

        return $key;
    }

    private function keyTaken(?Organization $organization, string $key): bool
    {
        // Le catalogue entier, pas la seule portee : une cle qui existe deja
        // ailleurs rendrait `definition()` ambigu.
        if ($this->types->exists($key)) {
            return true;
        }

        return CustomLoopType::query()->where('key', $key)->exists();
    }

    /** @param array<int, mixed> $cards @return array<int, string> */
    private function normalize(array $cards): array
    {
        return collect($cards)
            ->filter(fn ($k) => is_string($k) && $this->cards->exists($k))
            ->unique()
            ->sortBy(fn ($k) => $this->cards->get($k)['order'] ?? 0)
            ->values()
            ->all();
    }

    /** Apres les types du fichier, dans l'ordre d'arrivee. */
    private function nextOrder(): int
    {
        return (int) (CustomLoopType::query()->max('order') ?? 500) + 10;
    }
}
