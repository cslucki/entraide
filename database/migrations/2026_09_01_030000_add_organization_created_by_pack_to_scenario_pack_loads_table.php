<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1351 — provenance de l'Organization d'un chargement.
 *
 * Un pack qui implemente
 * {@see \App\Support\ScenarioPacks\Contracts\ProvisionsItsOrganization} peut
 * creer son Organization quand elle n'existe pas. Le retrait doit alors pouvoir
 * revenir a l'etat ABSENT — mais UNIQUEMENT si c'est bien ce chargement qui l'a
 * creee. Une Organization preexistante, meme vide, n'appartient a personne et
 * ne doit jamais disparaitre parce qu'un pack a ete retire.
 *
 * Cette provenance ne peut pas vivre dans `scenario_pack_entities` : le
 * registrar exige un `organization_id` sur chaque entite inscrite, et une
 * Organization n'en porte pas. Elle vit donc ici, sur le chargement lui-meme,
 * en une seule colonne additive.
 *
 * `false` par defaut, y compris pour les lignes deja en base : les deux packs
 * existants ne provisionnent rien, et leur comportement de retrait reste
 * exactement celui d'avant cette migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_pack_loads', function (Blueprint $table) {
            $table->boolean('organization_created_by_pack')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('scenario_pack_loads', function (Blueprint $table) {
            $table->dropColumn('organization_created_by_pack');
        });
    }
};
