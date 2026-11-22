<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1879 — les veilles publiees dans une Boucle.
 *
 * Une veille est une information datee (`observed_on`), son impact formule par
 * un humain, et une source HTTP(S). Elle appartient a UNE Boucle et a
 * l'Organization de cette Boucle — jamais a celle d'une session.
 *
 * Suppression DOUCE : une veille supprimee disparait de la Card et de
 * l'annonce ChatLoop (qui relit la veille, voir LoopMessageService), mais la
 * ligne reste pour l'audit.
 *
 * `category_id` : nullable (aucune categorie tant que la Boucle n'en a pas
 * prepare), et `nullOnDelete` par prudence — les categories ne sont jamais
 * supprimees par l'application, seulement archivees.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_watch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('loop_watch_categories')->nullOnDelete();
            $table->date('observed_on');
            $table->text('information');
            $table->text('impact');
            $table->string('source_url', 2048);
            // La veille survit au depart de son auteur, comme un Sondage.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // La liste de la Card : les veilles d'une Boucle, plus recentes d'abord.
            $table->index(['loop_id', 'deleted_at', 'observed_on']);
            $table->index('category_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_watch_items');
    }
};
