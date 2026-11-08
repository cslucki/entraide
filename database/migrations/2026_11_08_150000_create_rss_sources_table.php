<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1789 — le catalogue des flux RSS connus.
 *
 * ## Une source est PLATEFORME, pas une Organization
 *
 * Décision MASTER (précheck TASK-1789) : le même flux WordPress doit pouvoir nourrir
 * plusieurs Organizations — l'Organization reste un choix fait à l'import, par article,
 * jamais une propriété de la source. Pas de colonne `organization_id` ici, à la différence
 * de la quasi-totalité des tables métier du dépôt (`AGENTS.md` §1 : "toute fonctionnalité
 * métier est Organization-scoped" — une source RSS n'est pas une fonctionnalité métier,
 * c'est un catalogue d'outillage SuperAdmin, au même titre que d'autres tables déjà
 * dépourvues d'`organization_id` dans ce dépôt).
 *
 * Additive, non destructive : nouvelle table, rien touché ailleurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rss_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('url', 2048)->unique();
            $table->string('label')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rss_sources');
    }
};
