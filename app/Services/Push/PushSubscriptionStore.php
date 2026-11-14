<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
            //
            // TASK-1822 — ces deux colonnes decrivent la CREATION. Elles ne
            // figurent donc plus dans les colonnes mises a jour (voir
            // `$colonnesAMettreAJour` ci-dessous) : elles ne sont ecrites
            // qu'a l'INSERT. Elles restent ici parce que l'upsert en a
            // besoin pour la ligne qu'il cree.
            'created_env' => (string) config('app.env'),
            // TASK-1740 — la clef VAPID en vigueur au moment de la
            // revendication. Posee par le serveur, jamais recue du client.
            // C'est elle qui permettra de reconnaitre, plus tard, un
            // abonnement cree avec une clef qui n'a plus cours.
            //
            // TASK-1822 — ce que cette empreinte dit EXACTEMENT, et ce
            // qu'elle ne dit pas : « la clef que le serveur SERVAIT quand
            // cette ligne a ete creee ». Ce n'est pas une preuve
            // cryptographique de la clef que le navigateur detient — seul
            // `options.applicationServerKey`, cote navigateur, l'est, et le
            // serveur ne le recoit jamais (il ne doit pas : une declaration
            // du client n'est pas une preuve). C'est une PRESOMPTION forte,
            // puisque c'est cette clef-la que la page a servie au
            // navigateur, et elle suffit a reperer un changement de clef
            // serveur. Le controle cryptographique reel reste cote client
            // (`comparerCleVapid()`).
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
        // TASK-1822 — `created_env` et `vapid_key_hash` SORTENT de cette
        // liste, et c'est le coeur du correctif.
        //
        // Elles y figuraient depuis T1740, ce qui les faisait reecrire a
        // CHAQUE reconciliation — or la reconciliation tourne a chaque
        // affichage de l'ecran des reglages et a chaque retour sur
        // l'application. Deux consequences, mesurees en T1821 :
        //
        //  - `created_env` n'etait pas une origine : un abonnement de
        //    production relu depuis un autre environnement s'y reetiquetait
        //    seul, et le filtre de recette de T1740 cessait de l'ecarter ;
        //  - `vapid_key_hash` enregistrait la clef du DERNIER contact. Un
        //    abonnement cree sous une clef perimee redevenait donc
        //    « concordant » aux yeux du serveur : le livreur le servait, le
        //    fournisseur repondait 2xx, et le navigateur jetait le message
        //    EN SILENCE. La garde de T1740 s'effacait elle-meme.
        //
        // Elles decrivent la CREATION : elles ne doivent etre ecrites qu'une
        // fois, a l'INSERT. Un endpoint qui change produit une NOUVELLE
        // ligne (l'empreinte porte l'unicite), donc un renouvellement propre
        // est stampe correctement sans qu'aucune mise a jour soit necessaire.
        //
        // Ce qui reste mis a jour l'est pour une raison precise :
        // `user_id` porte la bascule entre comptes freres (CDC 5.2),
        // `p256dh`/`auth` peuvent etre renegociees pour un meme endpoint, et
        // `last_seen_at` est justement ce que la reconciliation vient dire.
        //
        // Lignes HISTORIQUES : celles dont le hash a deja ete reecrit avant
        // ce correctif gardent la valeur qu'elles portent. On ne leur invente
        // AUCUNE clef d'origine — une valeur reconstituee serait une
        // affirmation sans mesure, exactement ce que ce correctif combat.
        $colonnesAMettreAJour = ['user_id', 'endpoint', 'p256dh', 'auth', 'last_seen_at', 'updated_at'];

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
            //
            // TASK-1822 — cette ceinture est une MISE A JOUR : elle ne doit
            // donc ecrire que les colonnes que l'upsert met a jour, et pas
            // une de plus. Elle poussait jusqu'ici tout `$valeurs`,
            // `created_env` et `vapid_key_hash` compris — une ceinture qui
            // defait la regle que la bretelle vient de poser n'en est pas
            // une. Les deux chemins partagent desormais la MEME liste.
            $misesAJour = array_intersect_key($valeurs, array_flip($colonnesAMettreAJour));

            PushSubscription::query()
                ->where('endpoint_hash', $empreinte)
                ->update($misesAJour);
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
     * TASK-1821 — TOUS les abonnements de la plateforme, propriétaire
     * chargé, pour la console GLOBALE réservée au Superadmin réel
     * (`SuperAdminPushAccessPolicy` — vérifiée par l'APPELANT, jamais ici :
     * ce service ne connaît pas la requête courante).
     *
     * `$recherche` filtre sur le nom OU l'email du PROPRIÉTAIRE, jamais sur
     * l'endpoint ni sur une clef — ce service ne les rend d'ailleurs jamais.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, PushSubscription>
     */
    public function tous(?string $recherche, int $parPage = 25)
    {
        $recherche = trim((string) $recherche);

        return PushSubscription::query()
            // TASK-1821 — l'Organization du proprietaire est demandee par la
            // console. `Organization` porte SoftDeletes : on ne restreint
            // JAMAIS ce `with()` sans penser a `deleted_at` (T1814) — ici on
            // ne restreint rien, la relation rend ce qu'elle trouve et la vue
            // traite l'absence.
            ->with(['user', 'user.organization'])
            ->when($recherche !== '', function ($requete) use ($recherche): void {
                $requete->whereHas('user', function ($sousRequete) use ($recherche): void {
                    $sousRequete->where('name', 'like', "%{$recherche}%")
                        ->orWhere('email', 'like', "%{$recherche}%");
                });
            })
            ->orderByDesc('last_seen_at')
            ->paginate($parPage)
            ->withQueryString();
    }

    /**
     * TASK-1821 — retirer N'IMPORTE QUEL abonnement par identifiant de
     * ligne, SANS filtre sur le propriétaire.
     *
     * Distinct de `renoncerParId()` à dessein : celui-ci ne protège PAS un
     * compte contre un autre — c'est précisément pourquoi l'appelant DOIT
     * avoir déjà vérifié `Gate::allows('superadmin-push-all-devices')`
     * AVANT de l'invoquer. Ce service ne peut pas le revérifier lui-même :
     * il ne connaît pas la requête courante, et dupliquer la garde ici
     * créerait une seconde vérité sur une décision de sécurité.
     *
     * Rend `true` si une ligne a été retirée. L'appelant est responsable de
     * journaliser l'identifiant du compte AGISSANT et celui du compte
     * PROPRIÉTAIRE de la ligne retirée — ni l'un ni l'autre n'est connu ici.
     */
    public function retirerParIdPourSuperadmin(string $id): bool
    {
        return PushSubscription::query()->where('id', $id)->delete() > 0;
    }

    /**
     * TASK-1821 — le decompte des abonnements PAR ENVIRONNEMENT d'origine.
     *
     * Pourquoi par environnement, et pas un total unique : chaque
     * environnement a sa propre paire VAPID, donc l'etat « concordant /
     * divergent » n'a de sens qu'a l'interieur de celui qui a stampe la
     * ligne. Un total agrege melangerait des mesures incomparables — c'est
     * ce melange qui a fait lire « cinq abonnements defectueux » la ou il n'y
     * avait que cinq abonnements d'un AUTRE environnement.
     *
     * `eligibles` n'est renseigne que pour l'environnement COURANT : ailleurs,
     * l'eligibilite depend d'une clef que ce serveur n'a pas, et un chiffre
     * invente vaudrait moins que son absence. La regle employee est celle du
     * livreur lui-meme (`scopeCleVapidUtilisable`), jamais une copie.
     *
     * @return list<array{environnement: string, total: int, eligibles: ?int, concordants: int, divergents: int, inconnus: int, comparable: bool}>
     */
    public function resumeParEnvironnement(): array
    {
        $environnementCourant = (string) config('app.env');
        $empreinteCourante = PushSubscription::empreinteCleVapid();

        $lignes = PushSubscription::query()
            ->selectRaw('COALESCE(created_env, ?) AS env', ['(inconnu)'])
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN vapid_key_hash IS NULL THEN 1 ELSE 0 END) AS sans_empreinte')
            ->selectRaw('SUM(CASE WHEN vapid_key_hash = ? THEN 1 ELSE 0 END) AS concordants', [$empreinteCourante])
            ->groupBy('env')
            ->orderBy('env')
            ->get();

        return $lignes->map(function ($ligne) use ($environnementCourant, $empreinteCourante): array {
            $total = (int) $ligne->total;
            $sansEmpreinte = (int) $ligne->sans_empreinte;
            $concordants = $empreinteCourante === null ? 0 : (int) $ligne->concordants;
            $comparable = $ligne->env === $environnementCourant && $empreinteCourante !== null;

            return [
                'environnement' => (string) $ligne->env,
                'total' => $total,
                // Meme regle que le livreur : empreinte absente OU identique.
                'eligibles' => $comparable ? $sansEmpreinte + $concordants : null,
                'concordants' => $comparable ? $concordants : 0,
                'divergents' => $comparable ? $total - $sansEmpreinte - $concordants : 0,
                'inconnus' => $comparable ? $sansEmpreinte : 0,
                'comparable' => $comparable,
            ];
        })->all();
    }

    /**
     * TASK-1821 — les dernieres livraisons Web Push de ces personnes.
     *
     * ## Ce que cette lecture ne peut PAS dire, et le dit
     *
     * `member_notification_deliveries` ne porte AUCUN identifiant
     * d'abonnement : sa cle est `(notification_id, channel)`, et son
     * `diagnostic` ne contient qu'un AGREGAT (`devices:2 revoked:1`). Une
     * livraison ne peut donc jamais etre attribuee a un appareil — seulement
     * a une PERSONNE. L'appelant doit l'afficher comme tel ; presenter une de
     * ces lignes en face d'un appareil serait inventer une tracabilite qui
     * n'existe pas (champs manquants documentes dans la fiche T1821).
     *
     * UNE requete pour toute la page, jamais une par ligne.
     *
     * @param  list<string>  $idsUtilisateurs
     * @return array<string, list<object>> id du compte => dernieres livraisons
     */
    public function dernieresLivraisonsWebPush(array $idsUtilisateurs, int $parPersonne = 5): array
    {
        if ($idsUtilisateurs === []) {
            return [];
        }

        return DB::table('member_notification_deliveries as d')
            ->join('member_notifications as n', 'n.id', '=', 'd.notification_id')
            ->whereIn('n.recipient_id', $idsUtilisateurs)
            ->where('d.channel', NotificationCatalogue::CHANNEL_WEB_PUSH)
            ->orderByDesc('d.updated_at')
            ->limit(count($idsUtilisateurs) * $parPersonne * 4)
            ->get([
                'n.recipient_id',
                'n.notification_key',
                'd.status',
                'd.diagnostic',
                'd.attempts',
                'd.sent_at',
                'd.updated_at',
            ])
            ->groupBy('recipient_id')
            ->map(fn ($livraisons) => $livraisons->take($parPersonne)->values()->all())
            ->all();
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

    /**
     * TASK-1821 — la ligne de CET appareil, pour CETTE personne, ou `null`.
     *
     * Meme filtre que `estAbonne()` et `renoncer()` (endpoint + proprietaire) :
     * ce n'est pas une nouvelle regle, seulement la version qui rend la LIGNE
     * plutot qu'un booleen, pour les appelants qui ont besoin de relire
     * `device_label` ou `endpoint_hash` — jamais l'endpoint ni les clefs,
     * qui restent hors de ce que ce service accepte de rendre ailleurs.
     */
    public function trouverParEndpoint(User $proprietaire, string $endpoint): ?PushSubscription
    {
        return PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::empreinteDe(trim($endpoint)))
            ->where('user_id', $proprietaire->getKey())
            ->first();
    }
}
