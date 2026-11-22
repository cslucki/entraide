<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1879 — les categories de Veille d'une Boucle.
 *
 * Preparees par le proprietaire ou l'animateur, propres a UNE Boucle : aucune
 * categorie n'est partagee entre Boucles, meme dans la meme Organization.
 *
 * Une categorie ne se supprime pas, elle s'ARCHIVE (`archived_at`) : les
 * veilles historiques gardent leur rattachement, la categorie cesse seulement
 * d'etre proposee.
 *
 * Unicite du nom : par Boucle, sans tenir compte de la casse, parmi les
 * categories ACTIVES. L'index est une expression (`lower(name)`) partielle
 * (`archived_at is null`), que PostgreSQL et SQLite savent tous deux tenir. Les
 * espaces peripheriques sont retires par le service avant l'ecriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_watch_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['loop_id', 'archived_at', 'position']);
            $table->index('organization_id');
        });

        DB::statement(
            'create unique index loop_watch_categories_loop_name_active_unique '
            .'on loop_watch_categories (loop_id, lower(name)) where archived_at is null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_watch_categories');
    }
};
