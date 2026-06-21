<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->nullable();
        });

        $defaultId = DB::table('settings')->where('key', 'default_organization_id')->value('value');
        if ($defaultId) {
            DB::table('organizations')->where('id', $defaultId)->update(['is_default' => true]);
        }

        if (! DB::table('organizations')->where('is_default', true)->exists()) {
            $fallbackId = DB::table('organizations')->where('is_active', true)->orderBy('created_at')->value('id');
            if ($fallbackId) {
                DB::table('organizations')->where('id', $fallbackId)->update(['is_default' => true]);
            }
        }

        DB::table('settings')->where('key', 'default_organization_id')->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX organizations_is_default_unique ON organizations (is_default) WHERE is_default = true');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS organizations_is_default_unique');
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
