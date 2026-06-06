<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->integer('service_points_min')->nullable()->after('welcome_points');
            $table->integer('service_points_max')->nullable()->after('service_points_min');
            $table->boolean('header_javascript_enabled')->default(false)->after('global_color_mode');
            $table->text('header_javascript')->nullable()->after('header_javascript_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'service_points_min',
                'service_points_max',
                'header_javascript_enabled',
                'header_javascript',
            ]);
        });
    }
};
