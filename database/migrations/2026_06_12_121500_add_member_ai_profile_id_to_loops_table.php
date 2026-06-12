<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loops', function (Blueprint $table) {
            $table->foreignUuid('member_ai_profile_id')
                ->nullable()
                ->after('created_by')
                ->constrained('member_ai_profiles')
                ->nullOnDelete();
            $table->index('member_ai_profile_id');
        });
    }

    public function down(): void
    {
        Schema::table('loops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('member_ai_profile_id');
        });
    }
};
