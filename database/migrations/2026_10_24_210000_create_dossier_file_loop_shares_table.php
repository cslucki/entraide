<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1695 (point 5a de Cyril) — partager un fichier dans une BOUCLE.
 *
 * ## Une table a part, pas une colonne de plus
 *
 * `dossier_file_shares` porte l'unicite `(dossier_file_id, user_id)`. Y poser
 * un `loop_id` nullable aurait rendu `user_id` nullable a son tour — et deux
 * NULL ne collisionnent pas en PostgreSQL : l'unicite serait devenue un voeu.
 * Le modele Dossier fait deja cette separation (`dossier_members` d'un cote,
 * `shared_with_loop_id` de l'autre) : les fichiers la reprennent.
 *
 * ## Le destinataire est la BOUCLE, pas ses membres du moment
 *
 * Aucune ligne par membre : l'acces se resout a la LECTURE (membre actif de la
 * Boucle, policy), jamais a l'ecriture. Copier les membres au moment du
 * partage aurait fige la liste — un arrivant n'aurait rien vu, un partant
 * aurait garde l'acces.
 *
 * ## Memes regles de survie que les partages nominatifs
 *
 * Cascade sur le fichier, la Boucle et l'Organization ; `shared_by` en
 * SET NULL — la trace du geste survit au depart de son auteur, le partage
 * reste valide. Le registre du cycle de vie declare cette colonne DANS LE MEME
 * COMMIT : la CI a deja rejete cette TASK une fois pour deux FK non declarees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_file_loop_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('dossier_file_id');
            $table->uuid('loop_id');
            $table->uuid('shared_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('dossier_file_id')->references('id')->on('dossier_files')->cascadeOnDelete();
            $table->foreign('loop_id')->references('id')->on('loops')->cascadeOnDelete();
            $table->foreign('shared_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['dossier_file_id', 'loop_id']);
            // « Quels fichiers pour cette Boucle ? » — la question de l'ecran
            // « Partages » d'un membre.
            $table->index(['organization_id', 'loop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_file_loop_shares');
    }
};
