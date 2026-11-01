<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1740 — avec QUELLE clef VAPID cet abonnement a-t-il ete cree ?
 *
 * ## Le defaut
 *
 * Un abonnement Push porte A VIE la clef publique avec laquelle le navigateur
 * l'a cree, et c'est elle que le navigateur exigera dans notre signature. Si
 * la clef du serveur change, l'abonnement SURVIT intact : le navigateur le
 * rend toujours, le serveur le croit valide, le fournisseur accepte l'envoi
 * et repond `201`… et le navigateur jette le message EN SILENCE.
 *
 * C'est exactement la forme de panne qui a coute la recette du 01/11, sous une
 * autre cause. Elle ne doit pas pouvoir se reproduire sans etre vue.
 *
 * ## Pourquoi une EMPREINTE et non la clef
 *
 * La clef publique n'est pas un secret, mais la stocker en clair sur chaque
 * ligne n'apporte rien : on ne veut pas la relire, seulement savoir si elle a
 * CHANGE. Un SHA-256 repond a cette question et rien de plus.
 *
 * ## Pourquoi `nullable`, et pourquoi un `null` reste ENVOYABLE
 *
 * Contrairement a `created_env`, un `null` ne vaut pas ici « suspect ». Les
 * lignes anterieures a cette colonne ont ete creees avec une clef dont nous
 * n'avons aucune raison de penser qu'elle differe — celle de Cyril vient
 * precisement d'etre validee en recette sur son Android.
 *
 * Les refuser casserait une installation qui FONCTIONNE pour se proteger d'un
 * risque qu'on n'a pas constate. On ne traite donc comme divergent que ce
 * qu'on a MESURE divergent ; les lignes inconnues se stampent d'elles-memes a
 * la prochaine reconciliation, qui a lieu a chaque affichage de l'ecran.
 *
 * Non destructive : un `ADD COLUMN` nullable, rien d'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->string('vapid_key_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('push_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('vapid_key_hash');
        });
    }
};
