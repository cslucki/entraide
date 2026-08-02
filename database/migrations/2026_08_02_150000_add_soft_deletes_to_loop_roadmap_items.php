<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap Premium — archive actions (soft delete) instead of destroying them. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('loop_roadmap_items', 'deleted_at')) {
            Schema::table('loop_roadmap_items', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('loop_roadmap_items', 'deleted_at')) {
            Schema::table('loop_roadmap_items', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
