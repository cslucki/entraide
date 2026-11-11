<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1808 — les trois marqueurs durables du parcours final d'entraide.
 *
 * La decision produit du 11/11 separe quatre moments : realiser l'entraide,
 * la cloturer, remercier l'aidant, l'evaluer. Trois d'entre eux ont besoin de
 * se souvenir de quelque chose qu'aucune colonne ne portait :
 *
 * 1. `transactions.seller_celebrated_at` — la pluie de coeurs doit s'afficher
 *    « une seule fois par entraide ET par aidant » (§4). Le drapeau actuel de
 *    l'animation vit dans `window`, donc en memoire de PAGE : il ne survit ni
 *    a un rechargement ni a un autre appareil. Un marqueur durable est la
 *    seule facon de tenir l'exigence.
 *
 * 2. `transactions.evaluation_invited_at` — « une invitation MAXIMUM par
 *    entraide, aucune relance automatique » (§6). La commande planifiee qui
 *    envoie l'invitation relit cette colonne : sans elle, chaque passage du
 *    planificateur renverrait le meme email.
 *
 * 3. `reviews.comment_skipped_at` — le micro-ecran B (« Souhaitez-vous
 *    ajouter quelques mots ? ») doit cesser de revenir quand l'aide a repondu
 *    « Terminer sans commentaire » (§5). Le distinguer d'un commentaire
 *    simplement pas encore ecrit demande une colonne a part : mettre une
 *    chaine vide dans `comment` confondrait les deux.
 *
 * Trois proprietes, et elles sont le cahier des charges de cette migration :
 *
 * - **additive** : trois colonnes `nullable`, aucune donnee existante lue ni
 *   reecrite, aucune contrainte posee sur l'existant ;
 * - **reversible** : `down()` retire exactement ces trois colonnes ;
 * - **non destructive** : rien ne se perd si elle est rejouee a l'envers —
 *   une entraide retrouve simplement l'etat « jamais celebree, jamais
 *   invitee, commentaire ni ecrit ni decline ».
 *
 * `reviews_skipped_at` (T1806) n'est PAS touchee et garde son sens : le
 * renoncement EXPLICITE a l'evaluation. « Plus tard » ne l'ecrit jamais —
 * c'est une exigence du §5, et la raison pour laquelle le micro-ecran A ne
 * passe pas par `reviews.skip`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('seller_celebrated_at')->nullable()->after('reviews_skipped_at');
            $table->timestamp('evaluation_invited_at')->nullable()->after('seller_celebrated_at');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->timestamp('comment_skipped_at')->nullable()->after('comment');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['seller_celebrated_at', 'evaluation_invited_at']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('comment_skipped_at');
        });
    }
};
