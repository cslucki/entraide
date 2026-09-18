<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1452 — B4-B Guest session selection / interest (Growth V3 §10, MASTER
 * Q78) : un visiteur pseudonyme CHOISIT une session et exprime un interet.
 *
 * Un interet n'est PAS une inscription : aucun User, aucune place consommee,
 * aucune reservation ferme. Une ligne par (session, visiteur) — le geste est
 * idempotent. L'attribution reste celle du GuestVisitor (first touch) ; le
 * journal `acquisition_events` porte `session_selected`. La reprise apres
 * inscription/verification (flux canonique) relira cette ligne par le claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_session_interests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('workshop_id')->constrained('workshops')->cascadeOnDelete();
            $table->foreignUuid('workshop_session_id')->constrained('workshop_sessions')->cascadeOnDelete();
            $table->foreignUuid('guest_visitor_id')->constrained('guest_visitors')->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamp('selected_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->unique(['workshop_session_id', 'guest_visitor_id']);
            $table->index(['organization_id', 'workshop_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_session_interests');
    }
};
