<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('loop_id');
            $table->uuid('sender_id')->nullable();
            $table->text('body');
            $table->string('type', 20)->default('user');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('loop_id')->references('id')->on('loops')->onDelete('cascade');
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('set null');

            $table->index(['loop_id', 'created_at']);
            $table->index('sender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_messages');
    }
};
