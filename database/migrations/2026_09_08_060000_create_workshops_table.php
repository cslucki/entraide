<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1450 — Workshop domain foundation (Growth Workshops Acquisition V3 §7,
 * MASTER Q76/Q77) : un domaine DEDIE, Organization-scoped — `LoopEvent` reste
 * Loop-first (loop_id obligatoire, ACL de Loop) et n'est pas reutilise.
 *
 * V1 : l'atelier lui-meme (titre, promesse, description, format, duree,
 * statut draft|published|retired, auteur/publication). Les sessions et les
 * inscriptions viennent par leurs TASKs (B4) — aucune colonne anticipee,
 * aucune `meeting_url` ici. Pas de versioning par reflexe : un atelier publie
 * reste editable par son OrgAdmin, son slug (URL publique) est fige des la
 * publication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('acquisition_journey_id')->nullable()->constrained('acquisition_journeys')->nullOnDelete();
            $table->string('slug', 80);
            $table->string('title', 160);
            $table->string('promise', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('format', 12);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('locale', 5);
            $table->string('status', 20);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshops');
    }
};
