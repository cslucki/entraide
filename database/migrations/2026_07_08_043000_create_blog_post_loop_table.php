<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_post_loop', function (Blueprint $table) {
            $table->foreignUuid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['blog_post_id', 'loop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_loop');
    }
};
