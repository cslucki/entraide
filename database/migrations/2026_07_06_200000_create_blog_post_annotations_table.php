<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_annotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('selected_text');
            $table->text('content');
            $table->string('status', 20)->default('open');
            $table->string('anchor_hash', 64)->nullable();
            $table->unsignedInteger('start_offset')->nullable();
            $table->unsignedInteger('end_offset')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['blog_post_id', 'status']);
            $table->index('organization_id');
            $table->index('user_id');
            $table->index('resolved_by');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_annotations');
    }
};
