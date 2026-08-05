<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les rencontres d'une Boucle.
 *
 * Deux tables, sur la meme forme que les Sondages : une reponse est un objet
 * unique par personne et par Evenement, donc « une voix par personne » est une
 * contrainte de la base et non une precaution du code.
 *
 * **Le fuseau est porte par l'Evenement.** Les dates sont en UTC, et `timezone`
 * garde l'identifiant IANA choisi a la creation. C'est le bon endroit : une
 * rencontre a lieu quelque part, et c'est ce lieu qui donne l'heure — pas la
 * preference de celui qui regarde. Ce produit n'a d'ailleurs aucune preference
 * de fuseau, ni sur `users` ni sur `organizations`, et cette tache n'en invente
 * pas.
 *
 * `visibility` decide qui voit : `loop` reste dans la Boucle, `organization`
 * remonte dans l'agenda agrege. Une Boucle privee ne peut porter que `loop` —
 * regle appliquee dans le service, la base ne connaissant pas la visibilite de
 * la Boucle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            // L'Evenement survit au depart de son auteur : son historique reste
            // lisible, avec un createur devenu inconnu.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('format', 12)->default('in_person'); // in_person | online | hybrid

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // Identifiant IANA, jamais un decalage : « Europe/Paris » survit aux
            // changements d'heure, « +01:00 » non.
            $table->string('timezone', 64)->default('Europe/Paris');

            $table->string('location', 255)->nullable();
            $table->string('meeting_url', 2048)->nullable();

            $table->string('visibility', 16)->default('loop');   // loop | organization
            $table->string('status', 12)->default('scheduled');  // scheduled | cancelled

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // L'agenda d'une Boucle : par date.
            $table->index(['loop_id', 'starts_at']);
            // L'agenda d'une Organization : les Evenements remontes, par date.
            $table->index(['organization_id', 'visibility', 'starts_at']);
        });

        Schema::create('loop_event_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Porte explicitement, comme sur les votes de Sondage : une reponse
            // se compte et se filtre sans passer par son Evenement.
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('loop_events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('response', 12); // going | maybe | not_going
            $table->timestamps();

            // Une reponse par personne et par Evenement.
            $table->unique(['event_id', 'user_id']);
            // Le decompte par reponse.
            $table->index(['event_id', 'response']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_event_responses');
        Schema::dropIfExists('loop_events');
    }
};
