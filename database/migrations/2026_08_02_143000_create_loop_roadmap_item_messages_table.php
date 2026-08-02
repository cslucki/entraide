<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap Premium — a dedicated per-action comment thread (NOT LoopMessage). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('loop_roadmap_item_messages')) {
            return;
        }

        Schema::create('loop_roadmap_item_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->foreignUuid('loop_roadmap_item_id')->constrained('loop_roadmap_items')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['loop_roadmap_item_id', 'created_at']);
            $table->index(['organization_id', 'loop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_roadmap_item_messages');
    }
};
