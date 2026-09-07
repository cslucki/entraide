<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1447 — OrganizationShortcut (Growth V2 §5, MASTER Q75) : un code court
 * global, une destination CANONIQUE resolue cote serveur (jamais une URL
 * libre : pas d'open redirect), une Journey referencee par cle (resolue
 * `published` au clic), une campagne, un interrupteur. SuperAdmin-managed V1.
 *
 * Et sur le visiteur Guest : la version EXACTE de Journey resolue au premier
 * geste (`acquisition_journey_id`), figee — first touch wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_shortcuts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->string('destination', 30);
            $table->string('acquisition_journey_key', 60)->nullable();
            $table->string('campaign', 100)->nullable();
            $table->boolean('active')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'active']);
        });

        Schema::table('guest_visitors', function (Blueprint $table) {
            $table->foreignUuid('acquisition_journey_id')->nullable()->after('shortcut')->constrained('acquisition_journeys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guest_visitors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('acquisition_journey_id');
        });
        Schema::dropIfExists('organization_shortcuts');
    }
};
