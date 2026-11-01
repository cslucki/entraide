<?php

namespace App\Jobs;

use App\Support\Notifications\NotificationWebPushDeliverer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * TASK-1740 — le job qui porte le canal `web_push`.
 *
 * Jumeau de {@see SendNotificationEmail}, et volontairement son copie conforme
 * dans sa STRUCTURE : meme file dediee par canal, meme `afterCommit`, meme
 * refus du rejeu automatique. Ce qui change est le livreur, rien d'autre.
 *
 * ## Pourquoi un job PAR CANAL
 *
 * C'est la correction du defaut de T1377 :
 * `NotificationDeliveryPlanner::plan()` dispatchait `SendNotificationEmail`
 * **dans la boucle sur les canaux**, sans jamais lui passer le canal. Tant que
 * la liste des canaux asynchrones n'avait qu'une entree, la boucle faisait un
 * tour et le defaut restait invisible. Avec le Push, chaque canal aurait mis
 * un job EMAIL en file.
 *
 * ## Pas de rejeu automatique
 *
 * `$tries = 1`, comme pour l'email, et pour une raison plus forte encore : un
 * Push atterrit sur un ecran verrouille. Rejouer un envoi dont on ne sait pas
 * s'il a abouti, c'est parier sur la tranquillite du destinataire. Un echec
 * reste trace dans la livraison ; c'est une decision humaine de le reprendre.
 */
class SendNotificationWebPush implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** La reprise automatique est INTERDITE : voir le docblock. */
    public int $tries = 1;

    /**
     * Plus genereux que l'email : un envoi Push s'adresse a N appareils, et
     * chacun est un aller-retour vers le fournisseur. Le quota reel est borne
     * par le livreur, pas par ce delai.
     */
    public int $timeout = 120;

    public function __construct(public readonly string $notificationId)
    {
        // Une file DEDIEE, distincte de `notifications-email` : un worker
        // borne a l'une ne doit pas drainer l'autre, et les deux canaux n'ont
        // ni le meme cout ni la meme urgence.
        $this->onQueue(NotificationWebPushDeliverer::QUEUE);

        // Attendre le commit du producteur : la connexion `database` du depot
        // declare `after_commit => false`, donc c'est bien cet appel qui fait
        // la difference. Pose par la methode du trait et non par une propriete
        // redeclaree — `Queueable` definit deja `$afterCommit` sans type.
        $this->afterCommit();
    }

    public function handle(NotificationWebPushDeliverer $deliverer): void
    {
        $deliverer->deliver($this->notificationId);
    }
}
