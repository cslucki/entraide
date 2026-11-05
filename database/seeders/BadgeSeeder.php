<?php

namespace Database\Seeders;

use App\Models\Badge;
use App\Support\Entraide\CatalogueDeBadges;
use Illuminate\Database\Seeder;

/**
 * TASK-1757 — la liste vit dans `CatalogueDeBadges`, plus ici.
 *
 * Ce fichier portait sa propre copie des sept badges. Ajouter un badge
 * demandait donc de modifier DEUX endroits — ici et la condition d'attribution
 * — et rien ne rougissait si on n'en faisait qu'un : `maybeAward()` ne fait
 * RIEN en silence quand la ligne manque.
 */
class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CatalogueDeBadges::tous() as $cle => $definition) {
            Badge::firstOrCreate(['key' => $cle], $definition);
        }
    }
}
