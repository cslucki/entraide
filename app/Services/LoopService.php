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
    /**
     * TASK-1679 — un seul parametre confondait deux roles : le responsable
     * metier (membre `owner` de la Boucle) et le createur administratif
     * (`loops.created_by`). Un SuperAdmin provisionne desormais une Boucle
     * AVANT que son responsable n'existe comme compte : `$owner` null, aucune
     * ligne `loop_members` a la naissance. L'attribution ulterieure passe par
     * les surfaces existantes (addMember / addMemberByUserId), dont la garde
     * same-org reste seule autorite — rien n'est relache ici.
     *
     * Le document racine garde un auteur REEL meme sans owner :
     * `blog_posts.user_id` est NOT NULL (invariant T1534, « l'auteur est
     * l'humain qui valide »), un auteur null serait refuse par le schema.
     * Sans owner, l'auteur est donc le createur administratif.
     */
    public function createLoopForOrg(?User $owner, string $organizationId, string $name, ?string $description = null, string $visibility = 'private', ?string $tagline = null, string $accessMode = Loop::ACCESS_REQUEST, ?string $type = null, ?User $creator = null): Loop
    {
        // Les appelants historiques ne passent que l'owner : il reste alors le
        // createur, et `created_by` ne change pas d'une virgule.
        $creator ??= $owner;

        if ($creator === null) {
            throw new \InvalidArgumentException('A Loop without an owner still needs an administrative creator.');
        }

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
            'created_by' => ($owner ?? $creator)->id,
        ]);

        if ($owner !== null) {
            $this->addMember($loop, $owner, 'owner');
        }

        // Was missing here while createLoop() had it: a Loop created from the
        // admin came out with no cards at all and relied on the fallback.
        $registry->applyPreset($loop);

        $this->provisionRootContent($loop, $owner ?? $creator);

        return $loop;
    }

    /**
     * TASK-1679 (recette) — le document racine EST le Manifeste visible, et
     * depuis TASK-1332 le Manifeste a quitte le socle de TOUS les presets :
     * le creer a chaque naissance contredisait ce retrait — une Boucle
     * « Communaute » affichait un Manifeste que son type n'active pas, en
     * doublon de sa description. Decision produit MASTER : pas de Card
     * Manifeste active, pas de Manifeste.
     *
     * Le Dossier racine, lui, est STRUCTUREL (Dossiers, fichiers, series) et
     * nait toujours. Une Card Manifeste activee plus tard retombe sur ses
     * pieds : `LoopManifestoCard::createManifesto()` appelle le meme
     * `ensureRootDocument()`, idempotent.
     *
     * Appele apres `applyPreset()` obligatoirement : c'est le preset qui dit
     * si `core.manifesto` est actif a la naissance.
     */
    private function provisionRootContent(Loop $loop, User $author): void
    {
        $service = app(LoopRootDocumentService::class);

        $manifesteActif = $loop->cards()
            ->where('card_key', 'core.manifesto')
            ->where('enabled', true)
            ->exists();

        if ($manifesteActif) {
            $service->ensureRootDocument($loop, $author);

            return;
        }

        $service->ensureRootDossier($loop);
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

        // Root Dossier toujours (TASK-1082) ; root document seulement si le
        // preset active le Manifeste — voir provisionRootContent().
        $this->provisionRootContent($loop, $user);

        return $loop;
    }

    public function updateLoop(Loop $loop, array $data): Loop
    {
        $loop->update($data);

        return $loop;
    }

    public function addMemberByUserId(Loop $loop, string $userId, string $role = 'member', ?User $actor = null): LoopMember
    {
        // TASK-1720 volet B — la garde SERVEUR, au point de passage oblige.
        //
        // Tous les chemins d'ajout finissent ici : la Card Membres, le
        // controleur, l'administration. La poser plus haut laisserait les
        // autres ouverts — c'est la lecon de T1693 bug L, deja apprise sur
        // cette meme methode.
        //
        // `findOrFail` et non un refus explicite : l'appelant en masse
        // (`addMembersFromOrganization`) attrape deja `ModelNotFoundException`
        // et compte la personne comme « ignoree », ce qui est exactement le
        // comportement voulu — on n'interrompt pas un ajout de dix personnes
        // parce que l'une d'elles n'a pas confirme son adresse.
        $user = User::eligibleForLoop()->findOrFail($userId);

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

            $this->resolvePendingJoinRequests($loop, $userId, $actor);

            return $existing;
        }

        $this->resolvePendingJoinRequests($loop, $userId, $actor);

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

        // TASK-1720 volet B — l'ecran ne propose que ce que le serveur accepte.
        return User::eligibleForLoop()
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
                $this->addMemberByUserId($loop, $userId, 'member', $actor);
                $added++;
            } catch (\RuntimeException|ModelNotFoundException) {
                // Already an active member, cross-organization or deactivated:
                // silently skipped, the caller reports the counts.
                $skipped++;

                continue;
            }

            // TASK-1381 — la resolution et sa notification vivent desormais
            // dans `resolvePendingJoinRequests()`, appele par
            // `addMemberByUserId()` ci-dessus (TASK-1693 bug L).
            //
            // Ce bloc faisait ce travail POUR CE SEUL CHEMIN. L'ajout par
            // l'ADMINISTRATION appelle `addMemberByUserId()` en direct et n'en
            // beneficiait donc pas : c'est le defaut signale. Deplacer la regle
            // d'un cran vers le bas la rend vraie pour tous les appelants, au
            // lieu d'en ajouter une seconde a cote — ce qui aurait solde la
            // demande SANS decideur ni notification, en devancant celle-ci.
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

        $this->resolvePendingJoinRequests($loop, $user->id);

        return LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => $loop->organization_id,
        ]);
    }

    /**
     * TASK-1693 bug L — devenir membre SOLDE toute demande en attente.
     *
     * ## Le defaut mesure
     *
     * Sur la Boucle `ofsh`, DEUX personnes etaient a la fois membres actives et
     * porteuses d'une demande `pending` : l'une proprietaire, l'autre ajoutee
     * par l'administration. Les ecrans affichaient donc « 2 demandes en
     * attente » pour des gens deja dans la Boucle, avec des boutons
     * « Accepter / Refuser » sans objet. Les ecrans disaient vrai sur ce qu'ils
     * lisaient ; c'est la DONNEE qui etait incoherente.
     *
     * ## Pourquoi ici, et une seule fois
     *
     * `acceptJoinRequest()` soldait deja la demande — c'est le bon modele, mais
     * il ne couvrait qu'un chemin sur quatre. `addMemberByUserId()`,
     * `addMember()` et `joinOpenLoop()` creaient un membre actif sans jamais
     * regarder les demandes. Trois chemins, trois oublis possibles : la regle
     * vit donc a UN endroit, appele par les trois.
     *
     * ## ACCEPTED, et non CANCELLED
     *
     * `cancelled` signifie « le demandeur s'est retracte ». Ici la demande a
     * recu sa reponse — l'entree dans la Boucle EST l'acceptation, quel que
     * soit le chemin emprunte. `decided_by` reste nul quand personne n'a
     * statue explicitement (adhesion libre, ou ajout sans decideur connu) :
     * mieux vaut un decideur absent qu'un decideur invente.
     *
     * `joinOpenLoop()` est inclus bien qu'une Boucle ouverte n'accepte pas de
     * demande : une Boucle peut passer de `request` a `open` en laissant des
     * demandes derriere elle, et ces demandes-la doivent se solder aussi.
     */
    private function resolvePendingJoinRequests(Loop $loop, string $userId, ?User $decidedBy = null): int
    {
        // Les demandes sont RELUES puis decidees UNE PAR UNE, jamais mises a
        // jour en masse (contrat TASK-1381) : un `update()` de query builder
        // n'emet aucun evenement et ne rend qu'un COMPTE — il ne dirait pas
        // QUELLE demande vient d'etre acceptee, donc rien ne pourrait etre
        // notifie. Pour le demandeur, etre ajoute est exactement le meme fait
        // que depuis l'ecran de decision, et merite la meme notification.
        $demandes = LoopJoinRequest::query()
            ->where('loop_id', $loop->id)
            ->where('user_id', $userId)
            ->where('status', LoopJoinRequest::STATUS_PENDING)
            ->get();

        foreach ($demandes as $demande) {
            $demande->update([
                'status' => LoopJoinRequest::STATUS_ACCEPTED,
                'decided_by' => $decidedBy?->id,
                'decided_at' => now(),
            ]);

            // Sans decideur identifie — adhesion libre, pack de donnees — il n'y
            // a personne a nommer dans la notification : on solde la demande
            // sans en fabriquer une.
            if ($decidedBy !== null) {
                $this->notifierDecisionDAdhesion(
                    $demande,
                    NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED,
                    $decidedBy,
                );
            }
        }

        return $demandes->count();
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
                    ->eligibleForLoop()
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

            $this->resolvePendingJoinRequests($loop, $user->id, $user);

            return $existing->fresh();
        }

        $this->resolvePendingJoinRequests($loop, $user->id, $user);

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
