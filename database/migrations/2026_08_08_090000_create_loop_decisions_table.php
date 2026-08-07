<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les Decisions d'une Boucle : ce qui a ete tranche, quand, et pourquoi.
 *
 * Le North Star nomme la perte que cette Card adresse :
 * « une decision n'est pas transformee en action ». Et il pose la regle qui la
 * gouverne : « **l'humain reste responsable des decisions durables** ». Rien
 * ici ne se decide tout seul, et l'IA moins que tout autre.
 *
 * Trois choix, chacun paye ailleurs dans cette serie :
 *
 * 1. **Une Decision promue ne copie jamais un message.** Le North Star dit
 *    qu'« une Interaction peut devenir […] une decision » ; promouvoir pose
 *    donc une **reference** — `loop_message_id` — et non un doublon du texte.
 *    Le message corrige se corrige partout. C'est exactement ce que le Journal
 *    a etabli en TASK-1104.
 *
 * 2. **Une Decision peut en remplacer une autre.** Sans cela la liste devient
 *    un mensonge des qu'un collectif revient sur un choix. `superseded_by_id`
 *    est une reference vers la Decision qui a pris le relais — la remplacee
 *    **reste lisible**, elle n'est pas effacee : c'est une memoire, pas un
 *    etat courant.
 *
 * 3. **Pas de statut, pas de workflow.** Une Decision est prise ou elle est
 *    remplacee ; il n'y a pas de brouillon, pas de circuit de validation, pas
 *    de vote. Le vote existe deja et s'appelle Sondage.
 *
 * Pas de table de categories, pas d'etiquettes, pas de fil de commentaires :
 * ce sont des chantiers que personne n'a demandes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Le cloisonnement est porte par la ligne, pas deduit de la Boucle.
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();

            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();

            // Ce qui a ete decide. Toujours present, y compris sur une Decision
            // promue : le titre est ce qu'on relit dans une liste, et le corps
            // d'un message n'en fait pas un.
            $table->string('title', 255);

            // Pourquoi. Facultatif — beaucoup de decisions se suffisent a
            // elles-memes, et exiger une justification les ferait ne pas
            // s'ecrire.
            $table->text('rationale')->nullable();

            // **La date de la decision**, distincte de celle de l'ecriture : on
            // consigne souvent apres coup.
            $table->date('decided_on');

            // La reference vers le message promu. `nullOnDelete` : un message
            // efface laisse la Decision, qui garde son titre et sa raison —
            // l'inverse effacerait ce que quelqu'un a voulu retenir.
            $table->foreignUuid('loop_message_id')->nullable()->constrained('loop_messages')->nullOnDelete();

            // La Decision qui a pris le relais. La contrainte est posee plus
            // bas : sous PostgreSQL, une cle etrangere vers **la table en cours
            // de creation** est refusee, la cle primaire n'etant etablie
            // qu'apres. C'est une divergence de plus avec SQLite, qui l'aurait
            // acceptee ici sans un mot.
            $table->uuid('superseded_by_id')->nullable();

            $table->timestamps();

            // La lecture : une Boucle, de la plus recente a la plus ancienne.
            $table->index(['loop_id', 'decided_on']);
            $table->index(['organization_id', 'loop_id']);

            // Un message ne se promeut qu'une fois. Tenu **par la base** et non
            // par un `SELECT … FOR UPDATE` sur une ligne inexistante, qui ne
            // verrouille rien sous PostgreSQL et n'est qu'un no-op sous SQLite.
            // La lecon vient de TASK-1104.
            $table->unique(['loop_id', 'loop_message_id']);
        });

        // `nullOnDelete` : si la Decision qui remplaçait disparait, la remplacee
        // **redevient courante** plutot que de rester barree en pointant vers
        // rien.
        Schema::table('loop_decisions', function (Blueprint $table) {
            $table->foreign('superseded_by_id')->references('id')->on('loop_decisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_decisions');
    }
};
