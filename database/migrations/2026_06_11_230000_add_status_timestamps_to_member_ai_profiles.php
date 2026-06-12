<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_ai_profiles', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('validated_at');
            $table->timestamp('generated_at')->nullable()->after('published_at');
            $table->timestamp('disabled_at')->nullable()->after('generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('member_ai_profiles', function (Blueprint $table) {
            $table->dropColumn(['published_at', 'generated_at', 'disabled_at']);
        });
    }
};
