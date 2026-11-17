<?php

namespace App\Support\Notifications;

use App\Events\MemberNotificationCreated;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * TASK-1372 — l'orchestrateur d'emission.
 *
 * ## Ce qu'il fait, et ce qu'il ne fait PAS
 *
 * Il ne detient AUCUNE garde metier en propre. Les invariants — cle du
 * catalogue, `object_type`, UUID, `collapse_key`, frontiere de tenant — vivent
 * dans `NotificationInvariants` et sont appliques par le modele sur `creating`.
 * Un `MemberNotification::create()` direct et incorrect echoue donc tout autant.
 *
 * Deux autorites qui divergent valent moins qu'une seule : cette classe se
 * limite a ce que le modele ne peut pas faire seul — normaliser les UUID avant
 * ecriture, et resoudre l'idempotence.
 *
 * ## L'adresse email ne franchit jamais la frontiere
 *
 * Un destinataire se designe par son `User`. Connaitre l'adresse de quelqu'un
 * n'a jamais autorise a lui creer une notification dans une Organization dont il
 * n'est pas membre. Le producteur resout `email -> User -> membership` AVANT
 * d'appeler, et n'emet rien s'il ne trouve pas : inviter un inconnu par email
 * est un cas metier ordinaire, pas une erreur. L'emetteur, lui, recoit une
 * affirmation et la fait verifier.
 *
 * ## `event_id` identifie l'EVENEMENT, pas l'objet metier
 *
 * C'est le contrat, et il est facile a enfreindre : `loop_invitations.id` est
 * deja un UUID, donc tentant a reutiliser. **Un rappel, ou un second type de
 * notification sur le meme objet, est un evenement DIFFERENT et exige un
 * `event_id` different.**
 *
 * ## L'idempotence s'appuie sur la CONTRAINTE, pas sur une lecture prealable
 *
 * Un `SELECT` avant `INSERT` laisse une fenetre entre les deux. Ici on insere,
 * et c'est `UNIQUE(event_id, recipient_id)` qui tranche : sur violation, la
 * ligne existante est relue — puis **comparee champ a champ**. Si elle ne
 * correspond pas a la meme emission, on leve. Rendre silencieusement une
 * ancienne notification differente serait le pire des deux mondes : l'appelant
 * croirait avoir emis, et recevrait une ligne qui parle d'autre chose.
 *
 * ### Et l'insertion est enveloppee dans un POINT DE SAUVEGARDE
 *
 * Ce n'est pas de la precaution decorative : sur PostgreSQL, une violation de
 * contrainte **abandonne la transaction courante**, et toute requete suivante
 * echoue en 25P02 tant qu'on n'a pas fait de rollback. Le `SELECT` de
 * rattrapage tomberait donc — alors que SQLite, qui n'a pas ce comportement,
 * resterait vert. C'est exactement le genre d'ecart que le depot fait tourner
 * sur deux moteurs pour attraper.
 *
 * `DB::transaction()` imbrique ouvre un SAVEPOINT et y revient sur exception :
 * la transaction de l'appelant survit, et le rattrapage peut lire.
 */
final class NotificationEmitter
{
    /**
     * Le planificateur est OPTIONNEL a la construction, et resolu au besoin.
     *
     * Deux suites existantes font `new NotificationEmitter` : un parametre
     * obligatoire les casserait sans rien apporter. Le rendre injectable garde
     * la porte ouverte aux tests qui veulent l'observer.
     */
    public function __construct(private ?NotificationDeliveryPlanner $planner = null) {}

    private function planner(): NotificationDeliveryPlanner
    {
        return $this->planner ??= app(NotificationDeliveryPlanner::class);
    }

