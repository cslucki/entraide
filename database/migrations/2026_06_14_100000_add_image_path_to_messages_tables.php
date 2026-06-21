<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('loop_messages', 'image_path')) {
            Schema::table('loop_messages', function (Blueprint $table) {
                $table->string('image_path', 255)->nullable()->after('body');
            });
        }

        if (! Schema::hasColumn('messages', 'image_path')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->string('image_path', 255)->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('loop_messages', 'image_path')) {
            Schema::table('loop_messages', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }

        if (Schema::hasColumn('messages', 'image_path')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }
};
