<?php

use App\Support\ScenarioPacks\Packs\ArtSciLabEnglishPack;
use App\Support\ScenarioPacks\Packs\ArtSciLabRogerPack;
use App\Support\ScenarioPacks\Packs\Test20260822DogfoodingPack;

return [

    /*
    |--------------------------------------------------------------------------
    | Organizations autorisees comme cible d'un scenario pack
    |--------------------------------------------------------------------------
    |
    | Garde-fou decide avec Cyril le 2026-08-18 (contrat TASK-1239 S3 : une
    | Organization cible doit etre "explicitement qualifiee comme Organization
    | de demonstration/dogfooding", prerequis de conception pour TASK-1240,
    | pas une option).
    |
    | Allowlist declarative, source de verite UNIQUE du garde-fou : ajouter un
    | slug ici exige un commit revu et un deploiement — jamais un flag
    | basculable en base de donnees ou depuis une UI. Aucune Organization
    | absente de cette liste ne peut recevoir un chargement, un reset ou une
    | suppression de scenario pack, quel que soit l'appelant.
    |
    */
    'allowed_organizations' => [
        'artscilab-demo',
        // TASK-1269 : Organization ISOLEE de dogfooding de Cyril (decision
        // 2026-08-22 20:38), creee par le SuperAdmin, jamais `main`.
        Test20260822DogfoodingPack::ORGANIZATION_SLUG,
        // TASK-1351 : Organization de demonstration ANGLAISE (Roger /
        // ArtSciLab / UT Dallas). Seul slug de cette liste que son pack
        // provisionne lui-meme quand il est absent — voir
        // App\Support\ScenarioPacks\Contracts\ProvisionsItsOrganization.
        ArtSciLabEnglishPack::ORGANIZATION_SLUG,
    ],

    /*
    |--------------------------------------------------------------------------
    | Packs enregistres
    |--------------------------------------------------------------------------
    |
    | pack_id => classe implementant App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition.
    |
    */
    'definitions' => [
        'artscilab-roger-demo' => ArtSciLabRogerPack::class,
        Test20260822DogfoodingPack::PACK_ID => Test20260822DogfoodingPack::class,
        ArtSciLabEnglishPack::PACK_ID => ArtSciLabEnglishPack::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Repertoires source des packs qui importent de vrais fichiers
    |--------------------------------------------------------------------------
    |
    | pack_id => repertoire absolu. Les documents de dogfooding de Cyril
    | vivent HORS git (`_temp/`, gitignore) : le pack lit les noms de Loops
    | (sous-dossiers) et les fichiers sur le disque au chargement. Un test
    | pointe cette cle vers une fixture temporaire.
    |
    */
    'sources' => [
        Test20260822DogfoodingPack::PACK_ID => env(
            'SCENARIO_PACK_TEST20260822_SOURCE_DIR',
            base_path('_temp/Test_Rag-2026-08-22'),
        ),
    ],

];
