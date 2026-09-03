<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1375 — les reglages de notification d'un membre.
 *
 * ## Des ECARTS, pas un etat complet
 *
 * Une ligne n'existe QUE si le membre s'ecarte du defaut. Les defauts vivent
 * dans `NotificationCatalogue`, et nulle part ailleurs.
 *
 * La consequence est utile : une base vide et un membre qui n'a jamais touche a
 * ses reglages sont exactement la meme chose — ce qui sera le cas de la
 * quasi-totalite des gens. Il n'y a rien a provisionner a l'inscription, rien a
 * rattraper quand une cle apparait au catalogue, et changer un defaut produit
 * s'applique immediatement a tous ceux qui n'ont rien dit.
 *
 * Stocker l'etat complet aurait fige les defauts au moment de l'ecriture : le
 * jour ou le produit change d'avis, personne n'en beneficierait.
 *
 * ## Pas d'`organization_id`, et c'est deliberе
 *
 * Un reglage de notification appartient a la PERSONNE, pas au tenant. « Je ne
 * veux pas d'email pour ceci » est une preference humaine, pas une propriete
 * d'appartenance. La lier a une Organization obligerait un membre a la
 * re-exprimer a chaque changement d'appartenance, et ferait de sa boite de
 * reception un objet du tenant.
 *
 * C'est le seul endroit de ce module ou l'absence d'`organization_id` est la
 * bonne reponse — `member_notifications`, elle, en porte un et le verifie.
 *
 * ## L'unicite est le triplet du CDC
 *
 * `(user_id, notification_key, channel)`. Deux lignes contradictoires pour le
 * meme reglage rendraient le resolver dependant de l'ordre de lecture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // La cle du catalogue et le canal. Une cle ou un canal absent du
            // catalogue est refuse a l'ecriture : un reglage qui ne gouverne
            // rien serait un mensonge affiche a l'ecran.
            $table->string('notification_key', 80);
            $table->string('channel', 20);

            // L'ECART : `false` = le membre a coupe ce canal, `true` = il l'a
            // rallume apres l'avoir coupe. Pas de troisieme etat — l'absence de
            // ligne dit deja « je m'en remets au defaut ».
            $table->boolean('enabled');

            $table->timestamps();

            $table->unique(['user_id', 'notification_key', 'channel'], 'member_notification_preferences_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notification_preferences');
    }
};
