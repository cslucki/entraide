<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1642 — provenance "sandbox Scenario Manifest" portee par
 * l'Organization elle-meme.
 *
 * ## Pourquoi une colonne, apres avoir cherche a l'eviter
 *
 * `ScenarioPackOrganizationGuard` autorise une cible en comparant son slug a
 * `config('scenario_packs.allowed_organizations')`, tableau STATIQUE et
 * commite. C'est exactement ce qu'on veut pour les quatre packs historiques :
 * ajouter un slug y exige un commit revu et un deploiement.
 *
 * Une sandbox Manifest ne peut pas y figurer : son slug est choisi par
 * BouclePro AU MOMENT du Load (`organization.proposed_slug` n'est qu'une
 * suggestion, et une collision doit produire une AUTRE sandbox). Un slug
 * decide au runtime ne peut pas etre dans un tableau commite.
 *
 * Les alternatives ont ete examinees et ecartees, chacune pour une raison de
 * securite et non de confort :
 *
 *  - rendre l'allowlist modifiable au runtime serait une permission pilotable
 *    par le chemin meme qui demande l'autorisation ;
 *  - `scenario_pack_loads.organization_created_by_pack` est ecrite APRES un
 *    chargement reussi, alors que le garde tourne AVANT toute ecriture : elle
 *    ne peut pas autoriser le chargement qui la cree ;
 *  - `scenario_pack_entities` exige un `organization_id` par entite, qu'une
 *    Organization ne porte pas ;
 *  - `is_active` / `is_public` / `is_default` sont des drapeaux PRODUIT ; leur
 *    faire porter un sens de securite melangerait deux autorites.
 *
 * ## Ce que la colonne est, et n'est pas
 *
 * C'est un fait de PROVENANCE, horodate et auditable : "cette ligne a ete
 * creee comme sandbox de scenario, par le serveur, a cet instant". Ce n'est
 * pas un reglage, pas une permission, pas un drapeau basculable. Elle n'est
 * jamais `fillable` sur le modele : aucun mass assignment, aucun champ de
 * formulaire et aucune cle de manifeste ne peut l'atteindre. Seul le service
 * de provisioning l'ecrit, sur une Organization qu'il vient de creer, dans la
 * meme transaction.
 *
 * Nullable, sans FK, sans backfill : aucune ligne existante n'est touchee, et
 * une Organization cliente reste a `null` pour toujours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('scenario_sandbox_created_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('scenario_sandbox_created_at');
        });
    }
};
