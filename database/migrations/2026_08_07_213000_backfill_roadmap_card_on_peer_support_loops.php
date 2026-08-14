<?php

use App\Models\Loop;
use App\Models\LoopCard;
use Illuminate\Database\Migrations\Migration;

/**
 * Le rattrapage des Engagements sur les Boucles Pair-aidance existantes.
 *
 * TASK-1105 ajoute `core.roadmap` au preset `peer_support` — c'est la Card que
 * la matrice produit appelle « Engagements ». **Ajouter une Card a un preset
 * n'atteint aucune Boucle deja creee** : `LoopTypeRegistry::activeCardsFor()`
 * ne retombe sur le preset que si la Boucle n'a aucune ligne `loop_cards`, et
 * `2026_08_04_090100_materialise_loop_cards_before_type_presets_change` les a
 * materialisees pour tout le parc.
 *
 * C'est **exactement** le defaut corrige la tache precedente pour le Journal
 * (`2026_08_07_190711`), et il est revenu a l'identique : une declaration de
 * preset ne se suffit jamais a elle-meme. La lecon est ici pour la prochaine.
 *
 * `firstOrCreate` et non une insertion en masse : la Card ne doit pas se
 * dedoubler, et une Card que quelqu'un a **desactivee a la main** reste
 * desactivee — `firstOrCreate` trouve la ligne et n'y touche pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Loop::query()
            ->where('type', 'peer_support')
            ->whereHas('cards')
            ->each(function (Loop $loop) {
                LoopCard::firstOrCreate(
                    ['loop_id' => $loop->id, 'card_key' => 'core.roadmap'],
                    [
                        'organization_id' => $loop->organization_id,
                        'enabled' => true,
                        'added_by_preset' => $loop->type,
                    ],
                );
            });
    }

    public function down(): void
    {
        // Rien n'est retire : une ligne posee par ce rattrapage est
        // indistinguable de celle qu'un administrateur aurait creee, et la
        // supprimer eteindrait sa Card sans qu'il l'ait demande.
    }
};
