<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ai_benchmark_prompts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('category')->index();
            $table->string('title');
            $table->text('prompt_text');
            $table->text('expected_output_hint')->nullable();
            $table->unsignedTinyInteger('complexity')->default(1);
            $table->json('tags')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
            $table->index(['complexity', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ai_benchmark_prompts');
    }
};
