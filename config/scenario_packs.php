<?php

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
    ],

    /*
    |--------------------------------------------------------------------------
    | Packs enregistres
    |--------------------------------------------------------------------------
    |
    | pack_id => classe implementant App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition.
    | Vide par TASK-1240 (moteur generique seul) ; TASK-1242 y ajoutera le
    | premier pack reel (ArtSciLab/Roger).
    |
    */
    'definitions' => [
        //
    ],

];
