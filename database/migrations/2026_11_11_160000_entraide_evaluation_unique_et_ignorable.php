<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1806 — une seule evaluation par transaction, et la possibilite de ne
 * pas en laisser.
 *
 * `reviews.unique(transaction_id, reviewer_id)` autorisait DES L'ORIGINE un
 * avis de chaque cote (aide ET aidant). Le correctif applicatif
 * (`ReviewPolicy`/`ReviewController`) empeche desormais l'aidant d'en creer
 * un, mais la contrainte DB reste la derniere garde si ce correctif devait
 * regresser — `unique(transaction_id)` seul interdit litteralement une
 * deuxieme ligne, quel que soit son auteur.
 *
 * Verifie avant ecriture (lecture seule, zero ecriture) : zero ligne
 * `reviews` en PROD et sur le jumeau local au 11/11/2026 — aucune donnee a
 * nettoyer, migration purement additive/contrainte.
 *
 * `reviews_skipped_at` est additive sur `transactions` : une personne qui
 * choisit de ne pas evaluer doit pouvoir le distinguer de « pas encore
 * evalue » (exigence produit T1806 §3.B), sans toucher a `reviews`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('reviews_skipped_at')->nullable()->after('completed_at');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['transaction_id', 'reviewer_id']);
            $table->unique('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['transaction_id']);
            $table->unique(['transaction_id', 'reviewer_id']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('reviews_skipped_at');
        });
    }
};
