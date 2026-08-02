<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap Premium — coloured labels, scoped to a single Loop of one Organization. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('loop_roadmap_labels')) {
            return;
        }

        Schema::create('loop_roadmap_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('color', 20)->default('gray');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['loop_id']);
            $table->index(['organization_id', 'loop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_roadmap_labels');
    }
};
