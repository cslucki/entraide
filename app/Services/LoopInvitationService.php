<?php

namespace App\Services;

use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmissionConflict;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Targeted e-mail invitations to a single Loop.
 *
 * Every state transition lives here rather than in the controller, because the
 * accept path is reachable from three entry points (an authenticated POST, a
 * login POST and a registration POST) and they must not each grow their own
 * copy of the rules.
 */
class LoopInvitationService
{
    /** Outcome of an accept attempt: why it failed, or that it succeeded. */
    public const RESULT_ACCEPTED = 'accepted';

    public const RESULT_ALREADY_ACCEPTED_BY_SAME_USER = 'already_accepted_by_same_user';

    public const RESULT_EMAIL_MISMATCH = 'email_mismatch';

    public const RESULT_EXPIRED = 'expired';

    public const RESULT_REVOKED = 'revoked';

    public const RESULT_TAKEN_BY_ANOTHER_USER = 'taken_by_another_user';

    public const RESULT_LOOP_UNAVAILABLE = 'loop_unavailable';

    public const RESULT_USER_DEACTIVATED = 'user_deactivated';

    public const RESULT_NOT_FOUND = 'not_found';

    public function __construct(private LoopService $loopService) {}

    /**
     * Create — or reuse — the single pending invitation for this recipient.
     *
     * Two people inviting the same address at the same moment must not produce
     * two live tokens, so the uniqueness check and the insert share one
     * transaction and lock the existing rows for this loop + address.
     */
    public function invite(Loop $loop, User $sender, string $email, ?string $name = null, ?string $message = null): LoopInvitation
    {
        $email = LoopInvitation::normalizeEmail($email);

        $invitation = DB::transaction(function () use ($loop, $sender, $email, $name, $message) {
            $existing = LoopInvitation::where('loop_id', $loop->id)
                ->where('recipient_email', $email)
                ->lockForUpdate()
                ->get();

            $pending = $existing->first(fn (LoopInvitation $i) => $i->isPending());

            if ($pending) {
                // Refresh the human-facing fields but keep the token already in
                // the recipient's mailbox valid.
                $pending->update(array_filter([
                    'recipient_name' => $name,
                    'message' => $message,
                ], fn ($v) => $v !== null));

                return $pending->fresh();
            }

            // A stale row flagged pending but past its window would otherwise
            // block a fresh invitation for ever.
            $existing->filter(fn (LoopInvitation $i) => $i->status === LoopInvitation::STATUS_PENDING && $i->isExpired())
                ->each(fn (LoopInvitation $i) => $i->update(['status' => LoopInvitation::STATUS_EXPIRED]));

            return LoopInvitation::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'recipient_email' => $email,
                'recipient_name' => $name,
                'message' => $message,
                'invitation_type' => $this->resolveType($loop, $email),
                'status' => LoopInvitation::STATUS_PENDING,
            ]);
        });

        // TASK-1374 — la notification IN_APP, une fois la ligne acquise.
        //
        // Ici et pas chez les appelants : `invite()` est le point d'entree
        // unique des deux chemins de production (le controleur et la Card des
        // membres), et aucun observer n'existe sur ce modele. L'email, lui,
        // reste entierement chez les appelants — il n'est ni double ni touche.
        $this->notifyRecipient($invitation, $sender);

        return $invitation;
    }

    /**
     * Prevenir le destinataire DANS l'application, quand c'en est un.
     *
     * Une invitation s'adresse a une ADRESSE EMAIL. Connaitre une adresse n'a
     * jamais donne le droit de franchir une frontiere d'Organization : la
     * notification n'existe que si cette adresse appartient a un membre **de
     * cette meme Organization**.
     *
     * Inviter quelqu'un qui n'a pas de compte, ou qui appartient a un autre
     * tenant, est un cas metier parfaitement NORMAL — l'email part, et rien
     * d'autre ne se passe. C'est pourquoi ce chemin ne leve jamais : c'est le
     * resolver qui filtre, et l'emetteur qui reste strict.
     */
    private function notifyRecipient(LoopInvitation $invitation, User $sender): void
    {
        $recipient = $this->resolveNotifiableRecipient($invitation);

        if ($recipient === null) {
            return;
        }

        $eventId = $this->notificationEventId($invitation);

        try {
            app(NotificationEmitter::class)->emit(
                notificationKey: NotificationCatalogue::LOOP_INVITATION,
                organization: (string) $invitation->organization_id,
                recipient: $recipient,
                eventId: $eventId,
                objectType: NotificationCatalogue::objectTypeFor(NotificationCatalogue::LOOP_INVITATION),
                objectId: (string) $invitation->id,
                actor: $sender->organization_id === $invitation->organization_id ? $sender : null,
            );
        } catch (NotificationEmissionConflict $conflit) {
            // Deux animateurs peuvent relancer la meme personne. `invite()`
            // reutilise alors l'invitation en attente — donc le meme evenement —
            // mais l'expediteur a change, et l'emetteur signale a juste titre que
            // la ligne existante differe.
            //
            // C'est un flux metier ordinaire, pas une anomalie : la personne a
            // deja ete prevenue, et le second appel ne doit surtout pas empecher
            // l'appelant d'envoyer son email.
            //
            // La tolerance est DELIBEREMENT ici, et non dans l'emetteur. Decider
            // quels conflits sont acceptables appartient au producteur qui
            // connait son metier ; l'emetteur, lui, reste strict pour tout le
            // monde. L'affaiblir globalement ferait payer a chaque futur
            // producteur une exception qui n'appartient qu'a celui-ci.
            if (! $this->alreadySignalled($invitation, $recipient, $eventId)) {
                throw $conflit;
            }
        }
    }

    /**
     * La personne a-t-elle DEJA ete prevenue du meme fait ?
     *
     * « Le meme fait » se verifie champ a champ, et **la seule difference
     * toleree est l'acteur**. Tenant, destinataire, evenement, cle, type et
     * identifiant d'objet, cle de regroupement : tout le reste doit coincider.
     *
     * Sans cette verification, le `catch` ci-dessus avalerait n'importe quel
     * conflit — y compris celui qui signale une vraie confusion d'`event_id`,
     * exactement ce que l'emetteur existe pour rendre bruyant.
     */
    private function alreadySignalled(LoopInvitation $invitation, User $recipient, string $eventId): bool
    {
        $existante = MemberNotification::query()
            ->forRecipient((string) $invitation->organization_id, (string) $recipient->id)
            ->where('event_id', $eventId)
            ->first();

        if ($existante === null) {
            return false;
        }

        return $existante->notification_key === NotificationCatalogue::LOOP_INVITATION
            && $existante->object_type === NotificationCatalogue::objectTypeFor(NotificationCatalogue::LOOP_INVITATION)
            && $existante->object_id === (string) $invitation->id
            && $existante->collapse_key === null;
    }

    /**
     * L'adresse invitee correspond-elle a un membre de CETTE Organization ?
     *
     * Meme clause que `resolveType()` juste en dessous — c'est deliberе : les
     * deux repondent a la meme question, et deux formulations qui divergeraient
     * finiraient par ne plus dire la meme chose. Celle-ci rend le membre plutot
     * qu'un booleen.
     */
    private function resolveNotifiableRecipient(LoopInvitation $invitation): ?User
    {
        return User::assignable()
            ->where('organization_id', $invitation->organization_id)
            ->whereRaw('LOWER(email) = ?', [LoopInvitation::normalizeEmail((string) $invitation->recipient_email)])
            ->first();
    }

    /**
     * L'identite de l'EVENEMENT de notification, derivee de l'invitation.
     *
     * Deterministe a dessein. Le drapeau `wasRecentlyCreated` aurait ete plus
     * direct, mais la branche de reutilisation rend `$pending->fresh()` — et
     * `fresh()` perd ce drapeau. Il serait donc faux exactement la ou on en
     * aurait besoin.
     *
     * Un UUIDv5 n'a pas ce defaut : la meme invitation rend toujours le meme
     * `event_id`, donc un rafraichissement se dedupe par la contrainte
     * `UNIQUE(event_id, recipient_id)`. Une invitation NOUVELLE porte un autre
     * identifiant, donc un autre evenement.
     *
     * Et il reste **distinct de `object_id`** : `event_id` designe le fait
     * « on a prevenu », pas l'objet dont on parle.
     */
    private function notificationEventId(LoopInvitation $invitation): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'bouclepro:notification:'.NotificationCatalogue::LOOP_INVITATION.':'.$invitation->id,
        );
    }

    /**
     * TASK-1378 — le pipeline Notifications prend-il en charge CET email ?
     *
     * ## Une seule autorite decide qui envoie
     *
     * Sans elle, deux chemins pourraient envoyer le meme message : le nouveau
     * pipeline pour les membres, et le mailer legacy pour tout le monde. Cette
     * methode est la reponse unique, et `LoopInvitationMailer` s'y remet.
     *
     * ## Deux conditions, et les DEUX comptent
     *
     * 1. Le catalogue autorise EMAIL sur cette cle. C'est ce qui rend le cutover
     *    REVERSIBLE : retirer `email` du catalogue rend aussitot la main au
     *    legacy, sans toucher une ligne de code ici.
     * 2. L'adresse invitee appartient a un membre de CETTE Organization. Un
     *    inconnu, ou quelqu'un d'un autre tenant, n'a pas de notification —
     *    donc pas de livraison EMAIL, donc le legacy reste seul a pouvoir le
     *    joindre.
     *
     * ## Recalcule EN VIF, jamais lu dans `invitation_type`
     *
     * La colonne est figee a la CREATION, et une invitation en attente peut etre
     * reutilisee des jours plus tard. Si la personne a rejoint l'Organization
     * entre-temps, la colonne dirait encore `external` alors que la notification
     * serait bien creee — et les DEUX chemins enverraient. Si elle en est
     * partie, la colonne dirait `existing_member` alors qu'aucune notification
     * n'existe — et PERSONNE n'enverrait.
     *
     * La clause est donc la MEME que celle qui decide de creer la notification,
     * evaluee au meme instant. C'est ce qui garantit « exactement un email » par
     * construction plutot que par coincidence.
     */
    public function emailHandledByNotifications(LoopInvitation $invitation): bool
    {
        if (! NotificationCatalogue::allowsChannel(
            NotificationCatalogue::LOOP_INVITATION,
            NotificationCatalogue::CHANNEL_EMAIL
        )) {
            return false;
        }

        return $this->resolveNotifiableRecipient($invitation) !== null;
    }

    /**
     * `existing_member` when the address already belongs to an assignable user
     * of this Loop's Organization — the mail then links straight to the landing
     * page instead of offering to create an account.
     */
    public function resolveType(Loop $loop, string $email): string
    {
        $exists = User::assignable()
            ->where('organization_id', $loop->organization_id)
            ->whereRaw('LOWER(email) = ?', [LoopInvitation::normalizeEmail($email)])
            ->exists();

        return $exists ? LoopInvitation::TYPE_EXISTING_MEMBER : LoopInvitation::TYPE_EXTERNAL;
    }

    public function revoke(LoopInvitation $invitation): void
    {
        if ($invitation->status !== LoopInvitation::STATUS_PENDING) {
            // Never retroactively revoke an accepted invitation, and treat an
            // already expired/revoked one as a no-op rather than an error.
            throw new \RuntimeException('Only a pending invitation can be revoked.');
        }

        $invitation->update(['status' => LoopInvitation::STATUS_REVOKED]);
    }

    /**
     * Accept an invitation on behalf of an authenticated user.
     *
     * Returns one of the RESULT_* constants. Never throws for an expected
     * refusal — callers render a message, they do not catch exceptions.
     *
     * @return array{result: string, invitation: ?LoopInvitation}
     */
    public function accept(string $token, User $user): array
    {
        return DB::transaction(function () use ($token, $user) {
            $invitation = LoopInvitation::where('token', $token)->lockForUpdate()->first();

            if (! $invitation) {
                return ['result' => self::RESULT_NOT_FOUND, 'invitation' => null];
            }

            if ($user->isDeactivated()) {
                return ['result' => self::RESULT_USER_DEACTIVATED, 'invitation' => $invitation];
            }

            // Strict identity check, re-evaluated here and not inherited from
            // whatever happened before login: a token must never be usable by
            // an address other than the one it was issued to.
            if (! $invitation->matchesEmail($user->email)) {
                return ['result' => self::RESULT_EMAIL_MISMATCH, 'invitation' => $invitation];
            }

            if ($invitation->isRevoked()) {
                return ['result' => self::RESULT_REVOKED, 'invitation' => $invitation];
            }

            if ($invitation->isAccepted()) {
                // Same person coming back on their own link: send them in.
                return $invitation->accepted_by_user_id === $user->id
                    ? ['result' => self::RESULT_ALREADY_ACCEPTED_BY_SAME_USER, 'invitation' => $invitation]
                    : ['result' => self::RESULT_TAKEN_BY_ANOTHER_USER, 'invitation' => $invitation];
            }

            if ($invitation->isExpired()) {
                $invitation->update(['status' => LoopInvitation::STATUS_EXPIRED]);

                return ['result' => self::RESULT_EXPIRED, 'invitation' => $invitation];
            }

            $loop = $invitation->loop;

            if (! $this->loopIsJoinable($loop, $invitation, $user)) {
                return ['result' => self::RESULT_LOOP_UNAVAILABLE, 'invitation' => $invitation];
            }

            $alreadyMember = LoopMember::where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if (! $alreadyMember) {
                try {
                    $this->loopService->addMemberByUserId($loop, $user->id);
                } catch (\RuntimeException|ModelNotFoundException) {
                    // addMemberByUserId owns the cross-organization and
                    // deactivation invariants; a refusal there means the
                    // invitation cannot be honoured.
                    return ['result' => self::RESULT_LOOP_UNAVAILABLE, 'invitation' => $invitation];
                }
            }

            // Marked accepted in both branches: an invitation sent to someone
            // who had joined in the meantime must not stay pending for ever.
            $invitation->update([
                'status' => LoopInvitation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            LoopJoinRequest::where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->where('status', LoopJoinRequest::STATUS_PENDING)
                ->update([
                    'status' => LoopJoinRequest::STATUS_ACCEPTED,
                    'decided_by' => $invitation->sender_id ?? $user->id,
                    'decided_at' => now(),
                ]);

            return ['result' => self::RESULT_ACCEPTED, 'invitation' => $invitation->fresh()];
        });
    }

    /**
     * Consume a token parked in the session during a login or registration POST.
     *
     * Deliberately called from the POST handlers, never from a GET: accepting an
     * invitation mutates state and must not ride on a navigation.
     *
     * @return array{result: string, invitation: ?LoopInvitation}|null null when
     *                                                                 nothing was pending
     */
    public function resumeFromSession(User $user): ?array
    {
        $token = session()->pull(LoopInvitation::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return $this->accept($token, $user);
    }

    /** The Loop itself must still be joinable, whatever the invitation says. */
    private function loopIsJoinable(?Loop $loop, LoopInvitation $invitation, User $user): bool
    {
        if (! $loop || ! $loop->isActive()) {
            return false;
        }

        if ($loop->organization_id !== $invitation->organization_id) {
            return false;
        }

        // Tenant strictness: the invitee must belong to the Loop's Organization.
        if ($user->organization_id !== $loop->organization_id) {
            return false;
        }

        return (bool) $loop->organization?->loops_enabled;
    }
}
