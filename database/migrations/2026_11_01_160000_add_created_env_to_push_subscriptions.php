<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1740 — d'OU vient cet abonnement ?
 *
 * ## Le besoin, et pourquoi une colonne plutot qu'un raisonnement
 *
 * MASTER, 01/11 : « envoi limite aux abonnements CREES LOCALEMENT ». Jusqu'ici
 * la garantie reposait sur un raisonnement : `push_subscriptions` est nee avec
 * T1739, elle n'a jamais figure dans un export de production, donc chaque
 * ligne y est locale.
 *
 * Le raisonnement est juste AUJOURD'HUI, et il cesse de l'etre au premier
 * dump de production qui inclura la table — c'est-a-dire des la prochaine
 * reconstruction du jumeau. Un raisonnement qui expire n'est pas une garde.
 *
 * La colonne, elle, voyage AVEC la ligne : un abonnement importe portera
 * `production`, et le filtre de recette l'ecartera sans que personne ait a se
 * souvenir de cette conversation.
 *
 * ## Pourquoi `nullable`
 *
 * Les lignes DEJA presentes ne peuvent pas etre datees retroactivement. Elles
 * restent a `null`, et c'est le filtre qui tranche : un `null` ne vaut pas
 * « locale », il vaut « origine inconnue ». Les abonnements existants devront
 * donc etre refaits pour redevenir eligibles a la recette — c'est un cout
 * reel, assume, et prefere a une valeur par defaut qui mentirait.
 *
 * Non destructive : un `ADD COLUMN` nullable, rien d'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            // La valeur d'`app.env` au moment de la revendication. Courte par
            // nature (`local`, `production`, `ai-validation`).
            $table->string('created_env', 40)->nullable()->after('device_label');
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('created_env');
        });
    }
};
