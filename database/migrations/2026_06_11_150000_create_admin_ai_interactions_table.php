<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_ai_interactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scenario_id')->index();
            $table->string('provider')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('status')->default('success')->index();
            $table->text('input_excerpt')->nullable();
            $table->string('input_hash')->nullable()->index();
            $table->unsignedInteger('input_length')->default(0);
            $table->text('result_summary')->nullable();
            $table->json('result_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('cost_usd', 14, 8)->default(0);
            $table->timestamps();

            $table->index(['created_at']);
            $table->index(['scenario_id', 'created_at']);
            $table->index(['provider', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ai_interactions');
    }
};
