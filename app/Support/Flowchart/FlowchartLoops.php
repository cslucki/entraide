<?php

namespace App\Support\Flowchart;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * TASK-1608 — la projection des Boucles DEDIEE au payload du flowchart.
 *
 * ## Pourquoi elle n'est pas `VisibleLoops`, et pourquoi ce n'en est pas un doublon
 *
 * Arbitrage MASTER du 2026-09-20 (corrige apres relecture du modele sur
 * `develop`) : les deux surfaces ne repondent pas a la meme question.
 *
 * - `App\Support\Loops\VisibleLoops` repond a « quelles Boucles le produit
 *   INTERNE peut-il nommer a ce membre ? ». Reponse TASK-1075 : toutes les
 *   Boucles actives du tenant, « privee » voulant dire « contenu reserve »,
 *   pas « cachee ». Elle reste l'autorite du catalogue `/org/{org}/loops` et
 *   du Shell, et **n'est pas modifiee par cette TASK**.
 *
 * - Le flowchart repond a « quelles possibilites cette personne peut-elle
 *   reellement explorer ICI ? », sur une page PUBLIQUE. Une page publique ne
 *   peut pas emprunter la reponse d'une surface interne.
 *
 * Ce n'est donc pas une seconde autorite metier generale : c'est une
 * projection de payload, bornee a une seule surface, qui n'accorde aucun
 * droit. Les Policies restent seules autorites des actions reelles, et le CTA
 * du flowchart mene a la fiche existante — jamais a une ecriture.
 *
 * ## DEUX AXES DISTINCTS, et ils ne se confondent pas
 *
 * - `visibility` : `public` | `private`
 * - `access_mode` : `open` | `request` | `invitation`
 *
 * ## La regle, telle qu'elle a ete tranchee
 *
 * SOCLE PUBLIC — servi a tout le monde, invite compris :
 * `status = active` ET `visibility = public` ET `access_mode ∈ {open, request}`.
 *
 * | visibility | access_mode | socle public |
 * |---|---|---|
 * | public  | open       | visible |
 * | public  | request    | visible |
 * | public  | invitation | **absente** |
 * | private | open       | **absente** |
 * | private | request    | **absente** |
 * | private | invitation | **absente** |
 *
 * AJOUT MEMBRE — pour qui appartient a l'Organization VISITEE : ses Boucles
 * actives dont il est membre ACTIF, meme `private`, meme `invitation`, parce
 * qu'elles font reellement partie de son espace. Deduplication par id.
 *
 * Ce qui n'est jamais ajoute : une Boucle privee dont il n'est pas membre, une
 * Boucle `invitation` dont il n'est pas membre, une Boucle d'une autre
 * Organization.
 *
 * ## Le cas du visiteur connecte venu d'une AUTRE Organization
 *
 * Il recoit le socle public, et rien de plus : l'ajout membre est borne a
 * l'Organization visitee, et il n'y appartient pas. Ce socle est, par
 * construction, ce qu'un invite anonyme voit deja — l'identite d'un etranger
 * n'ouvre donc aucune porte supplementaire.
 */
final class FlowchartLoops
{
    /** Les deux seuls modes d'acces qui sont une possibilite offerte publiquement. */
    public const PUBLIC_ACCESS_MODES = [Loop::ACCESS_OPEN, Loop::ACCESS_REQUEST];

    /**
     * Les Boucles a poser sur la carte, pour cette personne, dans ce tenant.
     *
     * Chaque Boucle rendue porte `is_member` et `active_members_count`,
     * annotes par la requete : la vue n'a aucune question a reposer.
     *
     * @return Collection<int, Loop>
     */
    public function for(Organization $organization, ?User $user): Collection
    {
        $appartient = $user !== null && $user->organization_id === $organization->id;

        $loops = $this->base($organization, $user)
            ->where(function (Builder $query) use ($organization, $user, $appartient): void {
                // SOCLE PUBLIC — tout le monde, invite compris.
                $query->where(function (Builder $public): void {
                    $public->where('visibility', 'public')
                        ->whereIn('access_mode', self::PUBLIC_ACCESS_MODES);
                });

                if (! $appartient) {
                    return;
                }

                // AJOUT MEMBRE — ses Boucles, quelles que soient leur
                // visibilite et leur modalite d'entree. L'union est ecrite en
                // OR sur la MEME requete : une seule lecture, et la
                // deduplication par id est acquise par construction plutot que
                // recollee apres coup.
                $query->orWhereExists(function ($membre) use ($organization, $user): void {
                    $membre->selectRaw('1')
                        ->from('loop_members')
                        ->whereColumn('loop_members.loop_id', 'loops.id')
                        ->where('loop_members.organization_id', $organization->id)
                        ->where('loop_members.user_id', $user->id)
                        ->where('loop_members.status', 'active');
                });
            })
            ->get();

        return $loops;
    }

    /**
     * Le socle commun : CE tenant, des Boucles vivantes, et les annotations.
     *
     * `organization_id` est pose ici, en dehors du groupe `OR` ci-dessus :
     * une borne de tenant qui vivrait DANS un groupe de conditions
     * alternatives cesserait d'etre une borne. Meme raison pour `status`.
     *
     * @return Builder<Loop>
     */
    private function base(Organization $organization, ?User $user): Builder
    {
        $query = Loop::query()
            ->where('organization_id', $organization->id)
            ->where('status', 'active')
            ->with(['organization'])
            ->withCount('activeMembers')
            ->latest('updated_at');

        if ($user === null) {
            // Un invite n'est membre de rien et n'a demande nulle part : les
            // deux annotations vaudraient `false` partout. Deux sous-requetes
            // pour une constante connue d'avance ne sont pas une mesure.
            return $query;
        }

        return $query
            ->withExists(['members as is_member' => function ($q) use ($user): void {
                $q->where('user_id', $user->id)->where('status', 'active');
            }])
            ->withExists(['joinRequests as has_pending_request' => function ($q) use ($user): void {
                $q->where('user_id', $user->id)->where('status', LoopJoinRequest::STATUS_PENDING);
            }]);
    }

    /**
     * L'etiquette d'acces a afficher, pour cette personne, sur cette Boucle.
     *
     * Elle decrit ce que la carte montre, elle n'autorise rien.
     *
     * - membre actif -> `member` ;
     * - demande en attente -> `pending` ;
     * - sinon, le mode d'entree de la Boucle (`open` ou `request`).
     *
     * Volontairement PAS `VisibleLoops::accessStateFor()` : cette methode rend
     * `invitation` PAR DEFAUT des que ni `join` ni `requestToJoin` n'autorisent,
     * ce qui est le cas pour un invite, pour un visiteur d'une autre
     * Organization, et meme pour un membre actif d'une Boucle `open`
     * ({@see \App\Policies\LoopPolicy::join()} refuse a qui est deja membre).
     * Elle etiqueterait donc « sur invitation » des Boucles qui ne le sont pas.
     */
    public function accessLabelFor(Loop $loop, ?User $user): string
    {
        if ($user !== null && (bool) $loop->getAttribute('is_member')) {
            return 'member';
        }

        if ($user !== null && (bool) $loop->getAttribute('has_pending_request')) {
            return 'pending';
        }

        return (string) $loop->access_mode;
    }

    /** Membre actif de cette Boucle ? Lit l'annotation, interroge la table sinon. */
    public function isMember(Loop $loop, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $annote = $loop->getAttribute('is_member');

        if ($annote !== null) {
            return (bool) $annote;
        }

        return LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
