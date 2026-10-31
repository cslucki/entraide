<?php

namespace App\Services\Users;

use App\Models\Organization;
use App\Models\User;

/**
 * TASK-1734 — une ligne du panneau « Mes organisations ».
 *
 * Une entree designe un COMPTE atteignable (ou pas), presente par son
 * Organization : c'est le vocabulaire de l'ecran. L'etat dit tout ce que la
 * vue a le droit de savoir — en particulier, `indisponible` ne transporte
 * JAMAIS son motif : il est au journal, pas a l'ecran.
 */
class MyOrganizationEntry
{
    public function __construct(
        public readonly User $compte,
        public readonly ?Organization $organization,
        public readonly string $etat,
        public readonly bool $lie,
    ) {}
}
