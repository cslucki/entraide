<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->foreignUuid('root_blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('root_blog_post_id');
            $table->index(['organization_id', 'dossier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_series');
    }
};
