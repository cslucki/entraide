<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1315 — le fil du Shell « BouclePro IA », conserve pendant la navigation.
 *
 * UNE table, pas deux. Il n'y a ni titre, ni fil multiple, ni resume : le fil
 * d'un utilisateur dans une Organization est exactement l'ensemble de ses
 * lignes ici. C'est deliberement plus petit qu'un modele de conversation —
 * « memoire avancee » est hors V1.
 *
 * Pourquoi une table et pas la session : la page d'une Boucle poll toutes les
 * 3 s (`wire:poll` de ChatLoop) et la session est ecrite en dernier-gagnant ;
 * un tour ecrit pendant qu'un poll est en vol disparaitrait sans erreur. C'est
 * la lecon deja consignee dans `App\Support\Loops\HelpRequestHandoff`.
 *
 * `conversation_id` : l'identifiant reutilise apres un changement de page. Le
 * Shell survit a un rechargement complet parce qu'il retrouve CE meme
 * identifiant, recalcule le PageContext de la NOUVELLE requete, et relit le
 * fil. Aucun `wire:navigate`, aucune SPA.
 *
 * `reply_to_id` UNIQUE : l'idempotence de tour (T1311, « le verrou traite la
 * course, l'idempotence traite le rejeu ») est garantie par la BASE, pas par
 * une lecture-puis-ecriture. En PostgreSQL comme en SQLite, un index unique
 * tolere plusieurs NULL — les messages humains ne se genent donc pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_shell_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // L'identifiant de conversation REUTILISE d'une page a l'autre
            // (decision MASTER 27/08) : il n'y a pas de ligne « conversation »
            // a maintenir, seulement un identifiant stable porte par les
            // messages. Il change quand — et seulement quand — l'utilisateur
            // efface son fil.
            $table->uuid('conversation_id');
            $table->string('role', 20); // 'user' | 'assistant'
            $table->text('content');
            $table->uuid('reply_to_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index(['user_id', 'organization_id', 'created_at'], 'ai_shell_messages_thread_index');
            $table->index(['conversation_id', 'created_at'], 'ai_shell_messages_conversation_index');
            $table->unique('reply_to_id', 'ai_shell_messages_reply_to_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_shell_messages');
    }
};
