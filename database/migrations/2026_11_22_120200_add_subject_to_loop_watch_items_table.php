<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1879 (demande Cyril, 22/11) — le « Sujet » d'une veille : une phrase
 * qui la resume. Additif seulement. Nullable en base pour les lignes
 * anterieures ; obligatoire a la saisie (LoopWatchService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_watch_items', function (Blueprint $table) {
            $table->string('subject', 160)->nullable()->after('observed_on');
        });
    }

    public function down(): void
    {
        Schema::table('loop_watch_items', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};
