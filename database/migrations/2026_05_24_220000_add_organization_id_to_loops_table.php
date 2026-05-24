<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('loops', 'organization_id')) {
            return;
        }

        Schema::table('loops', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->index();
        });

        DB::statement('UPDATE loops SET organization_id = community_id WHERE organization_id IS NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        if (! Schema::hasColumn('loops', 'organization_id')) {
            return;
        }

        Schema::table('loops', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });
    }
};
