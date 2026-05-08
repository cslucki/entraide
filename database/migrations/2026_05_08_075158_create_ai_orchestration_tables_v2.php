<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // master, classification, examples
            $table->text('content');
            $table->integer('version')->default(1);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('ai_interaction_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignUuid('community_id')->nullable()->constrained()->onDelete('set null');
            $table->string('provider');
            $table->text('user_input');
            $table->string('detected_intent')->nullable();
            $table->string('detected_category')->nullable();
            $table->float('confidence_score')->nullable();
            $table->json('raw_response')->nullable();
            $table->boolean('is_debug')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interaction_logs');
        Schema::dropIfExists('ai_prompts');
    }
};
