<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1441 — Shell display modes (Shell Welcome V3, MASTER Q69).
 *
 * `display_mode` = overlay | shell_first : COMMENT le Shell apparait sur
 * l'accueil public quand il est reellement pret. OFF n'est PAS un mode
 * persiste : `enabled = false` reste l'unique autorite OFF (aucune seconde
 * source de verite). Le defaut technique `overlay` ne rend rien public par
 * lui-meme : `enabled` et l'etat calcule restent les gardes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_guest_shell_policies', function (Blueprint $table) {
            $table->string('display_mode', 20)->default('overlay')->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('organization_guest_shell_policies', function (Blueprint $table) {
            $table->dropColumn('display_mode');
        });
    }
};
