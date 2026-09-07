<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1414 — CRM-2, le pipeline de statuts dynamique et la timeline.
 *
 * `crm_statuses` : le pipeline appartient a l'Organization (CDC §7 : « ne pas
 * utiliser un enum ferme »). Un statut se DESACTIVE, il ne se supprime pas
 * quand un historique existe — les Contacts qui le portent le gardent.
 *
 * `crm_contact_events` : la timeline du Contact, APPEND-ONLY. Une seule table
 * pour toute la campagne (arbitrage MASTER Q5) ; cette tranche n'y ecrit que
 * `status_changed`, CRM-3 y ajoutera les notes et les autres faits. Ce n'est
 * pas de l'event-sourcing : le Contact reste la verite courante, la timeline
 * est la memoire de ce qui s'est passe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_statuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('label', 60);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('color', 7)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'label']);
            $table->index(['organization_id', 'sort_order']);
        });

        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->foreignUuid('status_id')->nullable()->after('company')->constrained('crm_statuses')->nullOnDelete();
            $table->index(['organization_id', 'status_id']);
        });

        Schema::create('crm_contact_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('crm_contact_id')->constrained('crm_contacts')->cascadeOnDelete();
            $table->string('type', 40);
            $table->foreignUuid('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['crm_contact_id', 'occurred_at']);
            $table->index(['organization_id', 'type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contact_events');

        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });

        Schema::dropIfExists('crm_statuses');
    }
};
