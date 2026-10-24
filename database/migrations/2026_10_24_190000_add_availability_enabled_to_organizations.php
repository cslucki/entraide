<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1694 bug AE — la disponibilite des membres, activable par Organization.
 *
 * ## La demande
 *
 * Cyril : « ajouter une case a cocher au niveau du management des organisations
 * pour indiquer si cette fonctionnalite est active. Si elle n'est pas active, on
 * doit supprimer l'affichage [...] et tous les autres endroits ou ce petit point
 * vert ou rouge ».
 *
 * ## `true` par defaut, et c'est l'INVERSE du bug R
 *
 * Au bug R, la case « modifier les emails types » naissait DECOCHEE parce que
 * la demande etait un RETRAIT de capacite. Ici, la disponibilite existe, elle
 * fonctionne, et rien n'indique qu'il faille l'eteindre partout : un defaut a
 * `false` retirerait en silence une fonctionnalite de toutes les Organizations
 * deja en service.
 *
 * Le defaut preserve donc le comportement actuel. C'est a chaque Organization
 * de decocher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('availability_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('availability_enabled');
        });
    }
};
