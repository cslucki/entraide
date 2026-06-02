<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaultOrg = DB::table('organizations')->where('is_default', true)->first();
        if (! $defaultOrg) {
            return;
        }

        $keys = ['platform_name', 'platform_tagline', 'maintenance_mode', 'global_color_mode'];
        foreach ($keys as $key) {
            $value = DB::table('settings')->where('key', $key)->value('value');
            if ($value !== null) {
                DB::table('organization_settings')->updateOrInsert(
                    ['organization_id' => $defaultOrg->id, 'key' => $key],
                    ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('organization_settings')->truncate();
    }
};
