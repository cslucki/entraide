<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1907 — fichiers PRIVES d'un article (PDF, audio).
 *
 * Le fichier vit sur un disque prive ; le HTML de l'article ne porte que son
 * identifiant. Il est lu par une route qui applique les droits de l'article
 * (`BlogPostReadAccess`). Tant qu'il n'est rattache a aucun article
 * (`blog_post_id` NULL : depose dans l'editeur, pas encore enregistre), seul
 * son deposant le lit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('blog_post_id')->nullable()->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->text('extracted_text')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'blog_post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_files');
    }
};
