<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap Premium — multiple assignees (max 3, enforced in the app layer).
 * Replaces the single `loop_roadmap_items.assigned_to` FK with a pivot table.
 * Existing single assignees are migrated into the pivot before the column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loop_roadmap_item_user')) {
            Schema::create('loop_roadmap_item_user', function (Blueprint $table) {
                $table->id();
                $table->foreignUuid('loop_roadmap_item_id')->constrained('loop_roadmap_items')->cascadeOnDelete();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['loop_roadmap_item_id', 'user_id']);
            });
        }

        // Backfill existing single assignees into the pivot.
        if (Schema::hasColumn('loop_roadmap_items', 'assigned_to')) {
            $rows = DB::table('loop_roadmap_items')->whereNotNull('assigned_to')->get(['id', 'assigned_to']);
            foreach ($rows as $row) {
                DB::table('loop_roadmap_item_user')->updateOrInsert(
                    ['loop_roadmap_item_id' => $row->id, 'user_id' => $row->assigned_to],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }

            Schema::table('loop_roadmap_items', function (Blueprint $table) {
                // Drop the FK before the column on every driver (Laravel rebuilds SQLite),
                // otherwise SQLite leaves a dangling foreign key definition.
                $table->dropForeign(['assigned_to']);
                $table->dropColumn('assigned_to');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('loop_roadmap_items', 'assigned_to')) {
            Schema::table('loop_roadmap_items', function (Blueprint $table) {
                if (DB::getDriverName() === 'sqlite') {
                    $table->uuid('assigned_to')->nullable();
                } else {
                    $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('loop_roadmap_item_user')) {
            // Restore the first assignee per item.
            $rows = DB::table('loop_roadmap_item_user')->orderBy('id')->get(['loop_roadmap_item_id', 'user_id']);
            foreach ($rows as $row) {
                DB::table('loop_roadmap_items')
                    ->where('id', $row->loop_roadmap_item_id)
                    ->whereNull('assigned_to')
                    ->update(['assigned_to' => $row->user_id]);
            }

            Schema::dropIfExists('loop_roadmap_item_user');
        }
    }
};
