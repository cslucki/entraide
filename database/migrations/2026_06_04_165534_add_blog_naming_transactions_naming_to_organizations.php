<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('blog_naming')->default('b2b')->after('global_color_mode');
            $table->string('transactions_naming')->default('b2c')->after('blog_naming');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['blog_naming', 'transactions_naming']);
        });
    }
};
