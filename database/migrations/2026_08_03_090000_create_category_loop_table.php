<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1076 — a Loop can carry 0 to 3 domains from its Organization's Annuaire
 * referential (Category). Deliberately NOT linked to the fine-grained skills
 * (Skill): domains alone are enough for discovery and filtering, and keep the
 * taxonomy readable for an Organization of ~200 people.
 *
 * The "max 3" rule is enforced in the application layer (LoopController), not by
 * a DB constraint — same style as the other Loop invariants in this codebase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_loop', function (Blueprint $table) {
            $table->foreignUuid('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['category_id', 'loop_id']);
            $table->index('loop_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_loop');
    }
};
