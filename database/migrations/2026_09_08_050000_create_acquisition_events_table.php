<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1449 — Growth Workshops Acquisition V3 §5 (MASTER Q77) : journal
 * d'acquisition APPEND-ONLY et leger — une ligne par fait, jamais mise a jour,
 * jamais supprimee par le produit. Pas de second event sourcing general.
 *
 * Dimensions : Organization (obligatoire), Journey en version EXACTE (la ligne
 * publiee au moment du fait), GuestVisitor / GuestConversation / User nullables,
 * referrer / UTM / shortcut / locale bornes, metadata bornee. Les colonnes
 * Workshop / WorkshopSession seront ajoutees PAR les TASKs qui possederont ces
 * primitives (aucune colonne anticipee). Aucune IP, aucun User-Agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('event', 40);
            $table->foreignUuid('acquisition_journey_id')->nullable()->constrained('acquisition_journeys')->nullOnDelete();
            $table->foreignUuid('guest_visitor_id')->nullable()->constrained('guest_visitors')->nullOnDelete();
            $table->foreignUuid('guest_conversation_id')->nullable()->constrained('guest_conversations')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('referrer', 500)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('shortcut', 32)->nullable();
            $table->string('locale', 5)->nullable();
            $table->json('metadata')->nullable();
            // Faits de cycle de vie naturellement uniques (guest_created:{visitor}, account_created:{user}...) ;
            // NULL pour les faits repetables (shortcut_opened : une ligne par ouverture).
            $table->string('dedupe_key', 120)->nullable()->unique();
            $table->timestamp('created_at');

            $table->index(['organization_id', 'event', 'created_at']);
            $table->index(['organization_id', 'guest_visitor_id']);
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_events');
    }
};
