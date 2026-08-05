<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qui a archive cette Boucle, et quand.
 *
 * `loops.status` porte deja l'etat — ces deux colonnes ne le doublent pas, elles
 * repondent a une autre question. Cyril demande un archivage *tracable*, et
 * aucune table d'audit ne couvre les Boucles : sans cela, une Boucle archivee ne
 * sait dire ni depuis quand ni par qui, ce qui est precisement ce qu'on attend
 * d'une operation reversible.
 *
 * Nullables, et remises a null a la reactivation : elles decrivent l'archivage en
 * cours, pas un historique. Un historique complet serait une table, et ce n'est
 * pas le sujet de cette tache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loops', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
            // `nullOnDelete` et non `cascadeOnDelete` : la suppression d'un
            // compte ne doit pas emporter la Boucle qu'il a archivee.
            $table->foreignUuid('archived_by')->nullable()->after('archived_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn('archived_at');
        });
    }
};
