<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1377 — l'etat COURANT d'une livraison, canal par canal.
 *
 * ## Une notification, plusieurs livraisons
 *
 * EMAIL n'est pas une deuxieme notification : c'est un canal de livraison de la
 * MEME `MemberNotification`. Creer une seconde ligne de notification pour
 * l'email dedoublerait le badge, le Centre et l'idempotence — trois autorites
 * a tenir alignees au lieu d'une.
 *
 * ## Cette table porte l'ETAT COURANT, pas l'historique
 *
 * `email_logs` reste la preuve historique, et il n'y a pas de troisieme moteur.
 * Ici on repond a une seule question : ou en est la livraison de cette
 * notification sur ce canal, maintenant.
 *
 * ## L'idempotence vit dans la CONTRAINTE, pas dans le code appelant
 *
 * `UNIQUE(notification_id, channel)` est ce qui garantit qu'un evenement ne
 * part qu'une fois par canal, meme si deux producteurs, deux workers ou un
 * rejeu de queue arrivent ensemble. Une verification prealable en PHP laisserait
 * une fenetre entre le SELECT et l'INSERT ; la contrainte, non.
 *
 * C'est la meme doctrine qu'en T1372, ou `UNIQUE(event_id, recipient_id)` tranche
 * l'idempotence de l'emission.
 *
 * ## `claimed_at` rend la prise de travail OBSERVABLE
 *
 * Un `status = sending` seul ne dit pas DEPUIS QUAND. Sans horodatage, une
 * livraison bloquee par un worker mort serait indistinguable d'une livraison en
 * cours — et c'est precisement l'etat qu'un humain devra un jour arbitrer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_notification_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('notification_id')
                ->constrained('member_notifications')
                ->cascadeOnDelete();

            // Le canal, en clair. Court par construction : `in_app`, `email`.
            $table->string('channel', 20);

            // pending | sending | sent | failed | ambiguous
            // | skipped_preference | skipped_unreachable
            $table->string('status', 32);

            // Compte les PRISES de travail, pas les rejeux automatiques : il n'y
            // en a aucun en V1-A. Il sert a reperer ce qui a ete repris a la main.
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // Le diagnostic d'un echec, borne en longueur. Aucune donnee de
            // transport, aucun identifiant : voir NotificationDeliveryDiagnostic.
            $table->string('diagnostic', 120)->nullable();

            $table->timestamps();

            $table->unique(['notification_id', 'channel'], 'member_notification_deliveries_unique');

            // La question du worker : « qu'y a-t-il a livrer sur ce canal ? »
            $table->index(['channel', 'status'], 'member_notification_deliveries_worklist_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notification_deliveries');
    }
};
