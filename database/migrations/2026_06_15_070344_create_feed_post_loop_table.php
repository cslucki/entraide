<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_post_loop', function (Blueprint $table) {
            $table->foreignUuid('feed_post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['feed_post_id', 'loop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_post_loop');
    }
};
