<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reports', 'organization_id')) {
            return;
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->constrained()->cascadeOnDelete();
        });

        $types = DB::table('reports')->distinct()->pluck('reportable_type');
        foreach ($types as $type) {
            $instance = app($type);
            $table = $instance->getTable();
            DB::statement("
                UPDATE reports
                SET organization_id = (
                    SELECT organization_id FROM {$table}
                    WHERE {$table}.id = reports.reportable_id
                )
                WHERE reports.reportable_type = '{$type}'
            ");
        }
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
