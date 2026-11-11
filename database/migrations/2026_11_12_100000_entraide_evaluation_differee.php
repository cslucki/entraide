<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1808 (precision MASTER, 11/11 soir) — « ne pas reafficher
 * automatiquement le formulaire d'etoiles a chaque visite apres un "Plus
 * tard" ».
 *
 * `evaluation_deferred_at` est DISTINCTE de `reviews_skipped_at` : celle-ci
 * est le renoncement EXPLICITE a toute evaluation (§5), celle-la est un
 * simple report — « pas maintenant, mais librement plus tard ». Le
 * distinguer demande sa propre colonne : les confondre ferait disparaitre la
 * possibilite de noter, ce que MASTER interdit explicitement (« aucune
 * relance repetitive, aucune pression » n'est pas « aucune evaluation »).
 *
 * Additive, reversible, non destructive — meme forme que les deux
 * migrations T1808 precedentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('evaluation_deferred_at')->nullable()->after('evaluation_invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('evaluation_deferred_at');
        });
    }
};
