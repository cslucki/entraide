<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->string('type')->default('announcement');
            $table->string('title')->nullable();
            $table->text('content');
            $table->string('image_path')->nullable();
            $table->json('url_preview')->nullable();
            $table->string('status')->default('published');
            $table->timestamp('pinned_at')->nullable();
            $table->foreignUuid('pinned_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status', 'pinned_at']);
            $table->index(['organization_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_posts');
    }
};
