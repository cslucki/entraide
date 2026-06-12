<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_ai_profile_interactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('member_ai_profile_id')->constrained('member_ai_profiles')->cascadeOnDelete();
            $table->foreignUuid('profile_owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('visitor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visitor_type', 20)->default('guest')->index();
            $table->string('provider')->nullable()->index();
            $table->string('model')->nullable()->index();
            $table->string('status', 20)->default('success')->index();
            $table->text('question');
            $table->text('response')->nullable();
            $table->json('matched_fields')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['profile_owner_user_id', 'created_at']);
            $table->index(['member_ai_profile_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['visitor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_ai_profile_interactions');
    }
};
