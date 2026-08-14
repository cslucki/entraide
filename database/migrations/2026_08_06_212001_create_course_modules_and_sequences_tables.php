<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le Support de cours d'une Boucle Formation : des Modules, et dans chacun des
 * Sequences.
 *
 * **Ni Module ni Sequence n'est une Card.** Ce sont les contenus de la Card
 * « Support de cours », comme un Article est le contenu d'un Dossier. Rien ici
 * n'ouvre un second systeme de documents : une Sequence *pointe* vers ce qui
 * existe deja — un Article, un fichier de Dossier — ou porte un texte court
 * ecrit sur place.
 *
 * Deux tables seulement, et aucune notion d'inscription : **l'inscription,
 * c'est `LoopMember`**. Une table `training_enrollments` aurait duplique
 * l'adhesion, avec deux verites a garder d'accord sur qui suit la formation.
 *
 * Aucune progression, aucun travail a rendre, aucun QCM : ce sont d'autres
 * Cards de la matrice, et les declarer ici en creux serait promettre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Le cloisonnement est porte par la ligne elle-meme, pas deduit de
            // la Boucle : une jointure oubliee ne doit pas ouvrir une autre
            // Organization.
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();

            $table->string('title');
            $table->text('summary')->nullable();

            // Le rang, et rien d'autre. Le numero affiche — `01`, `02`, … — est
            // **calcule** a partir de lui, jamais ecrit dans le titre : un
            // numero recopie devient faux au premier deplacement.
            $table->unsignedInteger('position')->default(0);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['loop_id', 'position']);
            $table->index(['organization_id', 'loop_id']);
        });

        Schema::create('course_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('course_module_id')->constrained('course_modules')->cascadeOnDelete();

            $table->string('title');

            // Une Sequence dit **une** chose, de trois manieres possibles : un
            // texte ecrit sur place, un Article existant, ou un fichier de
            // Dossier existant. Les deux dernieres sont des references — rien
            // n'est recopie, donc rien ne diverge de l'original.
            $table->text('body')->nullable();
            $table->foreignUuid('blog_post_id')->nullable()->constrained('blog_posts')->nullOnDelete();
            $table->foreignUuid('dossier_file_id')->nullable()->constrained('dossier_files')->nullOnDelete();

            $table->unsignedInteger('position')->default(0);

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['course_module_id', 'position']);
            $table->index(['organization_id', 'course_module_id']);
        });
    }

    public function down(): void
    {
        // L'ordre compte : les Sequences pointent vers les Modules.
        Schema::dropIfExists('course_sequences');
        Schema::dropIfExists('course_modules');
    }
};
