<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1616 — la configuration des trois assistants, Boucle par Boucle.
 *
 * Quatrieme maillon de la chaine. Une ligne existe seulement la ou une Boucle
 * s'est ECARTEE du defaut : l'absence de ligne signifie « la posture du
 * catalogue », et vider un champ supprime la ligne plutot que de recopier le
 * defaut en base. C'est la convention de `loop_type_settings` — un override
 * inutile figerait la posture, et une Boucle cesserait de suivre l'evolution
 * du produit sans que personne ne l'ait demande.
 *
 * `key` vaut `aperio`, `traverse` ou `limen`. Pas d'enum et pas de cle
 * etrangere : les trois vivent dans `config/loop_plugins.php`, et une cle
 * retiree du catalogue doit cesser de s'appliquer en silence, pas casser une
 * ligne. Les noms sont FIXES en V0 — ce n'est pas la base qui l'impose, c'est
 * la Product Spec, et c'est le service qui la fait respecter.
 *
 * CES LIGNES SURVIVENT A L'EXTINCTION. Desactiver le plugin dans la Boucle, ou
 * retirer la disponibilite a l'Organization, ne supprime rien ici : rallumer
 * doit rendre ses postures a la Boucle, pas la renvoyer aux defauts.
 *
 * `organization_id` est porte en plus de `loop_id` pour la meme raison que
 * dans `loop_plugins` : la garde de tenant se lit dans la requete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_ai_assistants', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('key');

            // `null` = la posture du catalogue. Ce n'est pas « pas
            // d'instruction », c'est « celle d'en haut ».
            $table->text('instruction')->nullable();

            // Un assistant actif par defaut : les trois repondent tant que
            // personne n'en a eteint un.
            $table->boolean('enabled')->default(true);

            // `null` = l'ordre canonique du catalogue (Aperio, Traverse,
            // Limen). La Product Spec le dit « eventuellement » modifiable :
            // la colonne existe, l'ecran V0 ne l'expose pas.
            $table->unsignedSmallInteger('order')->nullable();

            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['loop_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_ai_assistants');
    }
};
