<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1131 / IA P1-1 — observabilité des interactions IA.
 *
 * Aucune colonne token/coût ici : cette table n'en a pas aujourd'hui et le
 * coût appartient à P1-2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_ai_profile_interactions', function (Blueprint $table) {
            $table->uuid('correlation_id')->nullable();
            $table->string('process', 100)->nullable();

            $table->index('correlation_id');
            $table->index('process');
        });
    }

    public function down(): void
    {
        Schema::table('member_ai_profile_interactions', function (Blueprint $table) {
            $table->dropIndex(['correlation_id']);
            $table->dropIndex(['process']);
            $table->dropColumn(['correlation_id', 'process']);
        });
    }
};
