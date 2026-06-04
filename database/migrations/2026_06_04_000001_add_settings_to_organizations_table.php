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
            $table->boolean('loops_enabled')->default(true)->after('is_default');
            $table->boolean('maintenance_mode')->default(false)->after('loops_enabled');
            $table->string('platform_name', 100)->nullable()->after('maintenance_mode');
            $table->string('platform_tagline', 255)->nullable()->after('platform_name');
            $table->string('global_color_mode', 10)->default('dark')->after('platform_tagline');
        });

        if (Schema::hasTable('organization_settings')) {
            $settings = DB::table('organization_settings')->get();
            foreach ($settings as $setting) {
                DB::table('organizations')
                    ->where('id', $setting->organization_id)
                    ->update([$setting->key => $setting->value]);
            }

            DB::table('organizations')
                ->whereNull('platform_name')
                ->update(['platform_name' => 'Entraide']);

            DB::table('organizations')
                ->whereNull('platform_tagline')
                ->update(['platform_tagline' => '']);

            Schema::dropIfExists('organization_settings');
        }
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'loops_enabled',
                'maintenance_mode',
                'platform_name',
                'platform_tagline',
                'global_color_mode',
            ]);
        });
    }
};
