<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1775 — les reglages de notification COLLECTIVE d'un membre, PAR Boucle.
 *
 * ## Une table COMPLEMENTAIRE, jamais une transformation de l'historique
 *
 * Arbitrage explicite de Cyril : une Boucle est une granularite que
 * `member_notification_preferences` (T1375) n'a jamais portee — sa doctrine
 * dit « un reglage de notification appartient a la PERSONNE, pas au tenant »,
 * et c'est TOUJOURS vrai pour les cles personnelles (invitation, decision
 * d'adhesion). Mais une notification COLLECTIVE (CDC §8.1, aujourd'hui
 * `loop.help_request.created` seule) ne peut pas se reduire a une personne :
 * elle vient d'UNE Boucle precise, et « je veux en entendre parler » est une
 * reponse qui varie Boucle par Boucle. Alterer la table historique pour y
 * ajouter un `loop_id` nullable aurait rendu CHAQUE ligne existante ambigue —
 * s'applique-t-elle a toutes les Boucles, ou a aucune ? Une table a part pose
 * la question UNE fois, a la racine.
 *
 * ## Portee a l'Organization, contrairement a sa voisine
 *
 * `member_notification_preferences` n'en porte pas, a dessein : une
 * preference personnelle suit la personne a travers ses appartenances.
 * Celle-ci EST une propriete d'appartenance — on ne peut pas regler la
 * collecte d'une Boucle a laquelle on n'appartient pas, et `organization_id`
 * est EXPLICITE, comme sur `member_notifications` et `loop_members` : la
 * frontiere de tenant doit vivre dans la meme requete que l'identite, jamais
 * deduite d'une jointure.
 *
 * ## Les TROIS leviers de l'ecran, dans UNE ligne
 *
 * `collective_enabled` est le maitre : eteint, il coupe TOUS les canaux de
 * cette Boucle, quoi que disent `email_enabled`/`web_push_enabled` — c'est
 * exactement le CDC B1 (« desactive TOUTES les notifications collectives de
 * cette Boucle, quel que soit le canal »). `in_app` n'a pas sa propre colonne :
 * tant que la Boucle est active, l'in-app suit le defaut du catalogue — il
 * n'y a rien a stocker de plus, l'in-app est livre par l'existence meme de la
 * ligne `member_notifications` (meme doctrine que `NotificationDeliveryPlanner`).
 *
 * ## L'absence de ligne EST le defaut produit
 *
 * Rejoindre une Boucle ne provisionne rien ici : `collective_enabled = true`,
 * `email_enabled = false`, `web_push_enabled = false` sont le comportement
 * attendu d'une Boucle fraichement rejointe, et c'est deja ce que le
 * resolver rend en l'absence de ligne. Ecrire une ligne au premier chargement
 * de l'ecran figerait ces defauts a la date d'inscription — meme erreur que
 * T1375 a deja evitee pour les reglages personnels.
 *
 * ## L'unicite suit `loop_members`, pas sa voisine
 *
 * `(loop_id, user_id)` : `loop_id` implique deja l'Organization, exactement
 * comme sur `loop_members`. Y ajouter `organization_id` dans la cle grossirait
 * l'index sans resoudre une seule ambiguite de plus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_loop_notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->boolean('collective_enabled')->default(true);
            $table->boolean('email_enabled')->default(false);
            $table->boolean('web_push_enabled')->default(false);

            $table->timestamps();

            $table->unique(['loop_id', 'user_id'], 'member_loop_notification_preferences_unique');
            // La lecture de l'ecran de reglages part toujours de la PERSONNE
            // ("mes Boucles"), jamais de la Boucle : l'index d'unicite suffit
            // pour l'autre sens, plus rare (purge a la suppression d'une
            // Boucle, deja couverte par le `cascadeOnDelete()` ci-dessus).
            $table->index(['user_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_loop_notification_preferences');
    }
};
