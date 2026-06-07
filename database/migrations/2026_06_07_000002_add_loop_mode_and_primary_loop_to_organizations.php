<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('loop_mode', 10)->default('multi')->after('loops_enabled');
            $table->uuid('primary_loop_id')->nullable()->after('loop_mode');

            $table->foreign('primary_loop_id')
                ->references('id')
                ->on('loops')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['primary_loop_id']);
            $table->dropColumn(['loop_mode', 'primary_loop_id']);
        });
    }
};
