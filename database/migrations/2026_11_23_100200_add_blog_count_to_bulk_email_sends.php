<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1883 (demande Cyril, 22/11) — la newsletter peut aussi montrer les
 * derniers billets de blog : leur nombre, choisi a l'envoi (0 = aucun).
 * Additive et idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bulk_email_sends', 'blog_count')) {
            return;
        }

        Schema::table('bulk_email_sends', function (Blueprint $table) {
            $table->unsignedSmallInteger('blog_count')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bulk_email_sends', 'blog_count')) {
            return;
        }

        Schema::table('bulk_email_sends', function (Blueprint $table) {
            $table->dropColumn('blog_count');
        });
    }
};
