<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1762 point 2 — « On peut renseigner sa structure », par Organization.
 *
 * Demande de Cyril : « Par defaut, ce champs sera "NON" [...] Ainsi ce sera a
 * moi, via le superadmin de cocher la case ».
 *
 * ## Un booleen a `false`, et non une colonne nullable
 *
 * Cyril a ecrit « NON (ou NULL) ». Un booleen non nullable est la bonne forme :
 * `null` ajouterait un TROISIEME etat que personne n'a demande, et chaque
 * surface qui lit le drapeau — l'edition du profil, la validation, l'annuaire,
 * la fiche publique, les deux recherches a venir — devrait decider de son sens.
 * Un drapeau qui gouverne l'apparition d'un champ se lit `oui` ou `non`.
 *
 * `default(false)` est un **defaut de fabrique** : une Organization creee plus
 * tard, par le seeder ou par le formulaire, nait avec la case decochee sans que
 * personne ait a y penser. C'est la lecon de
 * `feedback_une_colonne_de_drapeau_de_securite_exige_un_defaut_de_fabrique`.
 *
 * Les Organizations EXISTANTES recoivent `false` : le champ « Structure »,
 * visible de tous depuis le point 1, disparait partout tant que Cyril ne coche
 * pas. C'est exactement ce qu'il demande.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'structure_enabled')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('structure_enabled')->default(false)->after('membership_label_en');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('organizations', 'structure_enabled')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('structure_enabled');
        });
    }
};
