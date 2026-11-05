<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1762 point 17 — le « slogan » du profil.
 *
 * Demande de Cyril : « Ce qui vous défini en une phrase » — une baseline
 * obligatoire, affichee en avant sur la fiche publique, les cartes de
 * l'annuaire et la vue « Nouveaux arrivés ».
 *
 * ## `nullable`, meme si le champ est OBLIGATOIRE a la saisie
 *
 * La regle « obligatoire » (`required` au point 17) vit dans la validation,
 * pas dans la colonne — meme forme que `name` et `phone` (T1712). Une
 * colonne `NOT NULL` casserait l'INSERT de chaque compte deja existant au
 * moment de cette migration : aucun d'eux n'a encore saisi de slogan.
 *
 * ## Additive et idempotente
 *
 * Aucune ligne existante n'est touchee ; le `down()` ne detruit qu'une
 * colonne que cette TASK vient de creer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'slogan')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            // Apres `structure` : les deux champs voisinent dans le
            // formulaire d'edition comme sur la fiche publique.
            $table->string('slogan', 160)->nullable()->after('structure');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'slogan')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('slogan');
        });
    }
};
