<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1418 — CRM-6, la prochaine action.
 *
 * Trois colonnes sur le Contact, pas de table Task : « que dois-je faire pour
 * cette personne, et quand ? ». Une action existe si type + date sont
 * presents ; l'heure est OPTIONNELLE et stockee a part (arbitrage MASTER
 * Q18) : « appeler mardi » n'est pas « appeler mardi a 14h30 », et on
 * n'invente jamais 00:00 ou 23:59 pour le faire tenir dans un timestamp.
 *
 * L'historique (planifie / faite) vit dans la timeline, pas ici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->string('next_action_type', 20)->nullable()->after('last_interaction_at');
            $table->date('next_action_date')->nullable()->after('next_action_type');
            $table->time('next_action_time')->nullable()->after('next_action_date');
            $table->string('next_action_label', 120)->nullable()->after('next_action_time');

            $table->index(['organization_id', 'next_action_date']);
        });
    }

    public function down(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'next_action_date']);
            $table->dropColumn(['next_action_type', 'next_action_date', 'next_action_time', 'next_action_label']);
        });
    }
};
