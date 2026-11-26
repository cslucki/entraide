<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1904 — les rapports de bugs deviennent les « Signalements », categorises.
 *
 * NON destructive : ajout d'une colonne avec valeur par defaut. Les lignes
 * existantes (toutes des rapports de bugs) deviennent `technical_bug` sans
 * reecriture. Aucune contrainte CHECK : la liste des categories est validee
 * par l'application (BugReport::CATEGORIES).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bug_reports', function (Blueprint $table) {
            $table->string('category', 40)->default('technical_bug');
            $table->index(['category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('bug_reports', function (Blueprint $table) {
            $table->dropIndex(['category', 'status']);
            $table->dropColumn('category');
        });
    }
};
