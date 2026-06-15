<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_post_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('feed_post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->boolean('is_approved')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['feed_post_id', 'is_approved', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_post_comments');
    }
};
