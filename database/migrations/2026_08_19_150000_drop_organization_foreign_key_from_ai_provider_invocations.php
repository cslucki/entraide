<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1254 : la ligne du ledger canonique SURVIT a la suppression de
 * l'Organization (G12 du gap analysis T1246).
 *
 * `ai_provider_invocations.organization_id` portait une FK `ON DELETE CASCADE`
 * vers `organizations` : supprimer un tenant (`AdminOrganizationController::
 * destroy()` fait un `forceDelete()` reel) effacait toute son histoire
 * economique — et la facture plateforme correspondante — alors que `user_id`,
 * lui, n'a jamais eu de FK (« la ligne economique survit a la suppression du
 * compte », TASK-1220). Un ledger economique durable ne peut pas dependre de la
 * vie du tenant.
 *
 * Choix : RETRAIT de la FK, symetrique de `user_id` — pas `SET NULL` :
 *  - le registre est append-only (aucune mise a jour apres ecriture) : un
 *    `ON DELETE SET NULL` reecrirait le tenant de record de lignes du ledger ;
 *  - l'uuid du tenant supprime reste lisible (releves, export, audit) au lieu
 *    de se confondre avec tous les autres tenants supprimes ;
 *  - `organization_id` reste NOT NULL : chaque ligne ecrite a un tenant de
 *    record (regle d'attribution TASK-1253), le schema continue de l'exiger.
 *
 * Migration purement additive / non destructive : aucune ligne touchee, aucune
 * valeur changee, aucune colonne ni aucun index retire — seul le comportement
 * FUTUR d'une suppression d'Organization change. PostgreSQL : DROP CONSTRAINT
 * du nom par defaut Laravel ; SQLite : reconstruction de table par le schema
 * builder (lignes copiees, index conserves).
 *
 * `down()` remet la FK CASCADE sans toucher aux lignes : si des lignes
 * orphelines existent (tenant supprime apres `up()`), la contrainte echoue —
 * volontairement, un rollback ne supprime pas d'histoire economique en silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_invocations', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_invocations', function (Blueprint $table) {
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });
    }
};
