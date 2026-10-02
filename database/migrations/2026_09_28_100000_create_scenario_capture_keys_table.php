<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1652 — le registre des stable keys de CAPTURE.
 *
 * ## Pourquoi une table de plus, et pas `scenario_pack_entities`
 *
 * Les deux registres repondent a deux questions differentes, et les confondre
 * abimerait celui qui existe deja :
 *
 * - `scenario_pack_entities` repond « quelles lignes CE chargement a-t-il
 *   creees, et donc lesquelles Reset et Remove peuvent-ils detruire ? ». C'est
 *   un registre de PROPRIETE, borne a un `scenario_pack_load_id`, et le purger
 *   s'en sert pour decider ce qu'il efface.
 * - Capture demande « quelle stable key Manifest represente cette entite
 *   runtime ? ». La reponse doit survivre a des objets que le chargement n'a
 *   PAS crees — ceux nes de l'activite dans la sandbox — et ne doit surtout
 *   rien apprendre au purger sur eux.
 *
 * Ecrire les objets nouveaux dans `scenario_pack_entities` les rendrait
 * destructibles par un Reset qui ne les a jamais crees. La separation n'est
 * donc pas une elegance : c'est la garantie que Capture n'a AUCUN effet sur
 * Reset ni sur Remove.
 *
 * ## Ce que la table garantit
 *
 * Deux unicites, et elles disent deux choses distinctes :
 *
 * - `(organization_id, entity_family, stable_key)` — dans une sandbox, une
 *   clef ne designe qu'un objet. C'est l'unicite que le Manifest exige, et
 *   elle est PAR FAMILLE : une personne « alice » et une Boucle « alice »
 *   coexistent legitimement (mesure T1651).
 * - `(organization_id, entity_family, entity_id)` — un objet ne recoit qu'une
 *   clef, et il la garde. C'est elle qui rend la seconde Capture identique a
 *   la premiere, meme apres un renommage.
 *
 * ## Le piege de la cle primaire, deja paye en T1646
 *
 * `id` est declare en COMMANDE EXPLICITE, avant toute FK. Le modificateur
 * fluide `->primary()` produit son `ALTER TABLE` APRES celui des cles
 * etrangeres : PostgreSQL refuse alors (`SQLSTATE 42830`) tandis que SQLite
 * accepte en silence. Meme schema sur les deux moteurs, ou rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenario_capture_keys', function (Blueprint $table) {
            $table->uuid('id');
            $table->primary('id');

            // CASCADE, et c'est un choix.
            //
            // Remove detruit la sandbox par `forceDelete()`. Une FK RESTRICT
            // ferait echouer ce geste au motif d'un registre purement
            // administratif — le registre deviendrait une CEINTURE qui empeche
            // de retirer une sandbox. CASCADE le fait disparaitre avec elle,
            // ce qui est exactement sa duree de vie utile, et laisse Remove
            // inchange.
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // La famille MANIFEST (`users`, `loops`, `training.modules`…), pas
            // le nom de la table runtime : c'est le langage du Manifest qui
            // fait autorite, et il survit a un renommage de table.
            $table->string('entity_family', 40);

            // L'identite RUNTIME. `uuid` et non `foreignUuid` : cette colonne
            // pointe vers une vingtaine de tables differentes selon la famille,
            // aucune FK unique ne peut l'exprimer. La borne est
            // `organization_id`, verifiee a l'ecriture.
            $table->uuid('entity_id');

            // 64 caracteres : la borne du contrat de stable key.
            $table->string('stable_key', 64);

            // Quand cet objet a-t-il ete vu pour la premiere fois par une
            // Capture. Sert au diagnostic et a l'ordre de generation des clefs,
            // jamais a l'identite.
            $table->timestamp('first_seen_at');

            $table->timestamps();

            // Noms COURTS et explicites. PostgreSQL tronque en silence
            // au-dela de 63 octets, et deux index tronques au meme prefixe
            // entrent en collision (leçon T1646).
            $table->unique(['organization_id', 'entity_family', 'stable_key'], 'scenario_capture_keys_key_unique');
            $table->unique(['organization_id', 'entity_family', 'entity_id'], 'scenario_capture_keys_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenario_capture_keys');
    }
};
