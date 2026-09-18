<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1439 — UsageReference V1 (Shell Welcome V3 §9, MASTER Q66).
 *
 * « A quoi sert cette surface et comment l'utilise-t-on ? » — un texte CURE,
 * versionne, publie par un humain, PLATEFORME-ONLY : aucune colonne
 * `organization_id`, par construction (la personnalisation tenant vit dans le
 * contexte public de l'Organization et dans le PageContext, jamais ici).
 *
 * Une ligne = une version d'une (surface, locale). Au plus UNE version
 * `published` par (surface, locale) : index unique partiel (PostgreSQL et
 * SQLite), l'historique reste (`retired`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('surface_key', 40)->index();
            $table->string('locale', 5);
            $table->string('title', 160);
            $table->text('content');
            $table->unsignedInteger('version');
            $table->string('state', 20)->index();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['surface_key', 'locale', 'version'], 'usage_references_surface_locale_version_unique');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX usage_references_one_published ON usage_references (surface_key, locale) WHERE state = 'published'");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_references');
    }
};
