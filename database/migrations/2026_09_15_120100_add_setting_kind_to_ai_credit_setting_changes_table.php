<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TASK-1563 — de quelle NATURE de reglage parle une ligne d'audit.
     *
     * La table etait generique dans sa FORME (`scope`, `organization_id`,
     * `changes` JSON, `changed_by`) mais ne portait qu'une seule nature de
     * changement. `AiUserCreditSettings::lastChange()` n'a donc jamais eu
     * besoin de filtrer autrement que par perimetre — ce qui etait vrai tant
     * qu'une seule nature existait.
     *
     * TASK-1563 en ajoute une seconde. Sans discriminant, un changement de
     * rerank remonterait sur l'ecran de monetisation PRESENTE COMME un
     * changement de credit : un mensonge silencieux sur un ecran existant.
     *
     * `default('credit')` qualifie correctement toutes les lignes deja ecrites
     * — elles sont toutes des changements de credit, par construction : c'est
     * le seul appelant qui existait.
     *
     * Aucun renommage de table : le registre RGPD
     * (`UserDataLifecycleRegistry`) nomme cette table dans une politique, et
     * la renommer depasserait tres largement cette TASK.
     */
    public function up(): void
    {
        Schema::table('ai_credit_setting_changes', function (Blueprint $table): void {
            $table->string('setting_kind', 20)->default('credit')->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('ai_credit_setting_changes', function (Blueprint $table): void {
            $table->dropColumn('setting_kind');
        });
    }
};
