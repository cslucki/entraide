<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1756 (§6.3 du CDC) — la proposition de GROUPE, en deux colonnes.
 *
 * ## Pourquoi aucune table de participants
 *
 * Une Transaction est strictement 1-1 : `buyer_id` et `seller_id` sont NOT
 * NULL. Inventer une « transaction a six » aurait demande une table de
 * participants, une reprise de l'existant, et une seconde choregraphie a
 * maintenir a cote de celle qui marche.
 *
 * Or le mecanisme existe DEJA : plusieurs personnes peuvent avoir CHACUNE leur
 * transaction vers la meme annonce — c'est ce que T1693 bug W a livre cote
 * demandes, et la garde anti-doublon porte sur le couple (proposant, annonce),
 * pas sur l'annonce. Un atelier de six, ce sont six echanges 1-1 vers la meme
 * annonce, chacun avec sa conversation et ses confirmations.
 *
 * Il ne manquait qu'une BORNE et un PLANCHER.
 *
 * ## Les deux colonnes, et pourquoi la seconde existe
 *
 * - `capacity` : le maximum (2..6). `null` = annonce individuelle.
 * - `min_participants` : le PLANCHER de repartition (arbitrage Cyril du 05/11,
 *   option C).
 *
 * Le plancher n'est pas un confort. Sans lui, deux lectures s'affrontaient et
 * aucune n'etait juste : repartir sur les inscrits REELS fait payer 45 min a
 * une personne seule ; repartir sur la CAPACITE fait que l'aidant qui annonce
 * 45 min n'en recoit que 22 si l'atelier est a moitie plein. Le plancher laisse
 * l'aidant declarer « je le fais a partir de 3 », et la part devient
 * `ceil(X / max(inscrits, plancher))` : le participant connait son prix
 * maximum des l'inscription, l'aidant maitrise son risque.
 *
 * ## Migration ADDITIVE, et `null` veut dire quelque chose
 *
 * `null` = annonce individuelle, le comportement historique. Les lignes
 * existantes ne sont pas touchees et n'ont besoin d'aucune reprise.
 *
 * `smallint` et non `integer` : la borne produit est SIX. Reserver quatre
 * octets pour un nombre a un chiffre serait du gaspillage dans une table lue a
 * chaque affichage d'annonce.
 *
 * ## Pourquoi AUCUNE contrainte CHECK
 *
 * « De 2 a 6 » est une regle PRODUIT, pas une invariante de donnees : elle
 * changera le jour ou l'on voudra des ateliers de dix, et une contrainte
 * imposerait alors une migration sur une table chaude. La validation vit dans
 * le formulaire et dans `App\Support\Entraide\Atelier`.
 *
 * `down()` retire les deux colonnes et rien d'autre : ni index, ni cle
 * etrangere, aucune autre colonne n'en depend.
 *
 * ## Idempotence des deux sens (TASK-1764)
 *
 * Les deux sens sont gardes par `Schema::hasColumn`, comme les autres
 * migrations additives de ce lot. Le deploiement PROD est un
 * `php artisan migrate --force` en push-to-deploy : une passe interrompue puis
 * relancee, ou une repetition sur `prod_work`, ne doit pas echouer sur un
 * `column already exists`. Et un `down()` non garde echoue sur une base ou la
 * colonne n'a jamais ete posee.
 */
return new class extends Migration
{
    private const COLONNES = ['capacity', 'min_participants'];

    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'capacity')) {
                $table->smallInteger('capacity')->nullable()->after('points_cost');
            }

            if (! Schema::hasColumn('services', 'min_participants')) {
                $table->smallInteger('min_participants')->nullable()->after('capacity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            foreach (self::COLONNES as $colonne) {
                if (Schema::hasColumn('services', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
