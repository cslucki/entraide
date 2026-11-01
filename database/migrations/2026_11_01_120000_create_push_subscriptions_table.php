<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1739 — les abonnements Web Push, un par APPAREIL.
 *
 * ## Ce que cette table est, et ce qu'elle n'est pas
 *
 * Un abonnement n'est **pas une notification metier**. C'est un destinataire
 * TECHNIQUE : le trio (`endpoint`, `p256dh`, `auth`) que le navigateur remet
 * apres un consentement, et sans lequel aucun message chiffre ne peut etre
 * depose chez le fournisseur Push (CDC section 5.1).
 *
 * Elle ne porte donc aucune preference metier, aucune cle d'evenement, aucun
 * canal. Le canal `web_push` n'existe pas encore : il entrera au catalogue au
 * chantier d'ENVOI, apres correction du defaut de
 * `NotificationDeliveryPlanner::plan()` (le job email y est dispatche DANS la
 * boucle sur les canaux). Cette TASK n'envoie rien.
 *
 * ## Pourquoi AUCUN `organization_id`
 *
 * Decision deliberee, et elle suit un precedent du depot :
 * `member_notification_preferences` n'en porte pas non plus, et est declaree
 * pour cette raison dans `ScenarioSandboxPreflight::TABLES_HORS_PORTEE` —
 * « une preference appartient a la PERSONNE, pas au tenant ».
 *
 * Un abonnement est de la meme nature, en plus fort : il decrit un NAVIGATEUR
 * sur un APPAREIL. La meme personne qui bascule entre ses comptes freres garde
 * le meme telephone ; rattacher l'abonnement a un tenant obligerait a le
 * recreer a chaque bascule, et le consentement systeme, lui, ne se rejoue pas.
 *
 * `Organization = Tenant` n'est pas affaibli pour autant : la frontiere est
 * tenue **au moment de l'envoi**, en relisant l'`organization_id` du
 * destinataire — exactement ce que fait deja
 * `NotificationEmailDeliverer::livrer()` avant tout courriel
 * (`recipient_left_organization`). La borne vit la ou l'ecriture a lieu, pas
 * dans une colonne recopiee.
 *
 * ## L'unicite porte sur `endpoint` SEUL, et c'est le coeur
 *
 * Le CDC 5.2 l'exige : « l'endpoint ne doit etre attribue qu'a UN SEUL
 * `user_id` a un instant donne ». Une unicite `(user_id, endpoint)` aurait
 * autorise deux lignes pour le meme appareil sous deux comptes freres — et
 * donc, au chantier d'envoi, un Push du compte precedent sur l'ecran
 * verrouille.
 *
 * L'unicite sur `endpoint` seul rend la REVENDICATION atomique : une seule
 * ligne peut exister par appareil, et c'est le dernier compte qui s'abonne
 * qui la detient. Plusieurs appareils par personne restent evidemment
 * possibles — c'est l'autre sens de la relation.
 *
 * `endpoint` est une URL de fournisseur, longue : `text`, pas `string`. Les
 * points de terminaison FCM depassent 200 caracteres et rien ne garantit leur
 * borne. PostgreSQL indexe `text` sans difficulte ; pour rester indexable sous
 * les deux moteurs, l'unicite passe par une EMPREINTE.
 *
 * ## Minimisation (CDC 5.4)
 *
 * Aucune IP, aucun User-Agent complet, aucune localisation, aucun modele
 * d'appareil. `device_label` est un libelle COURT et facultatif, destine a ce
 * que la personne reconnaisse son propre appareil dans une liste — rien
 * d'autre. Il est fourni par le serveur a partir d'indices grossiers, jamais
 * une signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // `cascadeOnDelete` : un abonnement est une donnee d'appareil
            // rattachee a une personne. Le compte disparait, l'abonnement n'a
            // plus de destinataire. Declare en POLICY_DELETE au
            // `UserDataLifecycleRegistry`, qui doit RECOPIER ce que la FK fait.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // L'URL du fournisseur Push. `text` : voir le docblock.
            $table->text('endpoint');

            // L'empreinte de l'endpoint, qui porte l'unicite.
            //
            // Une colonne DERIVEE plutot qu'un index fonctionnel : SQLite
            // n'indexe pas une expression de la meme facon que PostgreSQL, et
            // le depot exige des tests verts sur les DEUX moteurs. Une colonne
            // calculee a l'ecriture se comporte identiquement partout.
            //
            // SHA-256 en hexadecimal = 64 caracteres, longueur fixe.
            $table->string('endpoint_hash', 64);

            // Les deux clefs du chiffrement de charge utile (RFC 8291).
            // Opaques pour nous : on ne les lit jamais, on les transmet.
            $table->string('p256dh', 255);
            $table->string('auth', 255);

            // Libelle court et FACULTATIF, pour que la personne reconnaisse
            // son appareil. Jamais un UA complet (CDC 5.4).
            $table->string('device_label', 60)->nullable();

            // Derniere fois que le navigateur a confirme cet abonnement.
            // Sert a la reconciliation : `pushsubscriptionchange` n'est pas
            // pris en charge partout, donc on reconfirme a la connexion et a
            // la reouverture de l'application.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            // L'unicite du CDC 5.2 : un appareil, un compte.
            $table->unique('endpoint_hash', 'push_subscriptions_endpoint_unique');

            // La liste des appareils d'une personne, triee par fraicheur.
            $table->index(['user_id', 'last_seen_at'], 'push_subscriptions_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
