<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le lien qui ferme la perte nommee par le North Star.
 *
 * « Une decision n'est pas transformee en action » figure dans la liste de ce
 * que BouclePro existe pour eviter. Une Card Decisions qui ne sait pas produire
 * d'action n'en traite que la moitie.
 *
 * **Additif et nullable** : un item de Roadmap n'a aucune obligation de venir
 * d'une Decision, et tout le parc existant reste valide sans une ecriture.
 *
 * `nullOnDelete` et non `cascadeOnDelete` : retirer une Decision ne doit
 * **jamais** emporter les actions qu'elle a lancees. Elles ont leur vie propre —
 * quelqu'un les a peut-etre deja faites.
 *
 * Le lien est pose ici, sur `loop_roadmap_items`, et non l'inverse : une
 * Decision peut engendrer **plusieurs** actions, et une colonne sur la Decision
 * n'en aurait tenu qu'une.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_roadmap_items', function (Blueprint $table) {
            $table->foreignUuid('loop_decision_id')->nullable()->after('loop_id')
                ->constrained('loop_decisions')->nullOnDelete();

            $table->index('loop_decision_id');
        });
    }

    public function down(): void
    {
        Schema::table('loop_roadmap_items', function (Blueprint $table) {
            $table->dropIndex(['loop_decision_id']);
            $table->dropConstrainedForeignId('loop_decision_id');
        });
    }
};
