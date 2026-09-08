<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1451 — B4-A Workshop Sessions foundation (Growth V3 §7, MASTER Q78) :
 * les sessions d'un atelier, Organization-scoped et coherentes avec leur
 * Workshop. Dates en UTC + fuseau IANA choisi (meme convention que
 * `loop_events`), capacite INFORMATIVE (nullable), lieu public borne.
 *
 * PAS de `meeting_url` : une donnee secrete n'entre pas avant le flux qui la
 * consomme (participant confirme). PAS d'inscription, PAS d'interet Guest ici
 * (B4-B et suivantes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('workshop_id')->constrained('workshops')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->string('status', 20);
            $table->string('location', 255)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'workshop_id', 'starts_at']);
            $table->index(['workshop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_sessions');
    }
};
