<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F0 (audit OPUS PRE-CRM-BRIDGE) — Mini-CRM V2 §6 / Growth V3 §15 : les faits
 * REPETABLES de la timeline (un par atelier, par session…) ont une identite
 * (type + reference canonique), jamais « type seul ». `dedupe_key` unique en
 * base tranche la course, comme `acquisition_events`. Les faits existants
 * gardent NULL (aucune reecriture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contact_events', function (Blueprint $table) {
            $table->string('dedupe_key', 160)->nullable()->unique()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contact_events', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
