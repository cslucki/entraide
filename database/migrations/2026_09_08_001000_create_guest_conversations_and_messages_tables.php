<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1434 — SW-4 : la memoire produit du Shell Welcome (Addendum V2 §7,
 * cadre Cyril 07/09 21h50 §4).
 *
 * GuestConversation / GuestMessage = memoire PRODUIT (ce qui a ete dit) ;
 * `ai_provider_invocations` reste l'autorite ECONOMIQUE (ce que ca a coute) —
 * un message assistant pointe vers l'invocation qui l'a produit. Le Guest
 * n'ecrit jamais dans `ai_interactions`.
 *
 * `message_count` compte les messages `role=user` pouvant declencher une
 * reponse IA dans CETTE conversation : c'est la definition canonique du
 * `max_messages` de la politique (SW-1), appliquee AVANT tout appel provider.
 * Deux Organizations ne melangent jamais leurs conversations ; supprimer un
 * visiteur (retention) emporte ses conversations et leurs messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('guest_visitor_id')->constrained('guest_visitors')->cascadeOnDelete();
            $table->string('locale', 5)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('message_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_message_at')->nullable();
            $table->string('source', 40)->nullable();
            $table->string('source_ref')->nullable();
            $table->foreignUuid('claimed_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'guest_visitor_id', 'last_message_at']);
            $table->index(['organization_id', 'started_at']);
        });

        Schema::create('guest_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('guest_conversation_id')->constrained('guest_conversations')->cascadeOnDelete();
            $table->string('role', 10);
            $table->text('body');
            $table->foreignUuid('ai_provider_invocation_id')->nullable()->constrained('ai_provider_invocations')->nullOnDelete();
            $table->timestamps();

            $table->index(['guest_conversation_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_messages');
        Schema::dropIfExists('guest_conversations');
    }
};
