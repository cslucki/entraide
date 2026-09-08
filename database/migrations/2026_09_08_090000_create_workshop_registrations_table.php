<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1453 — signup / resumption / registration (Growth V3 §7, §10, §11 ;
 * MASTER Q78) : l'inscription REELLE d'un User verifie a une session.
 *
 * Participation confirmee seulement apres : compte reel + email Verified +
 * geste explicite. unique(session, user). La provenance Guest (visiteur
 * rattache au claim, Journey exacte) est reprise sur la ligne. La capacite est
 * controlee au moment de l'inscription (le moment canonique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('workshop_id')->constrained('workshops')->cascadeOnDelete();
            $table->foreignUuid('workshop_session_id')->constrained('workshop_sessions')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('guest_visitor_id')->nullable()->constrained('guest_visitors')->nullOnDelete();
            $table->foreignUuid('acquisition_journey_id')->nullable()->constrained('acquisition_journeys')->nullOnDelete();
            $table->string('status', 20);
            $table->timestamp('registered_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['workshop_session_id', 'user_id']);
            $table->index(['organization_id', 'workshop_session_id', 'status']);
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_registrations');
    }
};
