<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1694 bug R — « Possibilite modifier emails types », par Organization.
 *
 * ## La demande
 *
 * Cyril : « Je pense qu'on va aussi desactiver le fait qu'une organisation
 * puisse modifier les emails types [...] avec une case a cocher ».
 *
 * ## Pourquoi `false` par defaut
 *
 * C'est la demande lue au pied de la lettre : la capacite est RETIREE, et
 * rendue au cas par cas. Un defaut a `true` aurait laisse la situation
 * inchangee et fait de la case une decoration.
 *
 * Ce defaut s'applique aux Organizations DEJA en base : aucune ne peut plus
 * editer ses gabarits tant que la case n'est pas cochee. C'est un retrait de
 * capacite, pas une perte de donnees — les lignes deja ecrites restent, et le
 * resolveur (bug M) continue de les servir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('system_emails_editable')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('system_emails_editable');
        });
    }
};
