<?php

use App\Models\Loop;
use App\Models\LoopCard;
use Illuminate\Database\Migrations\Migration;

/**
 * Le rattrapage des Dossiers sur les Boucles Projet existantes.
 *
 * **C'est desormais un invariant, pas une decouverte** : ajouter une Card a un
 * preset n'atteint aucune Boucle deja creee. `activeCardsFor()` ne retombe sur
 * le preset que si la Boucle n'a **aucune** ligne `loop_cards`, et
 * `2026_08_04_090100_materialise_loop_cards_before_type_presets_change` les a
 * materialisees pour tout le parc.
 *
 * Le defaut a ete trouve par la revue en TASK-1104, retrouve en TASK-1105,
 * puis ecrit d'avance en TASK-1106 et 1107. Il l'est encore ici.
 *
 * `firstOrCreate` : la Card ne se dedouble pas, et une Card **desactivee a la
 * main** reste desactivee — `firstOrCreate` trouve la ligne et n'y touche pas.
 *
 * **Aucune Card n'est retiree.** Le rattrapage est purement additif : une
 * Boucle qui porte une Card hors preset la garde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Loop::query()
            ->where('type', 'project')
            ->whereHas('cards')
            ->each(function (Loop $loop) {
                LoopCard::firstOrCreate(
                    ['loop_id' => $loop->id, 'card_key' => 'core.dossiers'],
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
