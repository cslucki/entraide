<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1366 — distinguer un RETRAIT VOLONTAIRE d'une DESACTIVATION ADMIN.
 *
 * ## Pourquoi une colonne, et pas simplement `disabled`
 *
 * Le statut `disabled` porte aujourd'hui une decision d'ADMINISTRATION. Y
 * ecrire aussi « je retire mon consentement » ferait de deux evenements
 * opposes le meme etat — et ce n'est pas theorique : aujourd'hui, un profil
 * desactive par un administrateur laisse reapparaitre le bouton « Publier »
 * cote membre, et `publish()` ne verifie rien. **Un membre peut donc deja
 * annuler une sanction.**
 *
 * L'audit du 02/09 a etabli ce comportement par lecture du code. La TASK qui
 * ajoute le retrait volontaire le rendrait beaucoup plus atteignable : on ne
 * l'institutionnalise pas au moment precis ou l'on donne la main a l'humain sur
 * son propre consentement.
 *
 * ## La machine a etats, apres cette colonne
 *
 *   publication membre    status=published  withdrawn_at=null   disabled_at=null
 *   RETRAIT VOLONTAIRE    status=disabled   withdrawn_at=now()  disabled_at=now()
 *   desactivation admin   status=disabled   withdrawn_at=null   disabled_at=now()
 *
 * La republication par le membre n'est alors autorisee que si `withdrawn_at`
 * est renseigne : ce qu'un administrateur a desactive, seul un administrateur
 * le republie.
 *
 * ## Strictement ADDITIVE
 *
 * Aucune donnee n'est lue, deplacee ni supprimee. Les profils existants
 * gardent `withdrawn_at = null`, ce qui est exactement leur verite : aucun
 * d'eux n'a pu etre retire volontairement, puisque le parcours n'existait pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('member_ai_profiles') || Schema::hasColumn('member_ai_profiles', 'withdrawn_at')) {
            return;
        }

        Schema::table('member_ai_profiles', function (Blueprint $table): void {
            $table->timestamp('withdrawn_at')->nullable()->after('disabled_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('member_ai_profiles') || ! Schema::hasColumn('member_ai_profiles', 'withdrawn_at')) {
            return;
        }

        Schema::table('member_ai_profiles', function (Blueprint $table): void {
            $table->dropColumn('withdrawn_at');
        });
    }
};
