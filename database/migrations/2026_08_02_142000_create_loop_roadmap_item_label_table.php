<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap Premium — pivot linking actions to their coloured labels. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('loop_roadmap_item_label')) {
            return;
        }

        Schema::create('loop_roadmap_item_label', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('loop_roadmap_item_id')->constrained('loop_roadmap_items')->cascadeOnDelete();
            $table->foreignUuid('loop_roadmap_label_id')->constrained('loop_roadmap_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['loop_roadmap_item_id', 'loop_roadmap_label_id'], 'roadmap_item_label_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_roadmap_item_label');
    }
};
