<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1220 : ledger canonique des invocations provider IA.
 *
 * Une ligne = UNE tentative/appel provider economiquement reel (generation ou
 * embedding), par opposition aux trois tables de trace historiques ou
 * `correlation_id` identifie une OPERATION metier pouvant legitimement couvrir
 * plusieurs appels (TASK-1219). Ce ledger est ADDITIF : il ne remplace ni
 * `ai_interactions` (autorite economique actuelle de `AiEconomicGuard`), ni
 * `admin_ai_interactions`, ni `member_ai_profile_interactions`, et aucun
 * backfill de l'historique n'est tente — l'historique n'a pas d'identifiant
 * d'invocation fiable, le reconstruire serait une heuristique.
 *
 * Invariant 0 != inconnu, porte par le schema :
 *  - tokens non observes        -> colonnes NULL (jamais 0) ;
 *  - cout non observe           -> `provider_cost` NULL + `cost_status` unknown ;
 *  - vrai cout nul              -> `provider_cost` 0 + `cost_status` known ;
 *  - credential non prouve      -> `credential_source` unknown ;
 *  - id provider non fourni     -> `provider_invocation_id` NULL
 *    (`sdk_invocation_id` est l'uuid7 genere par le SDK, PAS un id provider).
 *
 * Aucun credential, prompt ou contenu de reponse n'entre jamais ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_invocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            // Pas de FK : la ligne economique survit a la suppression du compte.
            $table->uuid('user_id')->nullable();
            $table->string('capability', 100)->nullable();
            $table->string('process', 100)->nullable();
            // generation | embedding
            $table->string('operation', 20);
            // ingestion | query | NULL (generation, ou operation non declaree)
            $table->string('embedding_operation', 20)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('model', 150)->nullable();
            // organization | platform | user | none | unknown — uniquement prouve.
            $table->string('credential_source', 20)->default('unknown');
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->unsignedInteger('embedding_count')->nullable();
            $table->unsignedInteger('embedding_dimensions')->nullable();
            // Monnaie : decimal, jamais float.
            $table->decimal('provider_cost', 12, 6)->nullable();
            $table->string('currency', 3)->nullable();
            // known | unknown
            $table->string('cost_status', 10)->default('unknown');
            // provider_reported | catalog_estimated | unknown
            $table->string('cost_source', 20)->default('unknown');
            // success | failed
            $table->string('status', 10);
            // Classe d'exception, jamais un message (qui pourrait porter des donnees).
            $table->string('failure_reason', 255)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->string('sdk_invocation_id', 64)->nullable();
            $table->string('provider_invocation_id', 128)->nullable();
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('completed_at', 6)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'operation', 'created_at']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_invocations');
    }
};
