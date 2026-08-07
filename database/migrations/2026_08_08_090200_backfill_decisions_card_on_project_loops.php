<?php

use App\Models\Loop;
use App\Models\LoopCard;
use Illuminate\Database\Migrations\Migration;

/**
 * Le rattrapage des Decisions sur les Boucles Projet existantes.
 *
 * **Ajouter une Card a un preset n'atteint aucune Boucle deja creee** :
 * `LoopTypeRegistry::activeCardsFor()` ne retombe sur le preset que si la
 * Boucle n'a aucune ligne `loop_cards`, et
 * `2026_08_04_090100_materialise_loop_cards_before_type_presets_change` les a
 * materialisees pour tout le parc.
 *
 * Ce defaut a ete trouve **deux fois de suite** par la revue — pour le Journal
 * en TASK-1104, pour la Roadmap Pair-aidance en TASK-1105. Il est ecrit ici
 * avant qu'on ait a le trouver une troisieme fois : une declaration de preset
 * ne se suffit jamais a elle-meme.
 *
 * `firstOrCreate` et non une insertion en masse : la Card ne se dedouble pas,
 * et une Card que quelqu'un a **desactivee a la main** reste desactivee —
 * `firstOrCreate` trouve la ligne et n'y touche pas.
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
                    ['loop_id' => $loop->id, 'card_key' => 'core.decisions'],
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
