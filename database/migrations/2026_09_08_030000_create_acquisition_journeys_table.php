<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1446 — AcquisitionJourney foundation (Growth V2 §2, MASTER Q74).
 *
 * Une Journey est une DEFINITION versionnee et tenantee d'un parcours
 * d'acquisition — pas encore la trace d'un visiteur (events, attribution,
 * Shortcut, Workshops arrivent ensuite). Pas de posture Shell (la politique
 * de l'Organization reste l'unique autorite), pas de sources publiques (la
 * whitelist appartient aux capabilities), pas de CTA libre.
 *
 * Une ligne = une version. Une seule `published` par (organization, key),
 * garantie en base (index unique partiel PG + SQLite).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquisition_journeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 160);
            $table->string('locale', 5);
            $table->unsignedInteger('version');
            $table->string('state', 20)->index();
            $table->string('campaign', 100)->nullable();
            $table->string('conversion_goal', 40);
            $table->string('usage_reference_surface_key', 40)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'key', 'version'], 'acquisition_journeys_org_key_version_unique');
            $table->index(['organization_id', 'key']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX acquisition_journeys_one_published ON acquisition_journeys (organization_id, key) WHERE state = 'published'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acquisition_journeys');
    }
};
