<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le Journal d'une Boucle : ce qui s'est passe, date et signe.
 *
 * Deux presets l'attendent — Pair-aidance et Coaching — et c'est ce qui en
 * fait la Card a livrer en premier : une seule table sert deux parcours.
 *
 * **Une entree ne copie jamais un message.** Le North Star le dit :
 * « une Interaction peut devenir une entree de Journal apres validation
 * humaine ». Promouvoir un message du ChatLoop pose donc une **reference** —
 * `loop_message_id` — et non un doublon de son texte. Le message corrige se
 * corrige partout ; il n'y a rien a garder d'accord.
 *
 * Une entree ecrite directement porte son propre texte. L'un **ou** l'autre,
 * jamais les deux : c'est la signature du service qui l'impose.
 *
 * Pas de table de categories, pas d'etiquettes, pas de fil de commentaires :
 * ce sont des chantiers que personne n'a demandes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Le cloisonnement est porte par la ligne, pas deduit de la Boucle.
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();

            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body')->nullable();

            // La reference vers le message promu. `nullOnDelete` : un message
            // efface laisse l'entree, qui garde alors son propre texte ou son
            // titre — l'inverse effacerait une trace que quelqu'un a voulu
            // garder.
            $table->foreignUuid('loop_message_id')->nullable()->constrained('loop_messages')->nullOnDelete();

            // **La date de ce qui s'est passe**, distincte de celle de
            // l'ecriture. On note souvent apres coup, et un Journal qui ne sait
            // dire que « quand ca a ete tape » ne raconte rien.
            $table->date('occurred_on');

            $table->timestamps();

            // La lecture du Journal : une Boucle, du plus recent au plus ancien.
            $table->index(['loop_id', 'occurred_on']);
            $table->index(['organization_id', 'loop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_journal_entries');
    }
};
