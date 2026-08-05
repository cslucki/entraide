<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les Sondages d'une Boucle.
 *
 * Quatre tables, et une decision qui porte tout le reste : **un vote est un
 * objet**, pas une ligne par choix. Une personne a un `loop_poll_votes` par
 * Sondage — unicite en base — et ses choix pendent de cet objet. C'est ce qui
 * rend « une voix par personne » exprimable par une contrainte plutot que par
 * une precaution applicative, en choix multiple comme en choix unique.
 *
 * Le choix unique, lui, ne peut pas etre une contrainte SQL portable : il vit
 * dans la transaction du service, qui verrouille le vote avant de remplacer ses
 * choix.
 *
 * `organization_id` est porte par le Sondage et par le vote. Les tables filles
 * n'en portent pas : elles ne sont atteignables qu'a travers leur Sondage, dont
 * l'Organization fait foi. En porter une copie inviterait a la desynchroniser.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_polls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            // Le Sondage survit au depart de son auteur : son historique
            // nominatif reste lisible, avec un createur devenu inconnu.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('question', 500);
            $table->text('description')->nullable();
            $table->string('selection_type', 10)->default('single'); // single | multiple
            $table->string('status', 10)->default('open');           // open | closed

            $table->timestamp('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // La liste de la Card : les Sondages d'une Boucle, ouverts d'abord,
            // les plus recents en tete.
            $table->index(['loop_id', 'status', 'created_at']);
            $table->index('organization_id');
        });

        Schema::create('loop_poll_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('poll_id')->constrained('loop_polls')->cascadeOnDelete();
            $table->string('label', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['poll_id', 'position']);
        });

        Schema::create('loop_poll_votes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('poll_id')->constrained('loop_polls')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Une voix par personne et par Sondage. Le coeur du modele.
            $table->unique(['poll_id', 'user_id']);
        });

        Schema::create('loop_poll_vote_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vote_id')->constrained('loop_poll_votes')->cascadeOnDelete();
            $table->foreignUuid('option_id')->constrained('loop_poll_options')->cascadeOnDelete();
            $table->timestamps();

            // Pas deux fois la meme option dans un vote.
            $table->unique(['vote_id', 'option_id']);
            // Le depouillement compte par option.
            $table->index('option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_poll_vote_options');
        Schema::dropIfExists('loop_poll_votes');
        Schema::dropIfExists('loop_poll_options');
        Schema::dropIfExists('loop_polls');
    }
};
