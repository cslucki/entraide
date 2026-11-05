<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1762 point 1 — le champ « Structure » du profil.
 *
 * Demande de Cyril : « que les membres puissent renseigner leur societe ou
 * association, etc. Bref le nom de leur structure ».
 *
 * ## Une colonne, et non une cle de plus dans `profile_links`
 *
 * `profile_links` (T1750) est une liste d'ADRESSES. Une structure est un nom,
 * il sera cherche (points 8 et 9 de la specification : chercher par structure
 * dans le SuperAdmin et dans l'annuaire) et trie. Un JSON le rendrait
 * cherchable au prix d'un `LIKE` sur du texte serialise.
 *
 * ## Additive et idempotente
 *
 * `nullable` sans valeur par defaut : aucune ligne existante n'est touchee, et
 * un compte qui ne renseigne rien reste indistinguable de ce qu'il etait. Le
 * `down()` ne detruit qu'une colonne que cette TASK vient de creer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'structure')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Apres `first_name` dans la table : l'ordre des colonnes ne
            // gouverne rien, mais il rend la table lisible a qui l'ouvre.
            $table->string('structure')->nullable()->after('first_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'structure')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('structure');
        });
    }
};
