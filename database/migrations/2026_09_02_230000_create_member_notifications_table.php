<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1372 — le stockage des notifications IN_APP.
 *
 * ## Pourquoi PAS `notifications`
 *
 * `User` porte le trait `Notifiable`, qui expose deja `$user->notifications()`
 * pointant vers une table `notifications` au schema Laravel
 * (`notifiable_type` / `notifiable_id` / `data`). Le canal `database` n'est
 * utilise nulle part aujourd'hui — les quatre classes d'`app/Notifications`
 * renvoient `['mail']` — mais la relation existe sur le modele. Creer une table
 * `notifications` au schema different armerait une panne silencieuse le jour ou
 * quelqu'un appellerait cette relation.
 *
 * Le nom `member_notifications` laisse la place libre et dit ce qu'il stocke :
 * ce que BouclePro adresse a un membre, dans une Organization.
 *
 * ## Pas de colonne de contenu, et c'est le point le plus important
 *
 * Une notification ne porte QUE des references : `object_type` + `object_id`.
 * Aucun titre de Boucle privee, aucun extrait de message, aucun nom de document
 * — rien qui puisse survivre a une revocation de droits.
 *
 * Le rendu resout l'objet EN DIRECT et reapplique les permissions du moment. Une
 * notification emise hier n'accorde donc aucun droit aujourd'hui : si l'acces a
 * disparu, l'ecran le dit honnetement au lieu d'afficher un contenu fige.
 *
 * C'est aussi pourquoi il n'y a **aucune colonne `data` JSON** : un champ libre
 * finit toujours par recevoir du contenu metier, et ce contenu survit.
 *
 * ## `organization_id` vient de l'objet metier, jamais du contexte
 *
 * La colonne est renseignee explicitement par l'emetteur, depuis l'objet qui
 * produit l'evenement. Le trait `HasOrganizationId` est volontairement ECARTE :
 * il remplirait depuis `app('current_organization')`, c'est-a-dire un contexte
 * de requete arbitraire — exactement ce qu'une frontiere de tenant ne doit pas
 * etre.
 *
 * ## L'idempotence est une CONTRAINTE, pas une convention
 *
 * `UNIQUE(event_id, recipient_id)` : un meme fait generateur ne peut pas
 * notifier deux fois la meme personne, meme si le producteur est rejoue. La
 * garde vit en base et ne depend d'aucun cache ni d'aucun TTL — meme doctrine
 * qu'`AiTurnIdempotency` (T1311), qui lit la table precisement pour cette
 * raison.
 *
 * ## `collapse_key` est pose des maintenant
 *
 * Il n'est pas encore exploite. Il est la parce qu'ajouter une colonne a une
 * table deja peuplee coute plus cher que la prevoir, et parce que le CDC le
 * demande des le schema V1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Le tenant. Pose par l'emetteur depuis l'objet metier.
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // La personne prevenue. Son appartenance au tenant est verifiee a
            // l'ecriture, et l'ownership est reverifie a chaque lecture.
            $table->foreignUuid('recipient_id')->constrained('users')->cascadeOnDelete();

            // La cle du catalogue. Une cle absente du catalogue n'existe pas.
            $table->string('notification_key', 80);

            // Le fait generateur. Deux emissions du meme fait vers la meme
            // personne sont le meme evenement, pas deux notifications.
            $table->uuid('event_id');

            // La REFERENCE a l'objet metier — jamais son contenu, jamais son URL.
            //
            // NON nullable : le catalogue impose un `object_type` a chaque cle,
            // donc une notification sans type d'objet est inatteignable. Une
            // colonne nullable qui ne peut jamais etre nulle se lit comme une
            // possibilite offerte, et n'en est pas une.
            $table->string('object_type', 40);
            $table->uuid('object_id')->nullable();

            // Qui a declenche, quand cela a du sens. Une suppression de compte
            // ne doit pas emporter la notification du destinataire.
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // Prevu des le schema V1, exploite plus tard.
            $table->string('collapse_key', 120)->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'recipient_id'], 'member_notifications_event_recipient_unique');
            $table->index(['recipient_id', 'organization_id', 'read_at'], 'member_notifications_recipient_unread_index');
            $table->index(['organization_id', 'collapse_key'], 'member_notifications_collapse_index');

            // `actor_id` porte une FK `nullOnDelete` : sans index, la suppression
            // d'un compte impose un balayage complet de cette table.
            $table->index('actor_id', 'member_notifications_actor_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notifications');
    }
};
