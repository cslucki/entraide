<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_analysis_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('method');
            $table->text('note_content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['blog_post_id', 'user_id']);
            $table->index(['blog_post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_analysis_notes');
    }
};
