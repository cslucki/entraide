<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_todos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('status', 20)->default('todo');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['blog_post_id', 'position']);
            $table->index(['blog_post_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_todos');
    }
};
