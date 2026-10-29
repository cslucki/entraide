<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1725 (demande Cyril, 29/10) — « site multilingue », par Organization.
 *
 * ## Ce que le drapeau gouverne
 *
 * Le selecteur FR/EN. Il vivait sur HUIT emplacements — les trois gabarits de
 * homepage en version bureau ET menu mobile, le rail de gauche, la barre mobile
 * — et s'affichait partout, pour toute Organization, qu'elle publie ou non deux
 * langues. Demande Cyril : « on supprime le switch fr/en de la homepage et du
 * rail/gauche », avec cette case pour le rallumer.
 *
 * ## Le defaut est FAUX, et ce n'est pas l'option prudente
 *
 * Les autres bascules d'Organization (`loops_enabled`,
 * `members_can_create_loops`) valent VRAI par defaut : elles ouvrent une
 * fonction qu'on peut fermer. Celle-ci fait l'inverse — la demande est de
 * SUPPRIMER le selecteur, et la case existe pour le ramener la ou deux langues
 * sont reellement publiees. Un defaut a VRAI aurait livre un correctif sans
 * effet, et laisse a Cyril un geste a refaire sur chaque Organization.
 *
 * Choix signale a Cyril : une case a cocher suffit a revenir dessus.
 *
 * ## Pas de `nullable`
 *
 * Un drapeau de configuration a trois etats — vrai, faux, et « personne n'a
 * decide » — oblige chaque lecteur a choisir ce que vaut le troisieme, et deux
 * lecteurs finissent par ne pas choisir la meme chose. `default(false)` sur une
 * colonne NOT NULL : les lignes existantes recoivent FAUX a l'application, et il
 * n'y a jamais de troisieme cas a interpreter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('multilingual_enabled')
                ->default(false)
                ->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('multilingual_enabled');
        });
    }
};
