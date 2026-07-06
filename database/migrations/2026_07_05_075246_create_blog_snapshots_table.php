<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->string('name');
            $table->text('comment')->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('content');
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 320)->nullable();
            $table->string('status', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index(['blog_post_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_snapshots');
    }
};
