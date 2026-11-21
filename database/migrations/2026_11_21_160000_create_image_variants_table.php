<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1873 — registre des variantes optimisees.
 *
 * Purement ADDITIVE : aucune colonne existante n'est touchee, aucune image
 * n'est traitee ici. Le rattrapage des images existantes ne part JAMAIS d'une
 * migration ni d'un deploiement — seulement de `images:optimize --execute`
 * ou de la console `/admin/images`, lances a la main.
 *
 * Une ligne par (disque, source, preset). La contrainte UNIQUE est la garde
 * anti-doublon : deux traitements concurrents de la meme source ne peuvent
 * pas inscrire deux variantes pour le meme preset.
 *
 * `organization_id` est NULLABLE : un avatar appartient a un compte, et un
 * compte peut n'avoir aucune Organization. FK `cascadeOnDelete` : une
 * Organization effacee definitivement emporte ses lignes de registre (les
 * fichiers derives restent, comme ses originaux — meme comportement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('surface', 40);
            $table->string('disk', 40);
            $table->string('source_path', 512);
            $table->string('preset', 40);
            $table->unsignedSmallInteger('preset_version');
            $table->string('status', 20);
            $table->string('path', 255)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('format', 10)->nullable();
            $table->unsignedBigInteger('source_bytes')->nullable();
            $table->string('source_hash', 40)->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'source_path', 'preset']);
            $table->index(['surface', 'status']);
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_variants');
    }
};
