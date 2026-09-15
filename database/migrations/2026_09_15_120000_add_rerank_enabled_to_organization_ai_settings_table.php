<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-1563 — l'autorisation de reranker, Organization par Organization.
     *
     * `default(false)` n'est pas une precaution de style : c'est la GARANTIE
     * qu'aucune Organization existante n'est activee au deploiement. Cette
     * migration ajoute une colonne, elle n'ecrit AUCUNE donnee — au moment ou
     * elle passe, toutes les Organizations du monde basculent a « non », y
     * compris celles qui figuraient jusqu'ici dans l'allowlist d'environnement
     * de TASK-1562, desormais retiree.
     *
     * C'est voulu. Une activation doit etre un GESTE, jamais un heritage.
     */
    public function up(): void
    {
        Schema::table('organization_ai_settings', function (Blueprint $table): void {
            $table->boolean('rerank_enabled')->default(false)->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organization_ai_settings', function (Blueprint $table): void {
            $table->dropColumn('rerank_enabled');
        });
    }
};
