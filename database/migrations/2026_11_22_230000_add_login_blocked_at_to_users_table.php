<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1881 (demande Cyril, 22/11) — « Membres autorises a se connecter ».
 *
 * `NULL` = autorise : aucun compte n'est bloque au deploiement (outil
 * temporaire, le temps de migrer les comptes). Une date = bloque depuis.
 * Additive et idempotente ; le `down()` ne retire que cette colonne.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'login_blocked_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('login_blocked_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'login_blocked_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('login_blocked_at');
        });
    }
};
