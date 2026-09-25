<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1642 (revue) — l'identite d'idempotence d'un chargement de manifeste.
 *
 * ## Le defaut corrige
 *
 * La spec 5.2 exige qu'"un double clic ou rejeu reseau sur Load avec le meme
 * manifeste/digest retourne le chargement existant, sans creer une seconde
 * sandbox". L'unique contrainte existante,
 * `(organization_id, pack_id)`, ne pouvait pas l'assurer : une sandbox de
 * manifeste est NEUVE a chaque appel, donc le couple est toujours inedit et
 * n'a jamais rien bloque. Deux appels identiques produisaient deux sandboxes.
 *
 * ## Pourquoi le digest, et pas autre chose
 *
 * `proposed_slug` n'est PAS une cle d'idempotence : c'est une suggestion, deux
 * manifestes differents peuvent proposer le meme slug, et le slug final peut
 * en differer. `pack_id` seul ne suffit pas davantage : deux versions d'un
 * meme pack partagent leur `id` et decrivent pourtant deux mondes.
 *
 * Le digest canonique RFC 8785 est la SEULE chose qui identifie exactement le
 * contenu approuve par un humain. Deux appels portent le meme digest si et
 * seulement s'ils demandent le chargement du meme monde.
 *
 * ## Pourquoi une contrainte DB et pas seulement un `SELECT` prealable
 *
 * Un `SELECT` puis un `INSERT` laisse une fenetre : deux requetes concurrentes
 * peuvent toutes deux ne rien trouver. L'index unique est la seule garantie
 * qui tienne sous concurrence — le second chargement echoue au niveau du
 * moteur, et le service nettoie puis rend le chargement gagnant.
 *
 * ## Compatibilite des packs historiques
 *
 * La colonne est NULLABLE et les quatre packs PHP existants laissent `NULL`.
 * PostgreSQL comme SQLite traitent les NULL comme DISTINCTS dans un index
 * unique : autant de lignes a `NULL` que voulu, aucune contrainte nouvelle sur
 * l'existant, aucun backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scenario_pack_loads', function (Blueprint $table) {
            $table->string('manifest_digest', 64)->nullable()->after('pack_version');

            // NULL distincts des deux cotes : les packs historiques cohabitent
            // sans contrainte, et deux chargements du meme manifeste approuve
            // ne peuvent pas coexister.
            $table->unique(['pack_id', 'manifest_digest'], 'scenario_pack_loads_pack_manifest_digest_unique');
        });
    }

    public function down(): void
    {
        Schema::table('scenario_pack_loads', function (Blueprint $table) {
            $table->dropUnique('scenario_pack_loads_pack_manifest_digest_unique');
            $table->dropColumn('manifest_digest');
        });
    }
};
