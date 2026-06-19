<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_ai_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->boolean('generate_enabled')->default(true);
            $table->boolean('correct_enabled')->default(true);
            $table->unsignedTinyInteger('generate_limit')->default(3);
            $table->unsignedTinyInteger('correct_limit')->default(3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_ai_configs');
    }
};
