<?php

namespace App\Support\Loops;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * TASK-1364 — l'autorite UNIQUE des Boucles qu'une personne peut voir.
 *
 * Extraction litterale de `LoopController::getAccessibleLoopsQuery()`, qui
 * gouvernait le catalogue depuis une methode PRIVEE de controleur. Le
 * controleur l'appelle desormais ici, et le Shell aussi : une seule verite,
 * pas deux regles de visibilite qui divergeront au premier changement.
 *
 * ## Ce que « visible » veut dire ici, et ce n'est pas une invention
 *
 * Organization courante, statut `active`, **aucun filtre de visibilite** —
 * c'est la decision de TASK-1075, portee par le docblock d'origine :
 *
 *   « privee » ne veut plus dire « cachee », mais « contenu reserve aux
 *   membres ».
 *
 * Une Boucle privee de sa propre Organization est donc decouvrable : le
 * catalogue en montre deja le nom et l'etat d'acces, pour qu'un humain puisse
 * demander a la rejoindre. Nommer cette Boucle n'accorde aucun droit d'entree.
 *
 * Les Boucles ARCHIVEES ne sont PAS ici : le catalogue en fait une seconde
 * liste, filtree par la permission `loops.archive`. Elles restent hors de
 * cette primitive.
 *
 * ## Ce que cette classe ne fait pas
 *
 * Elle ne dit pas si l'on peut ENTRER. `accessStateFor()` interroge
 * `LoopPolicy` pour cela, et ne recopie aucune de ses regles : une Policy sait
 * des choses qu'un champ `access_mode` ignore — compte desactive, appartenance
 * deja active, demande en cours.
 */
class VisibleLoops
{
    /** Entree libre : la Policy `join` autorise l'entree immediate. */
    public const ACCESS_OPEN = 'open';

    /** Sur demande : la Policy `requestToJoin` autorise une demande. */
    public const ACCESS_REQUEST = 'request';

    /** Une demande de cette personne est deja en attente. */
    public const ACCESS_PENDING = 'pending';

    /** Ni l'un ni l'autre : l'entree passe par une invitation. */
    public const ACCESS_INVITATION = 'invitation';

    /**
     * La requete du catalogue, telle quelle.
     *
     * Rendue publique pour que `LoopController` la consomme sans changer d'un
     * octet ce qu'il affichait : la vue lit `is_member`, `is_owner`,
     * `has_pending_request`, `active_members_count` et `last_message_at`, tous
     * annotes ici.
     *
     * @return Builder<Loop>
     */
    public function query(string $organizationId, User $user): Builder
    {
        return Loop::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            // `organization` : le libelle d'un type peut etre surcharge par
            // locataire, et la carte de catalogue le lit. Charge ici, une fois,
            // plutot qu'une requete par Boucle dans la vue.
            ->with(['owner.user', 'owners.user', 'categories', 'organization'])
            ->withCount('activeMembers')
            ->withMax('messages as last_message_at', 'created_at')
            ->withExists(['members as is_member' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', 'active');
            }])
            ->withExists(['members as is_owner' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', 'active')->where('role', 'owner');
            }])
            ->withExists(['joinRequests as has_pending_request' => function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', LoopJoinRequest::STATUS_PENDING);
            }])
            ->latest('updated_at');
    }

    /**
     * Les Boucles que cette personne peut voir, separees en deux ensembles :
     * `member` (elle en est membre actif) et `other` (le reste du catalogue).
     *
     * ## Le cas mono-loop, et pourquoi « other » y est vide
     *
     * Une Organization en `loop_mode = 'mono'` n'a PAS de surface de catalogue :
     * `LoopController::index()` y redirige vers la Boucle primaire au lieu de
     * lister. Il n'existe donc aucun endroit ou cette personne verrait la liste
     * des autres Boucles — et le contrat de cette TASK est de ne nommer que ce
     * que la surface metier montre deja.
     *
     * Ses propres Boucles restent nommees : elle les voit, elle y ecrit.
     *
     * C'est une RESTRICTION, jamais un elargissement : elle ne peut rien
     * reveler que le catalogue cachait.
     *
     * @return array{member: Collection<int, Loop>, other: Collection<int, Loop>}
     */
    public function groupedFor(Organization $organization, User $user): array
    {
        $loops = $this->query((string) $organization->id, $user)->get();

        [$member, $other] = $loops->partition(static fn (Loop $loop): bool => (bool) $loop->is_member);

        return [
            'member' => $member->values(),
            'other' => $organization->isMonoLoop() ? collect() : $other->values(),
        ];
    }

    /**
     * L'etat d'acces d'une Boucle POUR cette personne, demande aux Policies.
     *
     * L'ordre compte : une demande deja en attente prime sur « vous pouvez
     * demander », sinon on inviterait quelqu'un a redemander ce qu'il a deja
     * demande. Et `LoopPolicy::requestToJoin()` refuse d'ailleurs dans ce cas —
     * on ne se repose pas sur cette coincidence, on la rend explicite.
     */
    public function accessStateFor(Loop $loop, User $user): string
    {
        if ($user->can('join', $loop)) {
            return self::ACCESS_OPEN;
        }

        // `has_pending_request` est annote par la requete du catalogue ; hors
        // de ce chemin on interroge la table, plutot que de rendre un etat faux
        // parce qu'une annotation manquait.
        $hasPending = $loop->getAttribute('has_pending_request') !== null
            ? (bool) $loop->getAttribute('has_pending_request')
            : LoopJoinRequest::query()
                ->where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->where('status', LoopJoinRequest::STATUS_PENDING)
                ->exists();

        if ($hasPending) {
            return self::ACCESS_PENDING;
        }

        if ($user->can('requestToJoin', $loop)) {
            return self::ACCESS_REQUEST;
        }

        return self::ACCESS_INVITATION;
    }
}
