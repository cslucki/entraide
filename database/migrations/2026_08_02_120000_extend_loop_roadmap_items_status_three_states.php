<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap Premium (mini-kanban) — extend the two-state status (open|done) to three
 * states (todo|in_progress|done). Widen the column (PostgreSQL enforces varchar length;
 * 'in_progress' is 11 chars) and remap existing data: open -> todo, done stays done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_roadmap_items', function (Blueprint $table) {
            $table->string('status', 20)->default('todo')->change();
        });

        DB::table('loop_roadmap_items')->where('status', 'open')->update(['status' => 'todo']);
    }

    public function down(): void
    {
        DB::table('loop_roadmap_items')
            ->whereIn('status', ['todo', 'in_progress'])
            ->update(['status' => 'open']);

        Schema::table('loop_roadmap_items', function (Blueprint $table) {
            $table->string('status', 10)->default('open')->change();
        });
    }
};
