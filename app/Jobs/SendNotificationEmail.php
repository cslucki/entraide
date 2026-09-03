<?php

namespace App\Jobs;

use App\Support\Notifications\NotificationEmailDeliverer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * TASK-1377 — livrer par email une notification deja emise.
 *
 * ## La charge utile est un IDENTIFIANT, rien d'autre
 *
 * Pas de modele serialise, pas d'adresse, pas de langue, pas de sujet. Un job
 * attend en file : tout ce qu'il transporte est une PHOTO d'un etat passe. Le
 * destinataire a pu quitter l'Organization, couper ses emails, changer de
 * langue, ou l'objet metier a pu etre revoque. Le worker relit donc TOUT depuis
 * la base au moment ou il travaille, et la charge utile ne dit que « laquelle ».
 *
 * ## Aucun contexte de requete
 *
 * Ni `auth()`, ni `currentOrganization()`, ni session. Un worker n'est
 * l'utilisateur de personne. Le tenant se lit sur la notification, jamais sur
 * un contexte ambiant — c'est la meme raison qui a fait exclure
 * `BelongsToOrganizationScope` des notifications en T1372 : sous worker, il
 * n'aurait rien resolu et aurait silencieusement vide la table.
 *
 * ## `afterCommit` n'est pas une precaution, c'est une correction
 *
 * La connexion de queue du depot a `after_commit => false`. Sans ce drapeau, la
 * livraison serait mise en file AVANT que la transaction du producteur ait
 * commit — et un worker rapide chercherait une ligne qui n'existe pas encore.
 * C'est une course qui ne se voit qu'en charge, donc exactement celle qu'il faut
 * fermer par construction.
 *
 * ## `$tries = 1` — aucune reprise automatique
 *
 * Reprendre suppose de savoir que rien n'est parti. Tant que cette certitude
 * n'est pas construite, un rejeu est un pari sur le dos du destinataire. Meme un
 * echec n'est pas rejoue en V1-A ; `ambiguous` ne le sera jamais
 * automatiquement.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Voir le docblock : la reprise automatique est INTERDITE en V1-A. */
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $notificationId)
    {
        // Une file DEDIEE. La file `default` porte un historique qui ne doit
        // etre ni draine ni consomme par cette tranche : un worker borne a
        // `notifications-email` ne peut pas y toucher, meme par erreur.
        $this->onQueue(NotificationEmailDeliverer::QUEUE);

        // Attendre le commit du producteur.
        //
        // La connexion `database` du depot declare `after_commit => false` :
        // ce reglage-ci est donc ce qui fait reellement la difference. Il est
        // pose par la methode du trait plutot que par une propriete redeclaree —
        // `Queueable` definit deja `$afterCommit` sans type, et le redeclarer
        // typé rend la composition du trait invalide (erreur FATALE a la
        // construction, pas une deprecation silencieuse).
        $this->afterCommit();
    }

    public function handle(NotificationEmailDeliverer $deliverer): void
    {
        $deliverer->deliver($this->notificationId);
    }
}
