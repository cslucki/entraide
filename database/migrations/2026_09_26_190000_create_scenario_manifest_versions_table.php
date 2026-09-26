<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1646 — la persistance ADMINISTRATIVE du Scenario Manager.
 *
 * ## Ce que cette table est, et ce qu'elle n'est pas
 *
 * Elle porte les VERSIONS de scenario telles que l'administration les
 * connait : le document Manifest, son etat de revue, son approbation, sa
 * provenance. Elle ne porte AUCUNE donnee metier : une ligne ici ne cree ni
 * Organization, ni User, ni Boucle. Les donnees metier naissent au Load, dans
 * la sandbox, et restent la propriete du moteur existant
 * (`scenario_pack_loads` / `scenario_pack_entities`).
 *
 * Le CDC (`TODO/SPECS/260926-CDC-scenario-manager.md` section 7.2) impose
 * **une seule table de versions**, sans table d'en-tete `scenarios` :
 * `scenario_key` est donc denormalise et indexe. Deux versions du meme
 * scenario partagent la cle et se distinguent par leur SemVer.
 *
 * ## Pourquoi il n'existe pas de colonne `loaded`
 *
 * `LOADED` est un etat DERIVE, jamais persiste (CDC 7.3 et 33.8) : une
 * version est affichee LOADED quand elle est `valid` ET que
 * `scenario_pack_load_id` pointe vers un chargement VIVANT.
 *
 * Cette derivation tient parce qu'un chargement est vivant si et seulement si
 * sa ligne existe : `ScenarioPackRemover` supprime la ligne pour de bon
 * (`$load->delete()`), `scenario_pack_loads` n'a ni colonne d'etat, ni
 * `removed_at`, ni suppression douce, et `reset_at` n'eteint rien — ce n'est
 * que l'horodatage du dernier reset. Le `nullOnDelete` ci-dessous denoue donc
 * le lien au moment exact ou la sandbox disparait, et la version redevient
 * simplement VALID. Une seconde verite persistee serait immediatement fausse.
 *
 * ### La limite exacte de cette garantie
 *
 * Elle est portee par la FK `organization_id` de `scenario_pack_loads`, qui
 * est `cascadeOnDelete` : supprimer l'Organization emporte le chargement, qui
 * denoue la version. Mais `Organization` utilise `SoftDeletes`, et une
 * cascade SQL ne se declenche que sur une suppression REELLE. Les trois
 * chemins qui detruisent une sandbox passent bien par `forceDelete()`
 * (`AdminOrganizationController`, `ScenarioPackDeleteCommand`,
 * `ManifestSandboxLoadService::discard()`), et un test le prouve de bout en
 * bout. Un `$organization->delete()` doux, lui, laisserait le lien en place :
 * la version se dirait encore chargee alors que la sandbox serait masquee.
 * Ce chemin n'existe nulle part aujourd'hui ; toute TASK ulterieure qui
 * supprimerait une sandbox doit donc employer `forceDelete()`, et non
 * decouvrir cette contrainte apres coup.
 *
 * ## Pourquoi `parent_id` peut se denouer sans rien orpheliner
 *
 * TASK-1630 a montre qu'un `SET NULL` sur le `parent_id` d'un Dossier
 * ORPHELINE son sous-arbre au lieu de le detruire. Le cas est ici different :
 * `parent_id` porte une PROVENANCE (de quelle version celle-ci a ete
 * dupliquee ou capturee), pas une CONTENANCE. Perdre ce pointeur quand
 * l'ancetre est supprime laisse une version complete et autonome — son
 * `json_source` ne depend pas de son parent.
 *
 * ## Etat : deux valeurs, et la garde est applicative
 *
 * `state` n'accepte que `draft` et `valid`. La contrainte CHECK n'est posee
 * qu'en PostgreSQL : SQLite ne sait pas ajouter de CHECK a une table
 * existante, et la doctrine du projet est que l'uniformite entre moteurs
 * s'obtient au niveau applicatif.
 *
 * Ces CHECK ne sont donc PAS la garde principale, seulement le dernier
 * rempart du moteur qui sait les porter. La garde qui vaut sur les deux
 * moteurs est le `booted()` de `ScenarioManifestVersion`, qui refuse tout
 * `state`, `usage` ou `origin` hors liste a l'enregistrement. Les deux sont
 * eprouves : la garde applicative sur les deux moteurs, le CHECK par une
 * insertion qui contourne le modele, en PostgreSQL seulement.
 */
