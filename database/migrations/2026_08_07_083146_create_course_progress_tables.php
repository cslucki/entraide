<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La progression individuelle : la structure est commune, l'etat est individuel.
 *
 * Toute la tache tient dans cette phrase. Les Modules et les Sequences existent
 * une fois pour la Boucle ; ce qui suit ne stocke que **ce que chaque personne
 * en a fait**.
 *
 * Trois choses n'existent volontairement pas ici :
 *
 * 1. **Aucune table d'inscription.** L'inscription, c'est `LoopMember`. Une
 *    seconde table ferait deux verites sur « qui suit cette formation ».
 * 2. **Aucun etat par Module.** Il se **calcule** depuis ses Sequences. Le
 *    stocker obligerait a le tenir a jour a chaque mouvement, et deux verites
 *    finiraient par diverger — au pire moment, celui ou quelqu'un croit avoir
 *    fini.
 * 3. **Aucun etat `unavailable` ni `available` en base.** Ce sont des
 *    **conclusions**, pas des faits : elles dependent des regles d'ouverture et
 *    changent sans que la personne ait rien fait. Seul ce qu'elle a
 *    reellement fait est ecrit.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Le mode de parcours d'une Formation.
        //
        // Dans sa propre table, et non en colonne de `loops` : c'est un reglage
        // qui n'a de sens que pour un type, et le socle doit rester neutre. Une
        // Boucle sans ligne ici est **sequentielle** — le defaut arrete, obtenu
        // sans ecrire quoi que ce soit a la creation.
        Schema::create('course_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();

            $table->string('path_mode')->default('sequential');

            $table->timestamps();

            // Une Boucle, un reglage. L'unicite l'impose plutot que de la
            // confier a la discipline des appelants.
            $table->unique('loop_id');
        });

        Schema::table('course_sequences', function (Blueprint $table) {
            // Cette Sequence attend-elle un regard du formateur ?
            //
            // C'est ce qui distingue `completed` — declaratif, le stagiaire dit
            // avoir fait — de `validated`, prononce par quelqu'un. Pour une
            // lecture, personne ne va corriger une lecture.
            $table->boolean('requires_validation')->default(false)->after('body');

            // Une Sequence sur laquelle une progression existe **s'archive**,
            // elle ne se supprime pas : effacer la ligne effacerait ce que des
            // gens ont fait. Meme regle que les Sondages votes et les Evenements
            // repondus depuis TASK-1087.
            $table->timestamp('archived_at')->nullable()->after('position');
        });

        Schema::create('course_sequence_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('course_sequence_id')->constrained('course_sequences')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // Ce que la personne a fait, et rien d'autre : `in_progress`,
            // `submitted`, `completed`, `validated`, `redo`.
            $table->string('status');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();

            // Le deblocage manuel du formateur. Il **prime sur tout** : un
            // formateur qui ouvre une etape a quelqu'un sait ce qu'il fait, et
            // aucune regle automatique ne doit le contredire.
            $table->timestamp('unlocked_at')->nullable();
            $table->foreignUuid('unlocked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Une personne, une Sequence, une ligne.
            $table->unique(['course_sequence_id', 'user_id']);
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_sequence_progress');

        Schema::table('course_sequences', function (Blueprint $table) {
            $table->dropColumn(['requires_validation', 'archived_at']);
        });

        Schema::dropIfExists('course_settings');
    }
};
