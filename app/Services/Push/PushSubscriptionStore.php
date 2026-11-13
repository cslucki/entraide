<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * TASK-1739 — la REVENDICATION d'un appareil par un compte.
 *
 * ## L'invariant que ce service tient, et lui seul
 *
 * CDC 5.2 : « l'endpoint ne doit etre attribue qu'a UN SEUL `user_id` a un
 * instant donne ». Autrement dit : un telephone n'appartient qu'a un compte a
 * la fois, et le dernier qui s'abonne gagne.
 *
 * Ce n'est pas une politesse d'affichage. Sans cet invariant, la meme personne
 * qui bascule entre ses comptes freres laisserait DEUX lignes pour son
 * telephone, et le chantier d'envoi deposerait sur son ecran verrouille une
 * notification destinee au compte qu'elle a quitte — une fuite inter-tenant
 * visible sans deverrouiller l'appareil.
 *
 * ## Pourquoi `updateOrCreate()` ne suffit pas
 *
 * `updateOrCreate(['endpoint_hash' => …], […])` fait un SELECT puis un INSERT
 * ou un UPDATE : deux temps, donc deux clients concurrents peuvent tous deux
 * ne rien trouver, et le second recoit une violation d'unicite brute en
 * pleine figure (500). Le cas n'est pas theorique : un navigateur qui
 * reconcilie a la reouverture pendant qu'un onglet s'abonne produit
 * exactement cette course.
 *
 * On ecrit donc en UPSERT — une seule instruction, atomique cote base — et on
 * garde malgre tout le rattrapage de la violation d'unicite, parce qu'une
 * garde qui depend d'une autre garde n'en est pas une.
 *
 * ## Ce que ce service ne fait pas
 *
 * Il n'envoie rien, ne chiffre rien, ne connait aucun canal de notification.
 * Il ne juge pas non plus la permission du navigateur : un refus systeme se
 * constate cote client, et le serveur n'en apprend jamais rien d'autre que
 * l'absence d'abonnement.
 */
class PushSubscriptionStore
{
    /**
     * Revendiquer un appareil pour cette personne.
     *
     * Rend l'abonnement tel qu'il existe APRES l'ecriture. Appeler deux fois
     * avec le meme endpoint est sans effet de bord : c'est precisement ce que
     * la reconciliation fait a chaque reouverture de l'application, puisque
     * `pushsubscriptionchange` n'est pas pris en charge partout (CDC 5.4).
     */
    public function revendiquer(
        User $proprietaire,
        string $endpoint,
        string $p256dh,
        string $auth,
        ?string $libelleAppareil = null,
    ): PushSubscription {
        $endpoint = trim($endpoint);
        $empreinte = PushSubscription::empreinteDe($endpoint);
        $maintenant = now();

        $valeurs = [
            'user_id' => $proprietaire->getKey(),
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth' => $auth,
            'device_label' => $libelleAppareil,
            'last_seen_at' => $maintenant,
            'updated_at' => $maintenant,
            // TASK-1740 — l'ORIGINE de l'abonnement, posee par le serveur et
            // jamais recue du client. Elle voyage avec la ligne : un
            // abonnement importe d'un dump de production portera
            // `production`, et le filtre de recette l'ecartera.
            'created_env' => (string) config('app.env'),
            // TASK-1740 — la clef VAPID en vigueur au moment de la
            // revendication. Posee par le serveur, jamais recue du client.
            // C'est elle qui permettra de reconnaitre, plus tard, un
            // abonnement cree avec une clef qui n'a plus cours.
            'vapid_key_hash' => PushSubscription::empreinteCleVapid(),
        ];

        // Le libelle n'est MIS A JOUR que s'il en arrive un.
        //
        // Defaut trouve par Cyril en recette sur son Android : le libelle est
        // pose au CLIC d'activation, mais la RECONCILIATION — qui rejoue cette
        // meme methode a chaque affichage de l'ecran et a chaque retour sur
        // l'application — n'en envoie aucun. Comme `device_label` figurait
        // dans les colonnes mises a jour, chaque reconciliation effacait le
        // libelle pose a l'activation : l'appareil redevenait anonyme en
        // quelques secondes.
        //
        // Un `null` entrant veut dire « je n'en sais rien », pas « efface ».
        // On ne confond pas l'absence d'information avec une information
        // d'absence.
        $colonnesAMettreAJour = ['user_id', 'endpoint', 'p256dh', 'auth', 'last_seen_at', 'updated_at', 'created_env', 'vapid_key_hash'];

        if ($libelleAppareil !== null) {
            $colonnesAMettreAJour[] = 'device_label';
        }

        try {
            // `upsert()` sur `endpoint_hash` : une seule instruction SQL.
            // C'est la bascule de proprietaire qui compte — `user_id` figure
            // dans les colonnes mises a jour, donc la revendication par un
            // compte frere REMPLACE l'ancienne au lieu d'ajouter une ligne.
            //
            // `upsert()` contourne les evenements Eloquent : ni `HasUuids` ni
            // le crochet `saving` du modele ne s'executent. L'identifiant et
            // l'empreinte sont donc fournis a la main — l'identifiant par
            // `newUniqueId()` du modele lui-meme, pour ne pas figer ici une
            // convention qui lui appartient.
            PushSubscription::query()->upsert(
                [$valeurs + [
                    'id' => (new PushSubscription)->newUniqueId(),
                    'endpoint_hash' => $empreinte,
                    'created_at' => $maintenant,
                ]],
                ['endpoint_hash'],
                $colonnesAMettreAJour,
            );
        } catch (UniqueConstraintViolationException) {
            // Ceinture : si un moteur rendait l'upsert indisponible, on
            // retombe sur une mise a jour ciblee, qui vise la MEME ligne.
            // Meme regle sur le libelle que ci-dessus.
            PushSubscription::query()
                ->where('endpoint_hash', $empreinte)
                ->update(array_filter(
                    $valeurs,
                    static fn (string $colonne): bool => $colonne !== 'device_label' || $libelleAppareil !== null,
                    ARRAY_FILTER_USE_KEY,
                ));
        }

        return PushSubscription::query()->where('endpoint_hash', $empreinte)->sole();
    }

