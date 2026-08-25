<?php

namespace App\Support\ScenarioPacks\Exceptions;

use RuntimeException;

/**
 * `pack_id` absent de `config('scenario_packs.definitions')` (ScenarioPackCatalog).
 */
class ScenarioPackNotFoundException extends RuntimeException
{
    public static function forId(string $packId): self
    {
        return new self("Aucun scenario pack enregistre pour pack_id='{$packId}' (config('scenario_packs.definitions')).");
    }
}
