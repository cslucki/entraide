<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1076 — third naming context, strictly mirroring the existing
 * blog_naming / transactions_naming mechanism (see Category::displayName()).
 * Default 'b2c' matches transactions_naming: domain names shown to members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('loops_naming')->default('b2c')->after('transactions_naming');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('loops_naming');
        });
    }
};
