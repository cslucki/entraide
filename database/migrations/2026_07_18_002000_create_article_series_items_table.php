<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_series_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('article_series_id')->constrained('article_series')->cascadeOnDelete();
            $table->foreignUuid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('blog_post_id');
            $table->index(['article_series_id', 'position']);
            $table->index(['organization_id', 'article_series_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_series_items');
    }
};
