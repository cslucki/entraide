<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['loop_members', 'loop_messages'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'organization_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
                    $t->index('organization_id');
                });

                DB::statement("UPDATE {$table} SET organization_id = (
                    SELECT organization_id FROM loops WHERE loops.id = {$table}.loop_id
                )");
            }
        }
    }

    public function down(): void
    {
        foreach (['loop_members', 'loop_messages'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'organization_id')) {
                $tableName = $table;

                Schema::table($table, function (Blueprint $t) use ($tableName) {
                    $t->dropConstrainedForeignId('organization_id');
                });
            }
        }
    }
};
