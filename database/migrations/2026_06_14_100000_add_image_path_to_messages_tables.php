<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_messages', function (Blueprint $table) {
            $table->string('image_path', 255)->nullable()->after('body');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('image_path', 255)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('loop_messages', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
