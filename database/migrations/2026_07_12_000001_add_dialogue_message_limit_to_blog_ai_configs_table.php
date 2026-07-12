<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_ai_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('dialogue_message_limit')->default(5)->after('correct_limit');
        });
    }

    public function down(): void
    {
        Schema::table('blog_ai_configs', function (Blueprint $table) {
            $table->dropColumn('dialogue_message_limit');
        });
    }
};
