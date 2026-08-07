<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qu'une Boucle de Reseautage met en avant : des Offres et des Demandes.
 *
 * **Aucun second systeme.** Les Offres (`services`) et les Demandes
 * (`service_requests`) existent depuis l'origine du produit, avec leurs
 * categories, leurs competences, leurs points et leurs transactions. Cette
 * table ne fait que **rattacher** ce qui existe deja a une Boucle.
 *
 * En creer un second aurait donne deux endroits ou dire la meme chose, et une
 * personne cherchant une competence aurait eu a chercher deux fois.
 *
 * C'est aussi pourquoi la Card ne porte **aucun formulaire de creation** : le
 * parcours existe (`services.create`, `requests.create`), avec ses regles de
 * categorie, de mode de livraison et de cout en points. Le dupliquer aurait
 * fait diverger les deux.
 *
 * **Un lien, pas une copie.** Le titre, la description et le statut sont lus
 * sur l'Offre ou la Demande a chaque affichage : la corriger la corrige
 * partout, et une Offre retiree cesse d'apparaitre ici.
 *
 * **Une meme Offre peut vivre dans plusieurs Boucles** — on offre la meme
 * competence dans deux reseaux — d'ou une table de liens et non une colonne
 * `loop_id` sur `services`.
 *
 * `service_id` **ou** `service_request_id`, jamais les deux : la contrainte est
 * tenue par le service et testee, comme pour le Journal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_marketplace_links', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Le cloisonnement est porte par la ligne, pas deduit de la Boucle.
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();

            // Qui l'a mise en avant — distinct de qui l'a ecrite.
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();

            // `cascadeOnDelete` : une Offre supprimee n'a plus rien a mettre en
            // avant, et son lien n'aurait plus de titre a afficher. C'est
            // l'inverse du Journal, ou l'entree gardait son propre texte.
            $table->foreignUuid('service_id')->nullable()->constrained('services')->cascadeOnDelete();
            $table->foreignUuid('service_request_id')->nullable()->constrained('service_requests')->cascadeOnDelete();

            // Un mot de la personne qui met en avant : « c'est exactement ce
            // dont Marie parlait mardi ». Facultatif.
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['loop_id', 'created_at']);
            $table->index(['organization_id', 'loop_id']);

            // La meme Offre ne se met pas en avant deux fois dans la meme
            // Boucle. Tenu **par la base** : un `SELECT` puis un `INSERT` sans
            // verrou laisse passer deux ecritures concurrentes, et
            // `lockForUpdate()` sur une ligne inexistante ne verrouille rien
            // sous PostgreSQL. La lecon vient de TASK-1104.
            //
            // Plusieurs NULL ne s'egalent ni en SQLite ni en PostgreSQL : une
            // Demande mise en avant n'entre donc pas en collision avec une
            // autre Demande, leurs `service_id` etant tous deux NULL.
            $table->unique(['loop_id', 'service_id']);
            $table->unique(['loop_id', 'service_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_marketplace_links');
    }
};
