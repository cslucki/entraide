<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_agent_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('member_ai_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('profile_owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('visitor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visitor_session_id', 100)->nullable()->index();
            $table->string('title')->nullable();
            $table->timestamps();

            $table->index(['profile_owner_user_id', 'organization_id']);
            $table->index(['visitor_user_id', 'profile_owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_agent_conversations');
    }
};
