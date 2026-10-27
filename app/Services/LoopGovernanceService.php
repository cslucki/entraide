<?php

namespace App\Services;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Support\Loops\LoopRoleRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every transition of a Loop role passes through here.
 *
 * The invariant this class exists to hold: a Loop always keeps at least one
 * active owner. It is checked on every path that could break it — demotion,
 * removal, voluntary departure, membership deactivation — because guarding only
 * the obvious one leaves the others open.
 *
 * Each transition runs in a transaction and locks the Loop's memberships, so two
 * simultaneous demotions cannot both see "there is another owner" and leave the
 * Loop with none. SQLite does not really enforce lockForUpdate; the concurrency
 * test runs on PostgreSQL for that reason.
 *
 * Nothing else in the codebase writes loop_members.role.
 */
class LoopGovernanceService
{
    public const RESULT_OK = 'ok';

    public const RESULT_UNCHANGED = 'unchanged';

    public const RESULT_LAST_OWNER = 'last_owner';

    public const RESULT_NOT_IN_LOOP = 'not_in_loop';

    public const RESULT_INACTIVE = 'inactive';

    public const RESULT_INVALID_ROLE = 'invalid_role';

    public function __construct(private LoopRoleRegistry $roles) {}

    /** Active owners, counted on canonical roles so a legacy alias still counts. */
    public function countActiveOwners(Loop $loop): int
    {
        return LoopMember::where('loop_id', $loop->id)
            ->where('status', 'active')
            ->where('role', LoopRoleRegistry::OWNER)
            ->count();
    }

