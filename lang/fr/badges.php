<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Les libelles des badges
    |--------------------------------------------------------------------------
    |
    | TASK-1757 (§9.7 du CDC entraide) — pourquoi ce fichier existe.
    |
    | Le catalogue de badges est de la DONNEE : `BadgeSeeder` seme `name` et
    | `description` en base, et le profil lit `$badge->name`. La passe de
    | lexique de la TASK A (T1754) s'est donc arretee aux fichiers de langue et
    | a laisse passer « Prestataire », « Multi-prestataire », « Evaluateur »,
    | « A complete son premier echange de service » — exactement le vocabulaire
    | que le critere d'acceptation n°1 de la TASK A bannit de l'UI membre.
    |
    | Plutot qu'une reprise de donnees (irreversible, et qui laisserait les
    | bases deja semees derriere), le rendu passe par une cle indexee sur
    | `badge->key`, avec REPLI sur la colonne. Meme doctrine que
    | `MessageSysteme::rendre()` en T1754 : cle si connue, texte brut sinon.
    | Un badge pose par une future migration s'affichera donc toujours, meme
    | sans entree ici.
    |
    */

    'keys' => [
        // Les 7 badges herites, retraduits dans le lexique de l'entraide.
        'first_exchange' => 'Première entraide',
        'five_exchanges' => 'Habitué de l’entraide',
        'ten_exchanges' => 'Pilier de la communauté',
        'first_service' => 'Première annonce',
        'five_services' => 'Cinq annonces publiées',
        'top_rated' => 'Très apprécié',
        'generous' => 'Dit merci',

        // TASK-1757 §9.7 — les trois nouveaux.
        'first_help' => 'Premier coup de main',
        'five_people_helped' => '5 personnes aidées',
        'helped_newcomer' => 'A aidé un nouveau membre',
    ],

    'descriptions' => [
        'first_exchange' => 'A participé à sa première entraide.',
        'five_exchanges' => 'A participé à 5 entraides.',
        'ten_exchanges' => 'A participé à 10 entraides.',
        'first_service' => 'A publié sa première annonce.',
        'five_services' => 'A publié 5 annonces ou plus.',
        'top_rated' => 'A une note moyenne de 4,5 ou plus (minimum 3 avis).',
        'generous' => 'A remercié 3 personnes qui l’avaient aidé.',

        'first_help' => 'A donné son premier coup de main.',
        'five_people_helped' => 'A aidé 5 personnes différentes.',
        'helped_newcomer' => 'A aidé quelqu’un pour sa toute première entraide.',
    ],

];