    /**
     * @param  string  $eventId  UUID de l'EVENEMENT DE NOTIFICATION — partage
     *                           par tous ses destinataires, jamais l'identifiant
     *                           de l'objet metier.
     * @param  array<string, bool>|null  $canauxAutorises  TASK-1778 — les
     *        verdicts de preference `[canal => autorise]` deja calcules POUR
     *        CE DESTINATAIRE. Un fan-out les resout en masse une fois pour
     *        tous ses destinataires, ce qui evite au planificateur de les
     *        relire personne par personne. `null` = qu'il les lise lui-meme,
     *        comportement de tous les appelants d'avant T1778.
     */
    public function emit(
        string $notificationKey,
        Organization|string $organization,
        User $recipient,
        string $eventId,
        string $objectType,
        ?string $objectId = null,
        ?User $actor = null,
        ?string $collapseKey = null,
        ?array $canauxAutorises = null,
    ): MemberNotification {
        // L'AUTORITE de normaliser est au modele, pour couvrir toutes les
        // portes. On applique ici la meme fonction — pas une seconde regle — sur
        // notre copie locale, parce que le rattrapage ci-dessous compare ces
        // valeurs a celles deja stockees : il lui faut la meme forme.
        $attributes = NotificationInvariants::canonicalize([
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
            'recipient_id' => $recipient->id,
            'notification_key' => $notificationKey,
            'event_id' => $eventId,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'actor_id' => $actor?->id,
            'collapse_key' => $collapseKey,
        ]);

        try {
            // Les invariants s'appliquent dans `creating` — donc aussi pour
            // quiconque ne passerait pas par cette methode.
            //
            // TASK-1377 — c'est ICI, et NULLE PART AILLEURS, que les livraisons
            // sont planifiees. Les deux `catch` ci-dessous rendent des lignes
            // DEJA existantes : y planifier renverrait un email a chaque rejeu
            // d'un producteur, ce que l'idempotence annoncee de ce module
            // interdit.
            //
            // La garantie est une garantie de CHEMIN. Elle ne repose pas sur
            // `wasRecentlyCreated` : ce drapeau appartient a l'instance, se perd
            // sur `fresh()` (constate en T1374) et ne prouve donc pas par quelle
            // branche on est passe.
            return DB::transaction(function () use ($attributes, $canauxAutorises) {
                $notification = MemberNotification::create($attributes);

                $this->planner()->plan($notification, $canauxAutorises);

                $this->diffuserSiActive($notification);

                return $notification;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->existingEmission($attributes);
        } catch (InvalidArgumentException $refus) {
            // Une garde d'appartenance a parle AVANT l'insertion — donc avant
            // que la contrainte d'unicite ait pu trancher. Si l'evenement a
            // DEJA ete delivre a cette personne, le rejeu ne cree rien : il
            // constate. Le refuser ferait echouer definitivement un producteur
            // qui rejoue un fait acquis, simplement parce qu'un membre a quitte
            // l'Organization depuis — et l'idempotence est un contrat annonce de
            // ce module, pas un effet de bord.
            //
            // Rien n'est assoupli pour autant : sans ligne existante a
            // l'identique, le refus repart tel quel.
            return $this->alreadyDelivered($attributes) ?? throw $refus;
        }
    }

    /**
     * TASK-1843 — diffusion temps reel du badge, OFF par defaut
     * (`config('notifications_realtime.enabled')`), independante du pilote
     * ChatLoop (`config('loop_chat.realtime_enabled')`).
     *
     * ## Pourquoi `DB::afterCommit()` est pose ICI, a la main
     *
     * Cette methode est appelee depuis l'INTERIEUR du `DB::transaction()`
     * de `emit()` ci-dessus — mais `emit()` elle-meme est tres souvent
     * appelee DEPUIS la transaction d'un producteur (mentions, demandes
     * d'aide, invitations...) : `DB::transaction()` imbrique n'ouvre alors
     * qu'un POINT DE SAUVEGARDE, et le commit REEL n'arrive que beaucoup
     * plus haut dans la pile d'appel, hors de portee de tout `catch` pose
     * ICI.
     *
     * Plutot que de faire porter cette garantie par CHACUN des producteurs
     * (10 fichiers, hors perimetre de cette TASK — section 3 du mandat),
     * la diffusion est enregistree dans un callback `DB::afterCommit()`
     * qui, lui, ne s'execute QUE lorsque la transaction la plus EXTERNE
     * commit vraiment — peu importe sa profondeur d'imbrication. Le
     * `try/catch` est a L'INTERIEUR de ce meme callback : si Reverb est en
     * panne a ce moment-la, l'exception est avalee ICI, jamais rejouee au
     * `foreach` d'`executeCallbacks()` qui l'execute — elle ne peut donc
     * JAMAIS casser ni la transaction qui vient de reussir, ni un AUTRE
     * callback `afterCommit` enregistre a cote (ex. l'envoi Email/Web Push
     * de CETTE MEME notification, ou l'event `LoopMessageCreated` d'un
     * message qui porterait une mention). C'est une garantie plus large
     * que celle de TASK-1839 (qui exigeait un `catch` par site d'appel) —
     * necessaire ici vu le nombre de producteurs.
     *
     * La creation de la `MemberNotification` est, elle, deja actee avant
     * que ce callback ne parte : une panne Reverb ne peut donc annuler ni
     * la notification elle-meme, ni sa planification Email/Web Push
     * (`NotificationDeliveryPlanner::plan()`, appele juste avant, dans la
     * MEME transaction).
     */
    private function diffuserSiActive(MemberNotification $notification): void
    {
        if (! config('notifications_realtime.enabled')) {
            return;
        }

        DB::afterCommit(function () use ($notification) {
            try {
                broadcast(new MemberNotificationCreated($notification));
            } catch (BroadcastException $e) {
                Log::warning(
                    'TASK-1843 diffusion Reverb en echec, notification interne deja enregistree.',
                    [
                        'notification_id' => $notification->id,
                        'recipient_id' => $notification->recipient_id,
                        'raison' => $e->getMessage(),
                    ]
                );
            }
        });
    }

    /**
     * L'evenement a-t-il deja ete delivre, a l'identique, a cette personne ?
     *
     * @param  array<string, mixed>  $attributes
     */
    private function alreadyDelivered(array $attributes): ?MemberNotification
    {
        // Les trois cles de recherche doivent etre des UUID VALIDES, pas
        // seulement des chaines. Le refus rattrape ici peut justement venir d'un
        // UUID malforme : relancer un `SELECT` avec cette valeur rouvrirait la
        // 22P02 PostgreSQL que la garde vient de fermer — et SQLite, lui,
        // rendrait tranquillement `null`. La meme divergence, deplacee d'un cran.
        foreach (['organization_id', 'recipient_id', 'event_id'] as $requis) {
            $valeur = $attributes[$requis] ?? null;

            if (! is_string($valeur) || ! Str::isUuid($valeur)) {
                return null;
            }
        }

        try {
            // Le SELECT vit dans son propre point de sauvegarde. On est peut-etre
            // deja dans la transaction d'un producteur, et une lecture qui
            // echouerait la laisserait abandonnee sur PostgreSQL.
            return DB::transaction(fn () => $this->existingEmission($attributes));
        } catch (NotificationEmissionConflict) {
            // Meme `event_id`, mais une autre emission : ce n'est pas un rejeu,
            // et le refus d'origine reste le bon diagnostic.
            //
            // Le type est PRECIS a dessein. `QueryException` descend de
            // `PDOException`, donc de `RuntimeException` : un catch large aurait
            // fait passer un interblocage ou une connexion perdue pour un
            // conflit d'emission.
            return null;
        }
    }

    /**
     * Relit la ligne deja presente, et refuse de la rendre si elle differe.
     *
     * La lecture est tenant-scopee : `users.organization_id` est mutable
     * (une Organization supprimee detache ses membres), donc un `event_id`
     * reutilise a travers un changement d'appartenance pourrait sinon rendre a
     * un appelant une ligne estampillee d'un autre tenant.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function existingEmission(array $attributes): MemberNotification
    {
        $existing = MemberNotification::query()
            ->forRecipient($attributes['organization_id'], $attributes['recipient_id'])
            ->where('event_id', $attributes['event_id'])
            ->first();

        if ($existing === null) {
            throw new NotificationEmissionConflict(
                'Notification event_id already used for this recipient outside the target Organization.'
            );
        }

        foreach (['notification_key', 'object_type', 'object_id', 'actor_id', 'collapse_key'] as $champ) {
            if ($existing->{$champ} !== $attributes[$champ]) {
                throw new NotificationEmissionConflict(
                    "Notification event_id already used for this recipient with a different {$champ}. "
                    .'event_id identifies the notification event, not the business object: '
                    .'a reminder or a second notification kind needs its own event_id.'
                );
            }
        }

        return $existing;
    }
}
