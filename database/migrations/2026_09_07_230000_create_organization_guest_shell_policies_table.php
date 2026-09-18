<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1429 — SW-1 : la politique Shell Welcome de chaque Organization.
 *
 * Une ligne par Organization (absente = DISABLED). L'autorite provider /
 * modele / cle / budget IA reste `organization_ai_settings` : rien n'est
 * duplique ici. Defauts : Addendum V2 §6 (retention 90 j) et Growth V1 §5
 * (10 tours).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_guest_shell_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('max_messages')->default(10);
            $table->unsignedSmallInteger('retention_days')->default(90);
            $table->decimal('guest_monthly_budget_usd', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_guest_shell_policies');
    }
};
