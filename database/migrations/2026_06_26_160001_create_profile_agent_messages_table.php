<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_agent_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('profile_agent_conversations')->cascadeOnDelete();
            $table->string('role', 20); // 'user' or 'assistant'
            $table->text('content');
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();

            $table->index('conversation_id');
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_agent_messages');
    }
};
