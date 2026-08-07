<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les QCM : ce qui se corrige tout seul.
 *
 * **Un QCM n'est pas un Sondage**, et ne partage pas son modele. Un Sondage n'a
 * pas de bonne reponse, pas de score, pas de tentative, et il est nominatif par
 * construction. Ce qui se reutilise est la *forme* — une question, des options,
 * une reponse par personne — pas les tables.
 *
 * Meme partage que le reste de la Formation : la structure est commune, l'etat
 * est individuel. Un QCM et ses questions existent une fois pour la Boucle ; une
 * **tentative** appartient a une personne.
 *
 * Quatre tables, et pas une de plus. Pas de moteur de bareme, pas de banque de
 * questions, pas de tirage aleatoire : ce sont des chantiers que personne n'a
 * demandes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();

            // Rattachement facultatif a une Sequence, comme les Travaux.
            // `nullOnDelete` : supprimer une Sequence n'emporte pas les
            // tentatives que des gens ont passees.
            $table->foreignUuid('course_sequence_id')->nullable()->constrained('course_sequences')->nullOnDelete();

            $table->string('title');
            $table->text('intro')->nullable();

            // Le seuil de reussite, en pourcentage.
            $table->unsignedTinyInteger('pass_score')->default(70);

            // Nul = autant de tentatives qu'on veut.
            $table->unsignedSmallInteger('max_attempts')->nullable();

            // **Informatif ou bloquant, au choix du formateur, QCM par QCM.**
            // Informatif, il situe ; bloquant, il conditionne le passage.
            $table->boolean('blocking')->default(false);

            // Quand le detail des bonnes reponses apparait. Trois valeurs, pas
            // un moteur : `immediate`, `after_last_attempt`, `after_release`.
            //
            // Le score, lui, est **toujours** immediat : c'est ce que tout le
            // monde attend. Le detail immediat rendrait les tentatives
            // multiples sans objet, et circulerait d'un stagiaire a l'autre.
            $table->string('reveal_mode')->default('after_last_attempt');

            // Renseigne quand le formateur ouvre le detail, en mode
            // `after_release`.
            $table->timestamp('released_at')->nullable();

            $table->unsignedInteger('position')->default(0);

            // Un QCM deja tente **s'archive** : effacer la ligne effacerait ce
            // que des gens ont passe. Et il sort du parcours actif — il ne
            // bloque plus rien.
            $table->timestamp('archived_at')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['loop_id', 'position']);
            $table->index(['organization_id', 'loop_id']);
        });

        Schema::create('course_quiz_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('course_quiz_id')->constrained('course_quizzes')->cascadeOnDelete();

            $table->text('text');

            // `single`, `multiple`, `true_false`. Trois formes, celles du MVP.
            $table->string('kind')->default('single');

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['course_quiz_id', 'position']);
        });

        Schema::create('course_quiz_options', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('course_quiz_question_id')->constrained('course_quiz_questions')->cascadeOnDelete();

            $table->text('text');

            // C'est **cette** colonne qui distingue un QCM d'un Sondage : un
            // Sondage n'a pas de bonne reponse.
            $table->boolean('is_correct')->default(false);

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['course_quiz_question_id', 'position']);
        });

        Schema::create('course_quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('course_quiz_id')->constrained('course_quizzes')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // Le rang de la tentative pour cette personne : 1, 2, 3…
            $table->unsignedSmallInteger('attempt_number');

            $table->unsignedTinyInteger('score');
            $table->boolean('passed');

            // Ce qui a ete coche, question par question. Garde pour que le
            // detail puisse etre montre plus tard sans rejouer la correction.
            $table->json('answers');

            $table->timestamp('submitted_at');
            $table->timestamps();

            // Une personne ne passe pas deux fois la meme tentative.
            $table->unique(['course_quiz_id', 'user_id', 'attempt_number']);
            $table->index(['course_quiz_id', 'user_id']);
            $table->index(['organization_id', 'user_id']);
        });

        Schema::table('course_sequence_progress', function (Blueprint $table) {
            // **Le deblocage manuel reste un geste distinct d'une reussite.**
            // Les deux ouvrent une etape, mais ils ne disent pas la meme chose,
            // et confondre les deux ferait passer un coup de pouce pour un
            // resultat. `unlocked_at` porte deja le premier ; celle-ci porte le
            // second, et elle est renseignee par la **correction**, jamais par
            // une main.
            $table->foreignUuid('passed_quiz_id')->nullable()->after('unlocked_by')
                ->constrained('course_quizzes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('course_sequence_progress', function (Blueprint $table) {
            $table->dropConstrainedForeignId('passed_quiz_id');
        });

        Schema::dropIfExists('course_quiz_attempts');
        Schema::dropIfExists('course_quiz_options');
        Schema::dropIfExists('course_quiz_questions');
        Schema::dropIfExists('course_quizzes');
    }
};
