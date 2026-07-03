<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_ai_profiles', function (Blueprint $table) {
            $table->json('structured_profile')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('member_ai_profiles', function (Blueprint $table) {
            $table->dropColumn('structured_profile');
        });
    }
};
