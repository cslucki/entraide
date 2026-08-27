<?php

namespace App\Policies;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Support\Loops\LoopPermissionResolver;

class LoopPolicy
{
    /**
     * Source of truth for "who can create a Loop" via the member-facing flow
     * (LoopController). The platform super-admin flow (AdminLoopController)
     * is deliberately NOT bypassed here: is_admin never grants an implicit
     * tenant — a super-admin creates only through the explicitly
     * Organization-scoped admin form.
     */
    public function create(User $user, Organization $organization): bool
    {
        if ($user->isDeactivated()) {
            return false;
        }

        if ($user->organization_id !== $organization->id) {
            return false;
        }

        if (! $organization->loops_enabled) {
            return false;
        }

        if ($organization->admin_id === $user->id) {
            return true;
        }

        return (bool) $organization->members_can_create_loops;
    }

    /**
     * Source of truth for "who can edit a Loop" via the member-facing flow
     * (LoopController). Same deliberate choice as create(): no is_admin
     * bypass here — the super-admin flow stays AdminLoopController-only.
     */
    public function update(User $user, Loop $loop): bool
    {
        if ($user->isDeactivated()) {
            return false;
        }

        // Une Boucle archivee ne se modifie plus.
        //
        // Cette ability est le goulot de plusieurs ecritures qui n'interrogent
        // pas le resolveur : l'identite de la Boucle, ses invitations, et — via
        // DossierPolicy::update, qui delegue ici pour un Dossier de Boucle — le
        // document racine et les fichiers du Dossier racine. La garde est donc
        // posee une fois ici plutot que quatre fois plus loin.
        //
        // Archiver et reactiver ne passent pas par la : ils demandent
        // `loops.archive` au resolveur, qui les laisse seuls traverser.
        if ($loop->isArchived()) {
            return false;
        }

        if ($user->organization_id !== $loop->organization_id) {
            return false;
        }

        $organization = $loop->organization;

        if (! $organization || ! $organization->loops_enabled) {
            return false;
        }

        if ($organization->admin_id === $user->id) {
            return true;
        }

        return LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('role', 'owner')
            ->exists();
    }

    /**
     * Full workspace (ChatLoop + Cards) — active membership only. No is_admin
     * or org-admin bypass: seeing the workspace requires actually being a
     * member, never an implicit grant.
     */
    public function viewWorkspace(User $user, Loop $loop): bool
    {
        if ($user->isDeactivated()) {
            return false;
        }

        if ($user->organization_id !== $loop->organization_id) {
            return false;
        }

        return LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Immediate join for access_mode=open Loops. Same tenant/banned/active
     * checks as viewWorkspace, but requires NOT already being an active
     * member and the Loop being open access.
     */
    public function join(User $user, Loop $loop): bool
    {
        if ($user->isDeactivated()) {
            return false;
        }

        if ($user->organization_id !== $loop->organization_id) {
            return false;
        }

        if (! $loop->isActive() || ! $loop->isOpenAccess()) {
            return false;
        }

        return ! LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Sending a join request for access_mode=request Loops. Refused if
     * already an active member or if a pending request already exists.
     */
    public function requestToJoin(User $user, Loop $loop): bool
    {
        if ($user->isDeactivated()) {
            return false;
        }

        if ($user->organization_id !== $loop->organization_id) {
            return false;
        }

        if (! $loop->isActive() || ! $loop->isRequestAccess()) {
            return false;
        }

        $alreadyMember = LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if ($alreadyMember) {
            return false;
        }

        return ! LoopJoinRequest::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', LoopJoinRequest::STATUS_PENDING)
            ->exists();
    }

    /**
     * Accepting/rejecting join requests — same "who can manage this Loop"
     * rule as update(): owner or Organization admin, no is_admin bypass.
     */
    /**
     * REVOIR les demandes d'adhesion : accepter, refuser.
     *
     * TASK-1313 : demandait `loop_members.add`. TASK-1313 (review fix) : demande
     * `loop_members.review_join_requests`, la permission qui porte exactement ce
     * geste dans la matrice.
     *
     * POURQUOI LES DEUX SONT SEPARES. Repondre a une demande d'adhesion et
     * ajouter quelqu'un de sa propre initiative ne sont pas le meme acte : l'un
     * repond a une sollicitation, l'autre en prend l'initiative. Les faire
     * dependre d'une seule permission rendait les overrides d'Organization
     * incoherents — revoquer `loop_members.add` pour interdire l'ajout direct
     * aurait FERME au passage la revue des demandes, sans que personne l'ait
     * voulu.
     *
     * Historiquement, cette methode deleguait a `update()` — donc `owner`
     * strict. `manageJoinRequests` est nee le 2 aout 2026 (TASK-1075), la
     * matrice de permissions le 3 (TASK-1079) : la policy precedait le registre
     * canonique d'une journee, et n'a jamais ete revisitee quand
     * `loop_members.review_join_requests` a ete accordee au facilitator.
     *
     * L'invitation d'une adresse e-mail EXTERIEURE reste, elle, gouvernee par
     * `update()` — ability distincte, volontairement non elargie.
     */
    public function manageJoinRequests(User $user, Loop $loop): bool
    {
        return $this->resolveLoopPermission($user, $loop, 'loop_members.review_join_requests');
    }

    /**
     * AJOUTER a la Boucle un utilisateur deja membre de l'Organization.
     *
     * Ability distincte de `manageJoinRequests()` : voir l'explication
     * ci-dessus. Un animateur peut composer son equipe DANS le tenant ; il ne
     * fait entrer personne DANS le tenant — c'est `update()` qui gouverne
     * l'invitation d'une adresse exterieure, et elle n'a pas bouge.
     */
    public function addMembers(User $user, Loop $loop): bool
    {
        return $this->resolveLoopPermission($user, $loop, 'loop_members.add');
    }

    /**
     * Le corps partage des deux abilities ci-dessus : compte actif, Boucle
     * vivante, puis le resolveur canonique — jamais un second systeme de
     * permissions.
     */
    private function resolveLoopPermission(User $user, Loop $loop, string $permission): bool
    {
        if ($user->isDeactivated() || $loop->isArchived()) {
            return false;
        }

        return app(LoopPermissionResolver::class)->can($user, $loop, $permission);
    }
}
