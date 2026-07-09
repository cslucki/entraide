<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_todo_threads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('todo_id')->constrained('blog_todos')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['todo_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_todo_threads');
    }
};