    /** @return Collection<int, LoopMember> */
    public function activeOwners(Loop $loop)
    {
        return LoopMember::where('loop_id', $loop->id)
            ->where('status', 'active')
            ->where('role', LoopRoleRegistry::OWNER)
            ->with('user')
            // `id` en second critere : `joined_at` est a la seconde, et des
            // proprietaires promus dans la meme seconde s'egaleraient.
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * True when this membership is the only thing standing between the Loop and
     * having no owner at all.
     */
    public function isLastActiveOwner(LoopMember $member): bool
    {
        return $this->roles->canonical($member->role) === LoopRoleRegistry::OWNER
            && $member->status === 'active'
            && LoopMember::where('loop_id', $member->loop_id)
                ->where('status', 'active')
                ->where('role', LoopRoleRegistry::OWNER)
                ->where('id', '!=', $member->id)
                ->count() === 0;
    }

    /**
     * Move a membership to a canonical role.
     *
     * Handles every transition in one place — promotion and demotion alike —
     * because the guard is the same in both directions: the change must not
     * leave the Loop without an active owner.
     */
    /**
     * TASK-1693 bug P — `$autoriserDepartDuDernierProprietaire`, et pourquoi il
     * est un PARAMETRE plutot qu'une suppression de la garde.
     *
     * L'invariant « il reste au moins un proprietaire actif » protege un etat
     * reel : une Boucle orpheline ne se gouverne plus que par
     * l'administration. Il reste donc la regle, et la valeur par defaut est
     * `false` : les quatre ecrans et tous les appelants existants sont
     * inchanges a l'octet.
     *
     * MASTER a demande (21/10) de pouvoir malgre tout retrograder le dernier
     * proprietaire depuis l'ADMINISTRATION — « option B, et on garde A ». Ce
     * drapeau est donc une derogation NOMMEE, demandee explicitement a chaque
     * appel, jamais un assouplissement general : un appelant qui l'ignore
     * continue de se faire refuser.
     *
     * Il ne vaut que pour la RETROGRADATION. Le retrait pur et simple du
     * dernier proprietaire reste refuse : une fois retrograde, la personne
     * n'est plus proprietaire et « Retirer » redevient disponible par
     * composition. On n'ouvre donc qu'une porte, pas deux.
     */
    public function changeRole(LoopMember $member, string $targetRole, bool $autoriserDepartDuDernierProprietaire = false): string
    {
        if (! $this->roles->isCanonical($targetRole)) {
            return self::RESULT_INVALID_ROLE;
        }

        return DB::transaction(function () use ($member, $targetRole, $autoriserDepartDuDernierProprietaire) {
            // Lock the whole Loop's memberships, not just this row: the decision
            // depends on how many *other* owners there are.
            $locked = LoopMember::where('loop_id', $member->loop_id)
                ->lockForUpdate()
                ->get();

            $fresh = $locked->firstWhere('id', $member->id);

            if (! $fresh) {
                return self::RESULT_NOT_IN_LOOP;
            }

            if ($fresh->status !== 'active') {
                return self::RESULT_INACTIVE;
            }

            $current = $this->roles->canonical($fresh->role);

            // Already canonical and on target: nothing to write. A stored legacy
            // alias still gets rewritten, so touching an old row canonicalises it.
            if ($current === $targetRole && $fresh->role === $targetRole) {
                return self::RESULT_UNCHANGED;
            }

            if ($current === LoopRoleRegistry::OWNER && $targetRole !== LoopRoleRegistry::OWNER) {
                $otherOwners = $locked
                    ->where('status', 'active')
                    ->where('id', '!=', $fresh->id)
                    ->filter(fn (LoopMember $m) => $this->roles->canonical($m->role) === LoopRoleRegistry::OWNER)
                    ->count();

                if ($otherOwners === 0 && ! $autoriserDepartDuDernierProprietaire) {
                    return self::RESULT_LAST_OWNER;
                }
            }

            $fresh->update(['role' => $targetRole]);

            return self::RESULT_OK;
        });
    }

    public function promoteToOwner(LoopMember $member): string
    {
        return $this->changeRole($member, LoopRoleRegistry::OWNER);
    }

    public function promoteToFacilitator(LoopMember $member): string
    {
        return $this->changeRole($member, LoopRoleRegistry::FACILITATOR);
    }

    public function demoteToMember(LoopMember $member): string
    {
        return $this->changeRole($member, LoopRoleRegistry::MEMBER);
    }

    /**
     * TASK-1693 bug O — TRANSFERER la propriete, en un seul geste.
     *
     * ## Ce qui gênait
     *
     * L'invariant « il reste au moins un proprietaire actif » est juste, et il
     * n'est pas en cause. Mais il imposait DEUX gestes, dans DEUX endroits de
     * l'ecran : promouvoir quelqu'un d'autre depuis sa ligne, puis revenir sur
     * celle du proprietaire pour le retrograder ou le retirer. Entre les deux,
     * la Boucle avait deux proprietaires — un etat que personne n'avait voulu.
     *
     * Ici, la succession est NOMMEE : on designe qui reprend, et le sortant
     * redescend. Une seule transaction, l'invariant jamais rompu, et aucun
     * etat intermediaire visible.
     *
     * ## Le sortant devient MEMBRE, il n'est pas retire
     *
     * « Supprimer le proprietaire » est ambigu : retirer son ROLE, ou retirer
     * la PERSONNE ? On fait le geste reversible — il reste dans la Boucle. S'il
     * doit aussi en sortir, « Retirer » devient disponible sur sa ligne des
     * l'instant ou il n'est plus le dernier proprietaire : les deux gestes se
     * composent, au lieu d'en inventer un troisieme qui ferait les deux a la
     * fois sans qu'on puisse revenir en arriere.
     *
     * ## Pourquoi pas deux appels a `changeRole()`
     *
     * Parce que l'ordre compte et que chacun ouvre sa propre transaction.
     * Promouvoir d'abord puis retrograder laisserait, entre les deux, une
     * fenetre ou un autre processus verrait deux proprietaires ; retrograder
     * d'abord serait refuse par l'invariant. Les deux ecritures vivent donc
     * dans UNE transaction, avec le meme verrou que les autres gestes.
     */
    public function transferOwnership(LoopMember $sortant, LoopMember $successeur): string
    {
        if ($sortant->loop_id !== $successeur->loop_id) {
            return self::RESULT_NOT_IN_LOOP;
        }

        if ($sortant->id === $successeur->id) {
            return self::RESULT_UNCHANGED;
        }

        return DB::transaction(function () use ($sortant, $successeur) {
            $verrouilles = LoopMember::where('loop_id', $sortant->loop_id)->lockForUpdate()->get();

            $sortantFrais = $verrouilles->firstWhere('id', $sortant->id);
            $successeurFrais = $verrouilles->firstWhere('id', $successeur->id);

            if (! $sortantFrais || ! $successeurFrais) {
                return self::RESULT_NOT_IN_LOOP;
            }

            if ($sortantFrais->status !== 'active' || $successeurFrais->status !== 'active') {
                return self::RESULT_INACTIVE;
            }

            if ($this->roles->canonical($sortantFrais->role) !== LoopRoleRegistry::OWNER) {
                return self::RESULT_UNCHANGED;
            }

            $successeurFrais->update(['role' => LoopRoleRegistry::OWNER]);
            $sortantFrais->update(['role' => LoopRoleRegistry::MEMBER]);

            return self::RESULT_OK;
        });
    }

    /**
     * Remove someone from the Loop.
     *
     * Replaces the previous blanket refusal for any owner: an owner may be
     * removed as long as another active one remains. Only the last is protected.
     */
    public function removeMember(LoopMember $member): string
    {
        return DB::transaction(function () use ($member) {
            $locked = LoopMember::where('loop_id', $member->loop_id)->lockForUpdate()->get();
            $fresh = $locked->firstWhere('id', $member->id);

            if (! $fresh) {
                return self::RESULT_NOT_IN_LOOP;
            }

            if ($fresh->status !== 'active') {
                return self::RESULT_UNCHANGED;
            }

            if ($this->roles->canonical($fresh->role) === LoopRoleRegistry::OWNER) {
                $otherOwners = $locked
                    ->where('status', 'active')
                    ->where('id', '!=', $fresh->id)
                    ->filter(fn (LoopMember $m) => $this->roles->canonical($m->role) === LoopRoleRegistry::OWNER)
                    ->count();

                if ($otherOwners === 0) {
                    return self::RESULT_LAST_OWNER;
                }
            }

            $fresh->update(['status' => 'left']);

            return self::RESULT_OK;
        });
    }

    /**
     * Voluntary departure.
     *
     * Same rule as removal, and deliberately the same code path: a controller
     * mutating the membership directly would sidestep the invariant.
     */
    public function leave(Loop $loop, string $userId): string
    {
        $member = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if (! $member) {
            return self::RESULT_NOT_IN_LOOP;
        }

        return $this->removeMember($member);
    }

    /**
     * Any transition of an active membership to a non-active status.
     *
     * The single entry point for deactivation flows, so none of them can empty a
     * Loop of its owners by taking a different route.
     */
    public function deactivateMembership(LoopMember $member, string $status = 'left'): string
    {
        if ($status === 'active') {
            return self::RESULT_INVALID_ROLE;
        }

        return DB::transaction(function () use ($member, $status) {
            $locked = LoopMember::where('loop_id', $member->loop_id)->lockForUpdate()->get();
            $fresh = $locked->firstWhere('id', $member->id);

            if (! $fresh || $fresh->status !== 'active') {
                return self::RESULT_UNCHANGED;
            }

            if ($this->isLastActiveOwner($fresh)) {
                return self::RESULT_LAST_OWNER;
            }

            $fresh->update(['status' => $status]);

            return self::RESULT_OK;
        });
    }
}
