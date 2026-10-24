<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1695 geste A — une autorisation posee sur UN FICHIER.
 *
 * ## Ce que cette table rend possible
 *
 * Le partage vivait jusqu'ici sur le DOSSIER (`dossier_members`). Mesure du
 * 24/10 sur OFSH : la creation de dossiers a ete supprimee (TASK-1629) et
 * « Mes documents » ne peut pas etre une ancre de partage (TASK-1136) — un
 * membre n'avait donc AUCUN chemin pour partager un fichier.
 *
 * ## Ce qu'elle N'EST PAS
 *
 * Pas un `dossier_members` de plus. Arbitrage de Cyril (chemin « C ») :
 * LECTURE SEULE, pas de re-partage, pas d'heritage. Il n'y a donc ni colonne
 * `role` ni notion de niveau : une ligne existe, ou elle n'existe pas. Ajouter
 * un role « au cas ou » inventerait une question que personne n'a posee, et
 * chaque lecteur du code devrait ensuite deviner ce qu'un role vaut.
 *
 * ## `organization_id` est PORTE, pas deduit
 *
 * Meme doctrine que les 40 modeles de `HasOrganizationId`, et meme lecon que
 * TASK-1691 : une frontiere de tenant qui se deduit du client n'est pas une
 * frontiere. La colonne est NOT NULL et contrainte.
 *
 * ## Le partage MEURT avec le fichier
 *
 * `ON DELETE CASCADE` sur `dossier_file_id`. C'est la regle que Cyril a posee —
 * « le partage suit le fichier » — et sa consequence : un fichier supprime ne
 * laisse pas derriere lui une autorisation qui ne designe plus rien.
 *
 * Idem pour la personne (`user_id`) : un compte supprime emporte les partages
 * qu'on lui avait faits. `shared_by` est en revanche `SET NULL` — la trace du
 * partage survit a celui qui l'a pose, comme `added_by` sur `dossier_members`.
 *
 * ## L'unicite
 *
 * (fichier, personne) : partager deux fois la meme chose a la meme personne
 * n'a pas de sens, et deux lignes rendraient la revocation ambigue — il
 * faudrait en retirer une puis decouvrir que l'acces tient toujours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_file_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('dossier_file_id');
            $table->uuid('user_id');
            $table->uuid('shared_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('dossier_file_id')->references('id')->on('dossier_files')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('shared_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['dossier_file_id', 'user_id']);

            // Les deux lectures du produit : « qu'a-t-on partage avec moi ? »
            // (user_id) et « qui a acces a ce fichier ? » (dossier_file_id, deja
            // couvert par l'index unique). Le tenant est borne a chaque requete,
            // d'ou l'index qui le prefixe.
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_file_shares');
    }
};
