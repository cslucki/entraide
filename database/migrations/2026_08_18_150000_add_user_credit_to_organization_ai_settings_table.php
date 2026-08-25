<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1229 : override d'Organization du CREDIT IA par utilisateur (nombre
 * d'utilisations par mois). Additif, nullable : NULL / `platform` = le
 * reglage plateforme s'applique ; `custom` = `user_credit_monthly_uses`
 * prime ; `unlimited` = inclus, aucun blocage par credit.
 *
 * Le budget provider de l'Organization (`monthly_budget_usd`) et le credit
 * commercial d'un utilisateur sont deux notions distinctes : le premier est
 * une depense reelle en monnaie, le second un droit d'usage en utilisations.
 * Ils ne se convertissent jamais l'un dans l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_ai_settings', function (Blueprint $table) {
            $table->string('user_credit_mode', 20)->nullable()->after('monthly_budget_usd');
            $table->unsignedInteger('user_credit_monthly_uses')->nullable()->after('user_credit_mode');
        });
    }

    public function down(): void
    {
        Schema::table('organization_ai_settings', function (Blueprint $table) {
            $table->dropColumn(['user_credit_mode', 'user_credit_monthly_uses']);
        });
    }
};
