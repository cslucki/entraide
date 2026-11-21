<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1873 (phase 2) — suivi PERSISTANT des lots d'optimisation.
 *
 * ## Pourquoi pas `Bus::batch` (`job_batches`)
 *
 * Les lots natifs comptent des JOBS (en attente, echoues), pas des issues
 * metier : une image ecartee ou dont l'original manque n'est pas un job
 * echoue. Ils ne savent pas non plus se mettre en PAUSE puis reprendre
 * (seulement s'annuler). Deux tables simples portent exactement ce qu'il faut :
 *
 *   `image_optimization_batches`       le lot : filtres, taille, etat ;
 *   `image_optimization_batch_items`   une ligne par IMAGE (une image a trois
 *                                      variantes compte pour une), avec son
 *                                      statut — la progression se COMPTE ici,
 *                                      cote serveur, et survit a tout
 *                                      rechargement.
 *
 * Purement additive. Aucun lot n'est cree par une migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_optimization_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Filtre choisi : NULL = toutes les Organizations.
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('surface', 40)->nullable();
            $table->unsignedInteger('requested');
            $table->unsignedInteger('total');
            $table->string('status', 30);
            $table->boolean('force')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('image_optimization_batch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('image_optimization_batches')->cascadeOnDelete();
            // Organization de l'IMAGE (NULL : avatar d'un compte sans tenant).
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->string('surface', 40);
            $table->string('disk', 40);
            $table->string('source_path', 512);
            $table->string('status', 20);
            $table->boolean('force')->default(false);
            $table->unsignedBigInteger('source_bytes')->nullable();
            $table->unsignedBigInteger('variant_bytes')->nullable();
            $table->unsignedBigInteger('served_bytes')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'disk', 'source_path']);
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_optimization_batch_items');
        Schema::dropIfExists('image_optimization_batches');
    }
};