return new class extends Migration
{
    private const CONTRAINTE_ETAT = 'scenario_manifest_versions_state_check';

    private const CONTRAINTE_USAGE = 'scenario_manifest_versions_usage_check';

    private const CONTRAINTE_ORIGINE = 'scenario_manifest_versions_origin_check';

    public function up(): void
    {
        Schema::create('scenario_manifest_versions', function (Blueprint $table) {
            // La cle primaire est declaree en COMMANDE EXPLICITE, et non par le
            // modificateur fluide `->primary()` comme ailleurs dans le projet.
            //
            // Ce n'est pas une coquetterie. `parent_id` reference cette meme
            // table, et Laravel emet les `ALTER TABLE ... ADD CONSTRAINT` des
            // cles etrangeres AVANT l'`ALTER TABLE ... ADD PRIMARY KEY` produit
            // par le modificateur fluide : au moment ou la FK auto-referente est
            // posee, `id` n'est donc pas encore unique. PostgreSQL refuse net
            // (`SQLSTATE[42830] : there is no unique constraint matching given
            // keys`) tandis que SQLite, qui compile ses FK a l'interieur du
            // CREATE TABLE, accepte sans rien dire. Le defaut existait donc sur
            // les deux moteurs mais ne se voyait que sur un.
            //
            // Declarer la commande ici la place AVANT la FK, et les deux moteurs
            // obtiennent le meme schema — plutot qu'une FK deplacee dans un
            // `Schema::table()` ulterieur, qui serait purement et simplement
            // ABSENTE en SQLite (leçon de TASK-1634).
            $table->uuid('id');
            $table->primary('id');

            // Identite du scenario, denormalisee : pas de table d'en-tete.
            $table->string('scenario_key', 100);
            $table->string('name', 150);
            $table->string('version', 20);

            $table->string('usage', 20);
            $table->string('origin', 20);
            $table->string('state', 20);

            // Le document lui-meme. Conserve MEME invalide : un brouillon qui
            // ne parse pas doit rester editable, sinon l'auteur perd son
            // travail au premier essai rate.
            $table->text('json_source');

            // Nullable parce qu'un document qui ne parse pas n'a pas de digest
            // canonique : le Validator n'en rend un qu'a partir du moment ou
            // le JSON a pu etre lu.
            $table->string('digest', 64)->nullable();

            // Verdict + compteurs + nombre d'erreurs, tel que rendu par
            // ManifestValidationResult::toArray().
            $table->json('validation_summary')->nullable();

            // L'approbation humaine, seule porte vers un Load (CDC 12.2). Le
            // digest approuve est celui que ScenarioManifest::fromApprovedJson()
            // comparera au digest recalcule, avant toute ecriture metier.
            $table->string('approved_digest', 64)->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Provenance. Auto-reference : une version peut descendre d'une
            // autre par Duplicate ou par Capture.
            $table->foreignUuid('parent_id')->nullable()->constrained('scenario_manifest_versions')->nullOnDelete();
            // Nom de contrainte EXPLICITE et court. Le nom genere par Laravel,
            // `scenario_manifest_versions_captured_from_organization_id_foreign`,
            // fait 64 octets : PostgreSQL le tronque silencieusement a 63 avec
            // un simple NOTICE, si bien que le nom reel differe de celui que
            // Laravel croit avoir pose — et qu'un futur `dropForeign()` par ce
            // nom echouerait.
            $table->foreignUuid('captured_from_organization_id')->nullable()
                ->constrained('organizations', 'id', 'smv_captured_from_org_fk')->nullOnDelete();

            // Le pilier de la derivation LOADED. nullOnDelete, jamais cascade :
            // la disparition d'une sandbox ne doit pas emporter la definition
            // administrative qui l'a produite.
            $table->foreignUuid('scenario_pack_load_id')->nullable()->constrained('scenario_pack_loads')->nullOnDelete();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('scenario_key');
            $table->unique(['scenario_key', 'version'], 'scenario_manifest_versions_key_version_unique');
        });

        // CHECK pgsql-only : SQLite ne peut pas en ajouter a une table
        // existante, et le projet obtient l'uniformite au niveau applicatif.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE scenario_manifest_versions ADD CONSTRAINT '.self::CONTRAINTE_ETAT
            ." CHECK (state IN ('draft', 'valid'))"
        );

        DB::statement(
            'ALTER TABLE scenario_manifest_versions ADD CONSTRAINT '.self::CONTRAINTE_USAGE
            ." CHECK (usage IN ('qa', 'dogfooding', 'demo', 'prospect'))"
        );

        DB::statement(
            'ALTER TABLE scenario_manifest_versions ADD CONSTRAINT '.self::CONTRAINTE_ORIGINE
            ." CHECK (origin IN ('new', 'import', 'duplicate', 'capture', 'template'))"
        );
    }

    public function down(): void
    {
        // `DROP TABLE` emporte ses propres contraintes CHECK : les retirer une
        // a une serait redondant, et le ferait au prix d'un mode d'echec de
        // plus. `ALTER TABLE t DROP CONSTRAINT IF EXISTS c` ne protege que du
        // manque de la CONTRAINTE, pas de celui de la TABLE : il leve
        // `relation does not exist` si la table a deja disparu, la ou
        // `dropIfExists` seul aurait rendu la main sans bruit.
        Schema::dropIfExists('scenario_manifest_versions');
    }
};
