<?php

namespace App\Services;

use App\Events\LoopMessageCreated;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class LoopMessageService
{
    /**
     * @param  list<string>  $mentions  identifiants DEJA re-cadres par l'appelant
     */
    public function sendUserMessage(Loop $loop, User $sender, string $body, ?array $metadata = null, ?string $replyToId = null, ?string $imagePath = null, array $mentions = []): LoopMessage
    {
        $this->assertCanSend($loop, $sender);

        if ($replyToId !== null) {
            $parent = LoopMessage::where('id', $replyToId)
                ->where('loop_id', $loop->id)
                ->exists();

            if (! $parent) {
                $replyToId = null;
            }
        }

        return DB::transaction(function () use ($loop, $sender, $body, $metadata, $replyToId, $imagePath, $mentions) {
            // TASK-1772 — les mentions vivent dans `metadata`, aux cotes de
            // `url_preview` et `requested_mode` (D4 : pas de migration en V1).
            if ($mentions !== []) {
                $metadata = ($metadata ?? []) + ['mentions' => array_values($mentions)];
            }

            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'reply_to_id' => $replyToId,
                'body' => $body,
                'image_path' => $imagePath,
                'type' => 'user',
                'metadata' => $metadata,
                'organization_id' => $loop->organization_id,
            ]);

            if ($mentions !== []) {
                $this->notifierLesMentions($loop, $sender, $message, $mentions);
            }

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * TASK-1747 (CDC §8.1) — prevenir la Boucle qu'une demande d'aide y est
     * posee. PREMIER producteur du §8.
     *
     * ## Pourquoi ici, et pas sur l'evenement de message
     *
     * Le reflexe aurait ete d'ecouter `LoopMessageCreated` et d'y filtrer le
     * type. Ce serait un piege : le jour ou quelqu'un ajoute un type, le
     * listener le verrait passer, et il suffirait d'une condition mal ecrite
     * pour notifier TOUS les messages de ChatLoop — exactement ce que le CDC
     * interdit (§8.1, « eviter le Push pour chaque message »).
     *
     * L'emission vit donc la ou le type est DECIDE : dans les deux methodes
     * qui posent `help_request`, et nulle part ailleurs. Un message ordinaire
     * ne passe jamais par ici, et `TASK1747HelpRequestNotificationTest` le
     * prouve plutot que de le supposer.
     *
     * ## Les deux sites, et pourquoi les deux comptent
     *
     * La demande NATIVE et la PROJECTION d'une Demande canonique sont, du
     * point de vue des membres, le meme fait : un besoin vient d'apparaitre
     * dans leur Boucle. Que la Demande ait ete creee ailleurs ne change rien
     * pour eux — ils ne l'avaient pas vue.
     *
     * ## Destinataires
     *
     * Les membres ACTIFS de la Boucle, sauf l'auteur : se faire prevenir de
     * sa propre demande n'apprend rien. La requete est bornee par
     * l'Organization de la Boucle en plus de son identifiant — une Boucle
     * n'est pas un Tenant, et la borne de tenant se pose a la main.
     *
     * ## Pas de transaction supplementaire
     *
     * L'appel se fait DANS celle de l'appelant. L'emetteur garantit
     * l'idempotence par `event_id`, et les jobs de livraison portent
     * `afterCommit()` : rien ne part avant que le message soit reellement
     * ecrit.
     */
    private function notifierLaDemandeDAide(Loop $loop, User $auteur, LoopMessage $message): void
    {
        $destinataires = User::query()
            ->whereIn(
                'id',
                LoopMember::query()
                    ->where('loop_id', $loop->id)
                    ->where('organization_id', $loop->organization_id)
                    ->where('status', 'active')
                    ->whereKeyNot(null)
                    ->pluck('user_id'),
            )
            ->whereKeyNot($auteur->getKey())
            ->get();

        if ($destinataires->isEmpty()) {
            return;
        }

        $emetteur = app(NotificationEmitter::class);

        foreach ($destinataires as $destinataire) {
            $emetteur->emit(
                notificationKey: NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
                organization: (string) $loop->organization_id,
                recipient: $destinataire,
                // L'identifiant du MESSAGE, pas de la Boucle : deux demandes
                // posees dans la meme Boucle sont deux faits distincts, et
                // l'unicite `(event_id, recipient_id)` doit les separer.
                eventId: (string) $message->getKey(),
                objectType: NotificationCatalogue::OBJECT_LOOP,
                objectId: (string) $loop->getKey(),
                actor: $auteur,
            );
        }
    }

    /**
     * TASK-1772 — prevenir les personnes NOMMEMENT appelees dans un message.
     *
     * ## Pourquoi ici, et pas dans un ecouteur de `LoopMessageCreated`
     *
     * La raison est ecrite dans le docblock de `notifierLaDemandeDAide()` et
     * elle vaut mot pour mot : un ecouteur qui filtre le type finit par
     * notifier TOUS les messages le jour ou quelqu'un ajoute un type.
     * L'emission vit la ou le fait est DECIDE.
     *
     * ## L'identifiant d'evenement est derive du NOM DE LA CLE
     *
     * Surtout pas `$message->getKey()` nu : `LOOP_HELP_REQUEST_CREATED`
     * l'emploie deja tel quel (plus haut dans ce fichier), et la contrainte
     * est `UNIQUE(event_id, recipient_id)`. Deux faits differents sous le meme
     * identifiant pour la meme personne font lever
     * `NotificationEmissionConflict` — qui descend de `RuntimeException`, donc
     * est attrape par le `catch` de l'envoi, donc **annule la transaction et
     * le message n'est pas publie**, avec pour seul indice « Impossible
     * d'envoyer le message. »
     *
     * Le `uuid5` nomme par la cle est l'idiome deja en place
     * (`LoopService::identifiantEvenementDecision()`, `BlogComments`) et il
     * rend le rejeu idempotent : re-emettre le meme fait pour la meme
     * personne ne cree pas de seconde notification. C'est ce qui donne
     * gratuitement la regle d'edition « une mention inchangee ne re-notifie
     * pas ».
     *
     * @param  list<string>  $destinatairesIds  deja re-cadres par l'appelant
     */
    private function notifierLesMentions(Loop $loop, User $auteur, LoopMessage $message, array $destinatairesIds): void
    {
        $destinataires = User::query()
            ->whereIn('id', $destinatairesIds)
            ->whereKeyNot($auteur->getKey())
            ->get();

        if ($destinataires->isEmpty()) {
            return;
        }

        $emetteur = app(NotificationEmitter::class);

        foreach ($destinataires as $destinataire) {
            $emetteur->emit(
                notificationKey: NotificationCatalogue::LOOP_MENTION_CREATED,
                organization: (string) $loop->organization_id,
                recipient: $destinataire,
                eventId: self::identifiantEvenementMention($message),
                // Le MESSAGE, pas la Boucle : la notification mene a LA PHRASE
                // ou l'on a ete nomme (arbitrage Cyril, D5 du CDC).
                objectType: NotificationCatalogue::OBJECT_LOOP_MESSAGE,
                objectId: (string) $message->getKey(),
                actor: $auteur,
            );
        }
    }

    /** L'identifiant d'evenement d'une mention, nomme par sa cle. */
    public static function identifiantEvenementMention(LoopMessage $message): string
    {
        // Meme idiome que `LoopService::identifiantEvenementDecision()` :
        // `Ramsey\Uuid\Uuid`, pas le helper d'Illuminate (qui n'expose pas
        // cette constante).
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'bouclepro:notification:'.NotificationCatalogue::LOOP_MENTION_CREATED.':'.$message->getKey(),
        );
    }

    public function sendHelpRequestMessage(
        Loop $loop,
        User $sender,
        string $body,
        string $title,
        string $need,
        string $context,
        string $expectedHelpType,
        ?array $deadline = null,
        string $urgency = 'normal',
    ): LoopMessage {
        $this->assertCanSend($loop, $sender);

        return DB::transaction(function () use ($loop, $sender, $body, $title, $need, $context, $expectedHelpType, $deadline, $urgency) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'help_request',
                'metadata' => [
                    'title' => $title,
                    'need' => $need,
                    'context' => $context,
                    'expected_help_type' => $expectedHelpType,
                    'deadline' => $deadline,
                    'urgency' => $urgency,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            $this->notifierLaDemandeDAide($loop, $sender, $message);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * Annonce ChatLoop d'une Demande canonique deja creee.
     *
     * Le body n'est qu'un libelle d'annonce impose par `loop_messages`; la
     * carte relit titre, description et statut sur `ServiceRequest`. Les deux
     * marqueurs metadata identifient cette ligne comme projection et servent
     * aussi au listener agent pour rester silencieux.
     */
    public function sendServiceRequestProjection(
        Loop $loop,
        User $sender,
        ServiceRequest $request,
    ): LoopMessage {
        $this->assertCanSend($loop, $sender);

        if ($loop->status !== 'active'
            || $request->organization_id !== $loop->organization_id
            || $request->organization_id !== $sender->organization_id
            || $request->user_id !== $sender->id
            || ! in_array($request->status, ['open', 'in_progress'], true)) {
            throw new \RuntimeException('The service request cannot be projected into this loop.');
        }

        return DB::transaction(function () use ($loop, $sender, $request) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => __('requests.chat_projection_body', ['title' => $request->title]),
                'type' => 'help_request',
                'metadata' => [
                    'projection_type' => 'service_request',
                    'service_request_id' => $request->id,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            $this->notifierLaDemandeDAide($loop, $sender, $message);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * Annoncer dans ChatLoop qu'un Sondage a ete pose ou clos.
     *
     * Suit exactement le motif de sendHelpRequestMessage() : un `type` propre et
     * un `metadata` structure, que la vue reconnait pour rendre autre chose
     * qu'une bulle de conversation. Aucune seconde architecture de messages
     * systeme n'a ete inventee — celle-ci existait, elle sert.
     *
     * Deux evenements seulement, jamais un par vote : une Boucle qui vote a
     * vingt n'a pas besoin de vingt lignes dans sa conversation.
     *
     * @param  'created'|'closed'  $event
     */
    public function sendPollEventMessage(Loop $loop, User $sender, LoopPoll $poll, string $event): LoopMessage
    {
        $this->assertCanSend($loop, $sender);

        $body = $event === 'closed'
            ? __('polls.chat_closed', ['question' => $poll->question])
            : __('polls.chat_created', ['question' => $poll->question]);

        return DB::transaction(function () use ($loop, $sender, $poll, $event, $body) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'poll_event',
                'metadata' => [
                    'event' => $event,
                    'poll_id' => $poll->id,
                    'question' => $poll->question,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * Annoncer dans ChatLoop qu'une rencontre est proposee, deplacee ou annulee.
     *
     * Meme motif que sendPollEventMessage(), et pour la meme raison : ChatLoop
     * reste le centre vivant de la Boucle, donc ce qui s'y passe ailleurs doit y
     * laisser une trace lisible. Trois evenements seulement, et jamais un par
     * reponse — une Boucle qui organise une reunion a dix n'a pas besoin de dix
     * lignes.
     *
     * @param  'created'|'updated'|'cancelled'  $event
     */
    public function sendEventMessage(Loop $loop, User $sender, LoopEvent $event, string $eventKind): LoopMessage
    {
        $this->assertCanSend($loop, $sender);

        $body = match ($eventKind) {
            'cancelled' => __('events.chat_cancelled', ['title' => $event->title]),
            'updated' => __('events.chat_updated', ['title' => $event->title]),
            default => __('events.chat_created', ['title' => $event->title]),
        };

        return DB::transaction(function () use ($loop, $sender, $event, $eventKind, $body) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'loop_event',
                'metadata' => [
                    'event' => $eventKind,
                    'event_id' => $event->id,
                    'title' => $event->title,
                    // Une date absolue en UTC plus le fuseau : le message reste
                    // juste meme relu depuis un autre continent.
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'timezone' => $event->timezone,
                    'format' => $event->format,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    public function updateUserMessage(Loop $loop, LoopMessage $message, User $user, string $body, ?array $metadata = null): LoopMessage
    {
        $this->assertCanSend($loop, $user);
        $this->assertMessageBelongsToLoop($loop, $message);

        if (! $message->isEditableBy($user)) {
            throw new \RuntimeException('Message cannot be edited.');
        }

        $body = trim($body);

        if ($body === '') {
            throw new \RuntimeException('Message body is required.');
        }

        // TASK-1772 — FUSION, et non ecrasement. Arbitrage Cyril du 06/11.
        //
        // `forceFill(['metadata' => $metadata])` remplacait le tableau ENTIER.
        // Corriger une faute de frappe perdait donc `requested_mode` — le
        // badge « IA » / « Dossiers » de la bulle — en silence, et aurait
        // perdu `mentions` de la meme facon. Le defaut etait PREEXISTANT et
        // n'avait jamais ete signale parce qu'il ne fait rien planter.
        //
        // La fusion est faite ici, au seul endroit qui ecrit, plutot que chez
        // chaque appelant : une regle qui vit a un seul endroit ne peut pas
        // diverger. L'appelant garde le dernier mot sur les cles qu'il
        // fournit — `array_merge` le lui donne — et tout ce qu'il ne nomme
        // PAS survit.
        $metadataFusionnee = array_merge($message->metadata ?? [], $metadata ?? []);

        $message->forceFill([
            'body' => $body,
            'metadata' => $metadataFusionnee === [] ? null : $metadataFusionnee,
            'edited_at' => now(),
        ])->save();

        // TASK-1772 — une mention AJOUTEE par l'edition notifie la nouvelle
        // personne ; une mention retiree ne de-notifie pas (c'est deja parti,
        // et un email ne se reprend pas) ; une mention inchangee ne re-notifie
        // pas — l'identifiant d'evenement deterministe le donne gratuitement.
        $mentions = $metadataFusionnee['mentions'] ?? [];

        if (is_array($mentions) && $mentions !== []) {
            $this->notifierLesMentions($loop, $user, $message, array_values($mentions));
        }

        $loop->touch();

        return $message;
    }

    public function deleteMessage(Loop $loop, LoopMessage $message, User $user): void
    {
        $this->assertMessageBelongsToLoop($loop, $message);

        if (! $this->canDeleteMessage($loop, $user)) {
            throw new \RuntimeException('Message cannot be deleted.');
        }

        $message->forceFill([
            'deleted_at' => now(),
            'deleted_by' => $user->id,
            'pinned_at' => null,
            'pinned_by_id' => null,
        ])->save();

        $loop->touch();
    }

    private function assertCanSend(Loop $loop, User $sender): void
    {
        $membership = LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $sender->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw new \RuntimeException('User is not an active member of this loop.');
        }

        $orgId = $sender->organization_id;

        if ($loop->organization_id !== $orgId) {
            throw new \RuntimeException('User does not belong to the same organization as this loop.');
        }
    }

    private function assertMessageBelongsToLoop(Loop $loop, LoopMessage $message): void
    {
        if ($message->loop_id !== $loop->id || $message->organization_id !== $loop->organization_id) {
            throw new \RuntimeException('Message not found.');
        }
    }

    public function canDeleteMessage(Loop $loop, User $user): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($loop->organization_id !== $user->organization_id) {
            return false;
        }

        $role = $this->activeMembershipRole($loop, $user);

        return $role !== null && in_array($role, ['owner', 'moderator'], true);
    }

    private function activeMembershipRole(Loop $loop, User $user): ?string
    {
        return LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->value('role');
    }
}
