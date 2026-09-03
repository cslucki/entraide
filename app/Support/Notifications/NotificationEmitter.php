<?php

namespace App\Support\Notifications;

use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
            return DB::transaction(function () use ($attributes) {
                $notification = MemberNotification::create($attributes);

                $this->planner()->plan($notification);

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
