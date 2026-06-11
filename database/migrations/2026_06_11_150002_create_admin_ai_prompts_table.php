<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ai_prompts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scenario_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('prompt_text');
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['scenario_id', 'version']);
            $table->index('scenario_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ai_prompts');
    }
};
