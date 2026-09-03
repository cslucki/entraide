<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1377 — rattacher la preuve historique a la notification qui l'a causee.
 *
 * ## Pourquoi etendre `email_logs` plutot que creer une table
 *
 * Parce qu'un troisieme moteur de preuve serait un troisieme endroit ou aller
 * chercher la verite. `email_logs` est deja la trace historique des envois ;
 * elle le reste, et gagne juste de quoi repondre a « quelle notification a
 * produit cet email ? ».
 *
 * ## Toutes ces colonnes sont NULLABLE, et ce n'est pas de la prudence
 *
 * La table contient deja des lignes ecrites par des appelants qui n'ont aucun
 * rapport avec les notifications — le test d'envoi de l'administration, par
 * exemple. Les rendre obligatoires casserait ces chemins, ou forcerait a
 * inventer une valeur. Un email sans notification est un cas NORMAL.
 *
 * ## `body_hash` sert a constater, pas a dedupliquer
 *
 * Il repond a « le corps envoye est-il bien celui qu'on croit ? » sans avoir a
 * comparer deux textes longs, et permet de reperer qu'un meme contenu est parti
 * deux fois. Il n'est PAS une cle d'unicite : deux envois legitimes peuvent
 * partager un corps identique, et c'est `UNIQUE(notification_id, channel)` sur
 * les livraisons qui garantit l'unicite.
 *
 * ## `locale` est celle de l'ENVOI
 *
 * Elle est figee au moment ou le corps est rendu, parce que c'est la seule chose
 * qu'on puisse affirmer plus tard : la preference du destinataire, elle, aura pu
 * changer entre-temps. Relire l'utilisateur ne dirait pas dans quelle langue le
 * message est REELLEMENT parti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->uuid('notification_id')->nullable()->after('user_id');

            // Pas de contrainte de cle etrangere : `email_logs` est une trace
            // HISTORIQUE. Supprimer une notification ne doit pas effacer la
            // preuve qu'un email est parti, ni faire echouer la suppression.
            $table->index('notification_id', 'email_logs_notification_index');

            $table->string('locale', 10)->nullable()->after('subject');
            $table->text('body_html')->nullable()->after('locale');
            $table->string('body_hash', 64)->nullable()->after('body_html');
        });
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex('email_logs_notification_index');
            $table->dropColumn(['notification_id', 'locale', 'body_html', 'body_hash']);
        });
    }
};
