<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('member_ai_profiles');

        Schema::create('member_ai_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->string('status', 30)->default('draft')->index();
            $table->string('locale', 5)->default('fr');

            $table->text('member_profile_summary')->nullable();
            $table->text('service_scope')->nullable();
            $table->text('experience_context')->nullable();
            $table->string('preferred_contact_action', 50)->nullable();
            $table->string('tone', 30)->nullable();
            $table->text('generated_summary')->nullable();

            $table->json('target_audience')->nullable();
            $table->json('problems_helped')->nullable();
            $table->json('skills')->nullable();
            $table->json('help_types')->nullable();
            $table->json('boundaries')->nullable();
            $table->json('good_request_examples')->nullable();
            $table->json('bad_request_examples')->nullable();
            $table->json('wizard_state')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('validated_at')->nullable();
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_ai_profiles');
    }
};
