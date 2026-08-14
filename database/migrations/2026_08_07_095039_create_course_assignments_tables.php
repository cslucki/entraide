<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les Travaux a rendre : ce qu'on demande, et ce que chacun remet.
 *
 * Meme partage que la Progression — **la structure est commune, l'etat est
 * individuel**. Un Travail existe une fois pour la Boucle ; une remise
 * appartient a une personne.
 *
 * **Aucun second systeme de fichiers.** Une remise qui porte un fichier pointe
 * vers un `DossierFile` du Dossier racine de la Boucle : le quota, la somme de
 * controle et la deduplication sont deja la, et les dupliquer aurait garanti
 * qu'ils divergent. Rien n'est recopie.
 *
 * **Aucun second editeur.** Le corps d'une remise est du texte, pose par
 * l'editeur existant.
 *
 * Le Travail est **le seul objet a porter une date limite** dans le MVP. Ni
 * Module ni Sequence n'en ont : une echeance generale appellerait rappels,
 * retards et penalites — un chantier que personne n'a demande. Les seances et
 * rendez-vous passent par la Card Evenements, deja livree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();

            // Rattachement facultatif a une Sequence : un Travail peut clore une
            // etape du parcours, ou vivre a cote. `nullOnDelete` plutot que
            // cascade — supprimer une Sequence ne doit pas emporter les remises
            // que des gens ont faites.
            $table->foreignUuid('course_sequence_id')->nullable()->constrained('course_sequences')->nullOnDelete();

            $table->string('title');
            $table->text('brief')->nullable();

            $table->timestamp('due_at')->nullable();

            $table->unsignedInteger('position')->default(0);

            // Un Travail deja rendu **s'archive**, il ne se supprime pas :
            // effacer la ligne effacerait ce que des gens ont remis. Et un
            // Travail archive sort du parcours actif — il ne bloque plus rien.
            $table->timestamp('archived_at')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['loop_id', 'position']);
            $table->index(['organization_id', 'loop_id']);
        });

        Schema::create('course_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('course_assignment_id')->constrained('course_assignments')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->text('body')->nullable();

            // **Une reference**, pas une copie : le fichier vit dans le Dossier
            // de la Boucle, avec son quota et sa somme de controle.
            $table->foreignUuid('dossier_file_id')->nullable()->constrained('dossier_files')->nullOnDelete();

            // `draft`, `submitted`, `validated`, `redo`. Comme pour la
            // Progression, seul ce que la personne a fait est ecrit.
            $table->string('status')->default('draft');

            $table->timestamp('submitted_at')->nullable();

            // Le retour du formateur. `reviewed_by` est ecrit : un avis sans
            // auteur ne serait pas un avis.
            $table->text('feedback')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Une personne, un Travail, une remise.
            $table->unique(['course_assignment_id', 'user_id']);
            $table->index(['organization_id', 'user_id']);
            // La requete du formateur — « qu'est-ce qui attend mon regard ? » —
            // se lit sur cet index.
            $table->index(['course_assignment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_submissions');
        Schema::dropIfExists('course_assignments');
    }
};