    /**
     * Retirer l'abonnement de CET appareil, pour CETTE personne.
     *
     * Le filtre sur `user_id` n'est pas decoratif : sans lui, quelqu'un qui
     * connait un endpoint pourrait desabonner l'appareil d'un autre. Un
     * endpoint n'est pas un secret — il voyage dans les requetes du
     * navigateur.
     *
     * Rend le nombre de lignes retirees, donc `0` quand il n'y avait rien a
     * retirer. C'est un cas ORDINAIRE : le navigateur peut avoir deja oublie
     * son abonnement de son cote.
     */
    public function renoncer(User $proprietaire, string $endpoint): int
    {
        return PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::empreinteDe(trim($endpoint)))
            ->where('user_id', $proprietaire->getKey())
            ->delete();
    }

    /**
     * TASK-1820 — retirer un abonnement par IDENTIFIANT DE LIGNE, pour CETTE
     * personne.
     *
     * Meme doctrine que `renoncer()`, pour une situation differente : celui-ci
     * vise par `endpoint`, le seul identifiant que le NAVIGATEUR connaisse —
     * c'est le bon choix quand c'est le navigateur qui parle. Un ECRAN
     * D'ADMINISTRATION ne doit en revanche jamais recevoir ni reafficher un
     * endpoint complet ; il ne peut donc viser que par l'identifiant DE LIGNE
     * (`PushSubscription::id`), que l'ecran a deja affiche sans rien exposer.
     *
     * Le filtre sur `user_id` reste la garde reelle, EXACTEMENT comme dans
     * `renoncer()` : sans lui, un identifiant de ligne connu (jamais secret,
     * un UUID visite dans l'URL) permettrait de retirer l'appareil de
     * n'importe qui. La requete part TOUJOURS de `$proprietaire`, jamais d'un
     * identifiant seul.
     *
     * Rend `true` si une ligne a ete retiree. `false` n'est PAS une erreur :
     * la ligne pouvait deja avoir disparu (double clic, onglet concurrent),
     * ou appartenir a un autre compte — l'appelant ne doit pas pouvoir
     * distinguer les deux cas, pour ne rien reveler sur l'existence d'une
     * ligne appartenant a quelqu'un d'autre.
     */
    public function renoncerParId(User $proprietaire, string $id): bool
    {
        return PushSubscription::query()
            ->where('id', $id)
            ->where('user_id', $proprietaire->getKey())
            ->delete() > 0;
    }

    /**
     * Les appareils de cette personne, le plus recemment vu en tete.
     *
     * @return \Illuminate\Support\Collection<int, PushSubscription>
     */
    public function appareilsDe(User $proprietaire)
    {
        return PushSubscription::query()
            ->where('user_id', $proprietaire->getKey())
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * Ce navigateur est-il DEJA abonne pour cette personne ?
     *
     * Interroge par endpoint, et verifie le proprietaire : l'appareil peut
     * porter un abonnement revendique par un compte frere, et dans ce cas la
     * reponse est NON pour cette personne-ci. C'est ce qui permet a l'ecran
     * d'afficher un etat honnete apres une bascule de compte.
     */
    public function estAbonne(User $proprietaire, string $endpoint): bool
    {
        return PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::empreinteDe(trim($endpoint)))
            ->where('user_id', $proprietaire->getKey())
            ->exists();
    }
}
