<?php

namespace App\Services;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Referral;
use App\Models\User;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmissionConflict;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class LoopService
{
    public function createLoopForOrg(User $user, string $organizationId, string $name, ?string $description = null, string $visibility = 'private', ?string $tagline = null, string $accessMode = Loop::ACCESS_REQUEST, ?string $type = null): Loop
    {
        $slug = $this->generateUniqueSlug($organizationId, $name);
        $registry = app(LoopTypeRegistry::class);

        $loop = Loop::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'tagline' => $tagline,
            'type' => $this->resolveCreationType($type),
            'status' => 'active',
            'visibility' => $visibility,
            'access_mode' => Loop::isValidAccessMode($accessMode) ? $accessMode : Loop::ACCESS_REQUEST,
            'created_by' => $user->id,
        ]);

        $this->addMember($loop, $user, 'owner');

        // Was missing here while createLoop() had it: a Loop created from the
        // admin came out with no cards at all and relied on the fallback.
        $registry->applyPreset($loop);

        app(LoopRootDocumentService::class)->ensureRootDocument($loop, $user);

        return $loop;
    }

    /**
     * The type a new Loop starts on.
     *
     * A type withdrawn from the offer cannot be picked at creation — unlike a
     * Loop that already carries one, there is nothing to preserve here.
     */
    private function resolveCreationType(?string $type): string
    {
        $registry = app(LoopTypeRegistry::class);

        return $registry->isAvailable($type) ? $registry->resolve($type) : $registry->default();
    }

    public function createLoop(User $user, string $name, ?string $description = null, string $visibility = 'private', ?string $tagline = null, string $accessMode = Loop::ACCESS_REQUEST, ?string $coverImagePath = null, ?string $type = null): Loop
    {
        $orgId = $user->organization_id;

        if (! $orgId) {
            throw new \RuntimeException('User has no organization.');
        }

        $slug = $this->generateUniqueSlug($orgId, $name);

        $loop = Loop::create([
            'organization_id' => $orgId,
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'tagline' => $tagline,
            'cover_image_path' => $coverImagePath,
            'type' => $this->resolveCreationType($type),
            'status' => 'active',
            'visibility' => $visibility,
            'access_mode' => Loop::isValidAccessMode($accessMode) ? $accessMode : Loop::ACCESS_REQUEST,
            'created_by' => $user->id,
        ]);

        $this->addMember($loop, $user, 'owner');

        // The type's card preset defines the Loop's starting composition
        // (TASK-1079). Additive and idempotent — see LoopTypeRegistry.
        app(LoopTypeRegistry::class)->applyPreset($loop);

        // Root Dossier and root document (TASK-1082). A Loop is never created
        // without them: the whole creation fails rather than leaving a Loop
        // whose card would show "no document".
        app(LoopRootDocumentService::class)->ensureRootDocument($loop, $user);

        return $loop;
    }

    public function updateLoop(Loop $loop, array $data): Loop
    {
        $loop->update($data);

        return $loop;
    }

    public function addMemberByUserId(Loop $loop, string $userId, string $role = 'member'): LoopMember
    {
        $user = User::assignable()->findOrFail($userId);

        if ($loop->organization_id !== $user->organization_id) {
            throw new \RuntimeException('Cannot add member from a different organization to this loop.');
        }

        $existing = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            if ($existing->status === 'active') {
                throw new \RuntimeException('User is already a member of this loop.');
            }

            // Reactivation must honour the role the caller asked for. It used to
            // set status alone, so re-adding a former member "as owner" silently
            // handed them back whatever role their old row happened to carry
            // (TASK-1079 CP5ter).
            $existing->update([
                'status' => 'active',
                'joined_at' => now(),
                'role' => app(LoopRoleRegistry::class)->canonical($role),
            ]);

            return $existing;
        }

        return LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $userId,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => $loop->organization_id,
        ]);
    }

    /**
     * Bulk-add existing Organization members to a Loop (TASK-1076, post-creation
     * step). Wraps addMemberByUserId() rather than duplicating its invariants:
     * cross-organization and deactivated users are refused there, and a user who
     * is already an active member is skipped instead of failing the whole batch,
     * so the operation is idempotent.
     *
     * A pending join request from someone added this way is closed as accepted —
     * otherwise it would linger and the person could appear as "waiting" while
     * already being a member.
     *
     * @param  array<int, string>  $userIds
     * @return array{added: int, skipped: int}
     */
    /**
     * Les personnes de l'Organization de la Boucle qui n'y sont pas encore.
     *
     * Cette requete vivait en prive dans LoopController et n'etait donc
     * atteignable que depuis l'ecran d'invitation. Trois ecrans en ont besoin —
     * l'invitation, la Card Membres et l'edition — et le serveur s'en ressert
     * pour re-verifier ce qu'un formulaire a poste : une seule definition.
     *
     * @return Collection<int, User>
     */
    public function invitableOrganizationMembers(Loop $loop): Collection
    {
        $alreadyIn = LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('status', 'active')
            ->pluck('user_id');

        return User::assignable()
            ->where('organization_id', $loop->organization_id)
            ->whereNotIn('id', $alreadyIn)
            ->orderBy('name')
            ->get(['id', 'name', 'first_name', 'email', 'avatar', 'banned_at']);
    }

    public function addMembersFromOrganization(Loop $loop, array $userIds, User $actor): array
    {
        $added = 0;
        $skipped = 0;

        foreach (array_unique($userIds) as $userId) {
            try {
                $this->addMemberByUserId($loop, $userId);
                $added++;
            } catch (\RuntimeException|ModelNotFoundException) {
                // Already an active member, cross-organization or deactivated:
                // silently skipped, the caller reports the counts.
                $skipped++;

                continue;
            }

            // TASK-1381 — les demandes sont RELUES avant d'etre decidees, au
            // lieu d'etre mises a jour en masse a l'aveugle.
            //
            // Un `update()` de query builder n'emet aucun evenement Eloquent et
            // ne rend que le nombre de lignes touchees : il ne dirait pas QUELLE
            // demande vient d'etre acceptee, donc rien ne pourrait etre notifie.
            // Ce chemin d'ajout en masse resout pourtant de vraies demandes en
            // attente — pour le demandeur, c'est exactement le meme fait que
            // depuis l'ecran de decision, et il merite la meme notification.
            $demandesEnAttente = LoopJoinRequest::where('loop_id', $loop->id)
                ->where('user_id', $userId)
                ->where('status', LoopJoinRequest::STATUS_PENDING)
                ->get();

            foreach ($demandesEnAttente as $demande) {
                $demande->update([
                    'status' => LoopJoinRequest::STATUS_ACCEPTED,
                    'decided_by' => $actor->id,
                    'decided_at' => now(),
                ]);

                $this->notifierDecisionDAdhesion(
                    $demande,
                    NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED,
                    $actor,
                );
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Kept as the historical entry point, now delegating to the governance
     * service. The blanket refusal for any owner is gone: an owner may be
     * removed while another active one remains, and only the last is protected.
     */
    public function removeMember(LoopMember $member): void
    {
        $result = app(LoopGovernanceService::class)->removeMember($member);

        if ($result === LoopGovernanceService::RESULT_LAST_OWNER) {
            throw new \RuntimeException('Cannot remove the last active owner of a loop.');
        }
    }

    public function addMember(Loop $loop, User $user, string $role = 'member'): LoopMember
    {
        if ($user->is_deactivated) {
            throw new \RuntimeException(__('validation.exists', ['attribute' => 'user']));
        }

        $orgId = $user->organization_id;

        if ($loop->organization_id !== $orgId) {
            throw new \RuntimeException('Cannot add member from a different organization to this loop.');
        }

        $existing = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            throw new \RuntimeException('User is already a member of this loop.');
        }

        return LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => $loop->organization_id,
        ]);
    }

    public function getEligibleReferrals(User $user, Loop $loop): Collection
    {
        $orgId = $user->organization_id;

        if ($loop->organization_id !== $orgId) {
            return new Collection;
        }

        $existingMemberUserIds = LoopMember::where('loop_id', $loop->id)
            ->pluck('user_id');

        return Referral::where('referrer_user_id', $user->id)
            ->where('organization_id', $loop->organization_id)
            ->whereHas('referred', function ($q) use ($loop, $existingMemberUserIds) {
                $q->where('organization_id', $loop->organization_id)
                    ->assignable()
                    ->whereNotIn('id', $existingMemberUserIds);
            })
            ->with('referred')
            ->get();
    }

    public function addReferralToLoop(Loop $loop, User $user, Referral $referral): LoopMember
    {
        if ($referral->referrer_user_id !== $user->id) {
            throw new \RuntimeException('This referral does not belong to you.');
        }

        if ($referral->organization_id !== $loop->organization_id) {
            throw new \RuntimeException('Cannot add cross-organization referral to this loop.');
        }

        $referred = $referral->referred;

        if (! $referred) {
            throw new \RuntimeException('Referred user not found.');
        }

        assert($referred instanceof User);

        return $this->addMember($loop, $referred);
    }

    public function joinOpenLoop(Loop $loop, User $user): LoopMember
    {
        $existing = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            if ($existing->status !== 'active') {
                $existing->update(['status' => 'active', 'joined_at' => now()]);
            }

            return $existing->fresh();
        }

        return LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => $loop->organization_id,
        ]);
    }

    public function requestToJoin(Loop $loop, User $user, ?string $message = null): LoopJoinRequest
    {
        return DB::transaction(function () use ($loop, $user, $message) {
            $existingPending = LoopJoinRequest::where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->where('status', LoopJoinRequest::STATUS_PENDING)
                ->lockForUpdate()
                ->exists();

            if ($existingPending) {
                throw new \RuntimeException('A pending join request already exists for this loop.');
            }

            return LoopJoinRequest::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'user_id' => $user->id,
                'message' => $message,
                'status' => LoopJoinRequest::STATUS_PENDING,
            ]);
        });
    }

    public function cancelJoinRequest(LoopJoinRequest $joinRequest): void
    {
        if (! $joinRequest->isPending()) {
            throw new \RuntimeException('Only a pending join request can be cancelled.');
        }

        $joinRequest->update(['status' => LoopJoinRequest::STATUS_CANCELLED]);
    }

    public function acceptJoinRequest(LoopJoinRequest $joinRequest, User $decidedBy): LoopMember
    {
        $member = DB::transaction(function () use ($joinRequest, $decidedBy) {
            $joinRequest = LoopJoinRequest::where('id', $joinRequest->id)->lockForUpdate()->firstOrFail();

            if (! $joinRequest->isPending()) {
                throw new \RuntimeException('This join request has already been decided.');
            }

            $existingMember = LoopMember::where('loop_id', $joinRequest->loop_id)
                ->where('user_id', $joinRequest->user_id)
                ->first();

            if ($existingMember) {
                if ($existingMember->status !== 'active') {
                    $existingMember->update(['status' => 'active', 'joined_at' => now()]);
                }
                $member = $existingMember->fresh();
            } else {
                $member = LoopMember::create([
                    'loop_id' => $joinRequest->loop_id,
                    'user_id' => $joinRequest->user_id,
                    'role' => 'member',
                    'status' => 'active',
                    'joined_at' => now(),
                    'organization_id' => $joinRequest->organization_id,
                ]);
            }

            $joinRequest->update([
                'status' => LoopJoinRequest::STATUS_ACCEPTED,
                'decided_by' => $decidedBy->id,
                'decided_at' => now(),
            ]);

            return $member;
        });

        // APRES le commit, jamais dedans. Voir `notifierDecisionDAdhesion()`.
        $this->notifierDecisionDAdhesion(
            $joinRequest->fresh() ?? $joinRequest,
            NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED,
            $decidedBy,
        );

        return $member;
    }

    public function rejectJoinRequest(LoopJoinRequest $joinRequest, User $decidedBy): void
    {
        DB::transaction(function () use ($joinRequest, $decidedBy) {
            $joinRequest = LoopJoinRequest::where('id', $joinRequest->id)->lockForUpdate()->firstOrFail();

            if (! $joinRequest->isPending()) {
                throw new \RuntimeException('This join request has already been decided.');
            }

            $joinRequest->update([
                'status' => LoopJoinRequest::STATUS_REJECTED,
                'decided_by' => $decidedBy->id,
                'decided_at' => now(),
            ]);
        });

        $this->notifierDecisionDAdhesion(
            $joinRequest->fresh() ?? $joinRequest,
            NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED,
            $decidedBy,
        );
    }

    /**
     * TASK-1381 — prevenir le demandeur qu'une decision a ete prise.
     *
     * ## Appelee APRES le commit, et c'est structurel
     *
     * `MemberNotification` applique ses invariants dans `creating` : un
     * destinataire qui a quitte l'Organization entre sa demande et la decision
     * fait lever une `InvalidArgumentException`. A l'interieur du
     * `DB::transaction()` metier, cette exception annulerait l'adhesion
     * elle-meme — l'effet de bord ferait echouer le fait. Et le bouton
     * echouerait a l'identique indefiniment.
     *
     * Le module protege deja la transaction de l'appelant par des points de
     * sauvegarde, donc PostgreSQL ne resterait pas abandonne ; ce qu'il ne peut
     * pas empecher, c'est la propagation. Le producteur doit donc se placer
     * dehors. C'est exactement ce que fait le producteur d'invitations.
     *
     * ## Et elle ne remonte AUCUNE exception
     *
     * Le contrôleur enveloppe l'appel dans `catch (\RuntimeException)` pour
     * afficher « cette demande a deja ete tranchee ».
     * `NotificationEmissionConflict` descend de `RuntimeException` : sans le
     * `catch` ci-dessous, un conflit d'emission s'afficherait a l'animateur
     * comme un message d'ERREUR technique en anglais — alors que l'adhesion
     * vient de reussir. Les deux causes deviendraient indistinguables.
     *
     * Plus generalement : la decision est COMMITEE. Rien de ce qui se passe
     * ensuite n'a le droit de la faire passer pour un echec. L'echec est trace
     * dans les journaux, ou il est diagnosticable, plutot que rendu a un
     * utilisateur qui n'y peut rien.
     *
     * ## L'acteur est verifie, pas suppose
     *
     * `decided_by` peut designer quelqu'un qui a quitte l'Organization depuis —
     * l'acceptation d'invitation ecrit `sender_id`. Les invariants refusent un
     * acteur hors tenant ; on rend donc la notification anonyme plutot que de
     * la faire echouer. Meme forme que pour les invitations.
     */
    private function notifierDecisionDAdhesion(
        LoopJoinRequest $joinRequest,
        string $notificationKey,
        User $decidedBy,
    ): void {
        $destinataire = User::find($joinRequest->user_id);

        if ($destinataire === null) {
            return;
        }

        try {
            app(NotificationEmitter::class)->emit(
                notificationKey: $notificationKey,
                organization: (string) $joinRequest->organization_id,
                recipient: $destinataire,
                eventId: $this->identifiantEvenementDecision($joinRequest, $notificationKey),
                objectType: NotificationCatalogue::objectTypeFor($notificationKey),
                objectId: (string) $joinRequest->id,
                actor: $decidedBy->organization_id === $joinRequest->organization_id ? $decidedBy : null,
            );
        } catch (NotificationEmissionConflict) {
            // La personne a deja ete prevenue de ce fait. Un rejeu n'est pas une
            // anomalie, et surtout pas une raison de signaler une erreur sur une
            // decision qui, elle, a bien eu lieu.
        } catch (\Throwable $echec) {
            // Le destinataire a pu quitter l'Organization, ou la base tousser.
            // La decision est prise et enregistree : elle ne se defait pas parce
            // que l'avis n'a pas pu partir. On le trace, et le cockpit de
            // supervision (TASK-1380) montrera l'absence d'activite.
            Log::warning('TASK-1381 notification de decision d\'adhesion non emise', [
                'join_request_id' => $joinRequest->id,
                'notification_key' => $notificationKey,
                'raison' => $echec->getMessage(),
            ]);
        }
    }

    /**
     * L'identite de l'EVENEMENT, derivee de LA CLE ET de la demande.
     *
     * Les deux ne suffisent pas separement. La demande seule donnerait le meme
     * identifiant a « accepte » et a « refuse » — deux faits differents sur le
     * meme objet — et la contrainte `UNIQUE(event_id, recipient_id)` ferait
     * echouer le second. La cle seule serait partagee par toutes les demandes.
     *
     * Deterministe a dessein : rejouer le meme producteur sur le meme fait ne
     * cree pas une seconde notification, il constate la premiere.
     */
    private function identifiantEvenementDecision(LoopJoinRequest $joinRequest, string $notificationKey): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'bouclepro:notification:'.$notificationKey.':'.$joinRequest->id,
        );
    }

    public function archiveLoop(Loop $loop): Loop
    {
        $loop->update(['status' => 'archived']);

        return $loop;
    }

    public function restoreLoop(Loop $loop): Loop
    {
        $loop->update(['status' => 'active']);

        return $loop;
    }

    private function generateUniqueSlug(string $orgId, string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;

        for ($i = 1; $i <= 20; $i++) {
            $exists = Loop::where('organization_id', $orgId)
                ->where('slug', $slug)
                ->exists();

            if (! $exists) {
                return $slug;
            }

            $slug = $base.'-'.$i;
        }

        return $base.'-'.Str::lower(Str::random(4));
    }
}
