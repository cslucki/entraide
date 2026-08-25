<?php

namespace App\Support\ScenarioPacks\Packs;

use App\Models\Organization;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Database\Seeders\ArtSciLabScenarioSeeder;
use LogicException;

/**
 * TASK-1242 — premier pack reel du moteur T1240, destine au dogfooding
 * Roger : le contenu deja fictif d'ArtSciLabScenarioSeeder (TASK-1203),
 * rejoue et declare au registrar pour beneficier de l'idempotence, du reset
 * et de la suppression bornee du moteur, au lieu d'un `db:seed` ad hoc.
 *
 * Decision produit deja actee avec Cyril le 2026-08-18 (allowlist
 * `config('scenario_packs.allowed_organizations')` + commentaire
 * "definitions" dans `config/scenario_packs.php`) : `artscilab-demo` est une
 * Organization de demonstration/dogfooding fictive, jamais une Organization
 * cliente reelle. Aucune donnee reelle, personnelle ou secrete.
 */
class ArtSciLabRogerPack implements ScenarioPackDefinition
{
    public function packId(): string
    {
        return 'artscilab-roger-demo';
    }

    public function packVersion(): string
    {
        return '1.0.0';
    }

    public function packName(): string
    {
        return 'ArtSciLab — démonstration Roger';
    }

    public function purpose(): string
    {
        return 'Démontrer le parcours IA/RAG canonique (clarification, validation humaine, Ask the Folders) sur l\'Organization de démonstration ArtSciLab, pour le dogfooding Roger.';
    }

    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        if ($organization->slug !== ArtSciLabScenarioSeeder::SLUG) {
            throw new LogicException(
                "ArtSciLabRogerPack ne peut cibler que l'Organization '".ArtSciLabScenarioSeeder::SLUG."', reçu '{$organization->slug}'."
            );
        }

        (new ArtSciLabScenarioSeeder)->compose($registrar);
    }
}
