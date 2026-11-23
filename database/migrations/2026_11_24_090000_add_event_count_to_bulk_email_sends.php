<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1891 (demande Cyril, 23/11) — la newsletter montre en tete les
 * prochains evenements de l'agenda : leur nombre, choisi a l'envoi
 * (0 = aucun). Additive et idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bulk_email_sends', 'event_count')) {
            return;
        }

        Schema::table('bulk_email_sends', function (Blueprint $table) {
            $table->unsignedSmallInteger('event_count')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bulk_email_sends', 'event_count')) {
            return;
        }

        Schema::table('bulk_email_sends', function (Blueprint $table) {
            $table->dropColumn('event_count');
        });
    }
};
