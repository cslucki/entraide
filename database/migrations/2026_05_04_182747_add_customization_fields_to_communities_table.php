<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->uuid('admin_id')->nullable()->after('is_active');
            $table->string('hero_image')->nullable()->after('admin_id');
            $table->string('hero_title')->nullable()->after('hero_image');
            $table->text('hero_description')->nullable()->after('hero_title');
            $table->string('accent_color')->default('#6366f1')->after('hero_description');
            $table->unsignedInteger('welcome_points')->default(100)->after('accent_color');

            $table->foreign('admin_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn(['admin_id', 'hero_image', 'hero_title', 'hero_description', 'accent_color', 'welcome_points']);
        });
    }
};
