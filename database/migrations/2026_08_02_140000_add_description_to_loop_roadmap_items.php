<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap Premium — rich description (sanitized TipTap HTML) per action. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('loop_roadmap_items', 'description')) {
            Schema::table('loop_roadmap_items', function (Blueprint $table) {
                $table->longText('description')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('loop_roadmap_items', 'description')) {
            Schema::table('loop_roadmap_items', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }
    }
};
