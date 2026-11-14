<?php

namespace App\Services;

use App\Events\LoopMessageCreated;
use App\Models\BlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use App\Support\Notifications\NotificationPreferenceResolver;
use Illuminate\Support\Collection;
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
                // TASK-1802 (demande Cyril, 10/11) — `currentlyInLoopOrganization()` :
                // meme defaut que celui corrige sur `notifierNouvelEvenement()`,
                // mesure en recette sur ce MEME producteur. Une adhesion ACTIVE
                // peut survivre au depart de son titulaire vers une AUTRE
                // Organization ; sans ce filtre, `emit()` refuse ce
                // destinataire et son exception, non rattrapee ICI, annulait
                // la transaction ENTIERE : plus personne n'etait prevenu, pas
                // meme le message ChatLoop, pour UNE seule ligne perimee.
                LoopMember::query()
                    ->where('loop_id', $loop->id)
                    ->where('organization_id', $loop->organization_id)
                    ->where('status', 'active')
                    ->whereKeyNot(null)
                    ->currentlyInLoopOrganization()
                    ->pluck('user_id'),
            )
            ->whereKeyNot($auteur->getKey())
            ->get();

        if ($destinataires->isEmpty()) {
            return;
        }

        // TASK-1775 (CDC B1/C2) — une Boucle desactivee COLLECTIVEMENT par un
        // membre ne doit produire AUCUNE notification pour lui, in-app
        // comprise : la garde se pose ICI, avant l'emission, pas apres. Une
        // notification qu'on ne cree jamais ne peut ni alimenter le badge des
        // non-lues, ni planifier un canal asynchrone qu'on devra annuler plus
        // tard — exactement ce que le CDC demande d'eviter.
        //
        // `allows()` retombe sur le reglage global historique, puis sur le
        // defaut du catalogue, quand la personne n'a pas (encore) regle CETTE
        // Boucle individuellement : aucun choix existant n'est perdu.
        $preferences = app(NotificationPreferenceResolver::class);

        // TASK-1778 — les TROIS canaux resolus EN MASSE, une seule fois.
        //
        // Avant : `allows()` etait appele une fois par destinataire pour
        // l'in-app (2 requetes chacun), puis le planificateur decouvrait les
        // deux canaux asynchrones destinataire par destinataire, puis chaque
        // job les relisait encore. Le banc T1778 a compte **8 requetes de
        // preference par destinataire**, soit 800 pour une Boucle de 100
        // membres. `allowsMany()` les rend en 3 requetes, quel que soit N.
        //
        // Les verdicts email/push sont transmis a `emit()` pour que le
        // planificateur ne fabrique AUCUNE ligne de livraison ni aucun job
        // pour un canal que la personne a coupe. Ils ne dispensent pas les
        // livreurs de relire la preference au moment de livrer : elle peut
        // changer entre-temps, et c'est leur garde qui le voit.
        $verdicts = $preferences->allowsMany(
            $destinataires,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            [
                NotificationCatalogue::CHANNEL_IN_APP,
                NotificationCatalogue::CHANNEL_EMAIL,
                NotificationCatalogue::CHANNEL_WEB_PUSH,
            ],
            (string) $loop->id,
        );

        $destinataires = $destinataires->filter(
            fn (User $destinataire): bool => (bool) ($verdicts[(string) $destinataire->id][NotificationCatalogue::CHANNEL_IN_APP] ?? false)
        );

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
                canauxAutorises: $verdicts[(string) $destinataire->id] ?? null,
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

        // TASK-1797 — les verdicts des TROIS canaux, resolus EN MASSE, une
        // seule fois. Jusqu'ici cette methode n'etait appelee que pour dix
        // destinataires au plus (`LoopChat::MENTIONS_MAX`) et laissait le
        // planificateur relire la preference de chacun a l'unite — un cout
        // negligeable a cette echelle. `@tous` porte jusqu'a 49 destinataires
        // d'un seul message : meme optimisation que `notifierLaDemandeDAide()`
        // juste au-dessus, pour la meme raison (T1778 : 8 requetes par
        // destinataire -> 3 requetes au total, quel que soit N).
        $verdicts = app(NotificationPreferenceResolver::class)->allowsMany(
            $destinataires,
            NotificationCatalogue::LOOP_MENTION_CREATED,
            [
                NotificationCatalogue::CHANNEL_IN_APP,
                NotificationCatalogue::CHANNEL_EMAIL,
                NotificationCatalogue::CHANNEL_WEB_PUSH,
            ],
        );

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
                canauxAutorises: $verdicts[(string) $destinataire->id] ?? null,
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

        // TASK-1802 (demande Cyril) — « sur le message qui est poste dans la
        // boucle, il faut ajouter date et heure. Et URL si en ligne. » La date
        // se lit dans le fuseau DE L'EVENEMENT (`startsAtLocal()`), pas celui
        // du lecteur : une rencontre a Chicago reste a Chicago, meme relue
        // depuis Paris — meme regle que `LoopEventPresenter`.
        //
        // TASK-1802 (suite, demande Cyril) — « on ne voit pas la date de fin
        // de l'evenement (ou la duree si c'est moins d'un jour d'ecart). Si
        // c'est un evenement qui contient une URL [...] il faut que l'URL
        // soit cliquable. » Le lien n'est donc PLUS concatene dans `$body` en
        // texte brut (jamais cliquable une fois echappe par `{{ }}`) : il vit
        // desormais dans `metadata`, et c'est la vue qui rend le `<a href>`
        // (voir `loop-chat.blade.php`). `$body` ne porte que de la prose
        // traduisible.
        $date = $event->startsAtLocal()->isoFormat('D MMM YYYY, HH:mm');

        $body = match ($eventKind) {
            'cancelled' => __('events.chat_cancelled', ['title' => $event->title]),
            'updated' => __('events.chat_updated', ['title' => $event->title]),
            default => $this->bodyPourNouvelEvenement($event, $date),
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
                    // TASK-1802 (suite) — necessaire a la vue pour rendre la
                    // meme comparaison « meme jour ou non » que ce fichier,
                    // sans re-derive dans le template.
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'timezone' => $event->timezone,
                    'format' => $event->format,
                    // TASK-1802 — dupliques depuis l'Evenement pour que le
                    // courriel de notification (qui relit CE message, jamais
                    // l'Evenement directement — voir `notifierNouvelEvenement()`)
                    // puisse composer son corps sans seconde lecture, et pour
                    // que la vue ChatLoop rende le lien de connexion en
                    // `<a href>` cliquable, jamais en texte brut echappe.
                    'description' => $event->description,
                    'location' => $event->location,
                    'meeting_url' => $event->meeting_url,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            if ($eventKind === 'created') {
                $this->notifierNouvelEvenement($loop, $sender, $message);
            }

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * TASK-1802 (demande Cyril) — le corps du message de creation, avec la
     * fin : « la duree si c'est moins d'un jour d'ecart », sinon la date de
     * fin explicite.
     *
     * Trois cas, jamais devines : sans fin du tout, le message ne change pas
     * (`chat_created`, forme historique) ; une fin le MEME jour calendaire
     * que le debut se dit en DUREE (« 1 h 30 »), plus parlant qu'une
     * deuxieme heure a lire ; une fin un AUTRE jour se dit en DATE complete,
     * parce qu'une duree de type « 14 h » ne dirait pas OU elle s'arrete.
     */
    private function bodyPourNouvelEvenement(LoopEvent $event, string $date): string
    {
        if ($event->ends_at === null) {
            return __('events.chat_created', ['title' => $event->title, 'date' => $date]);
        }

        if ($event->startsAtLocal()->isSameDay($event->endsAtLocal())) {
            $minutes = (int) $event->starts_at->diffInMinutes($event->ends_at);

            return __('events.chat_created_with_duration', [
                'title' => $event->title,
                'date' => $date,
                'duree' => $this->formatDuration($minutes),
            ]);
        }

        return __('events.chat_created_with_end', [
            'title' => $event->title,
            'date' => $date,
            'date_fin' => $event->endsAtLocal()->isoFormat('D MMM YYYY, HH:mm'),
        ]);
    }

    /** « 1 h 30 », « 2 h » ou « 45 min » — jamais les deux formes a la fois pour un compte rond. */
    private function formatDuration(int $minutes): string
    {
        $heures = intdiv($minutes, 60);
        $reste = $minutes % 60;

        if ($heures > 0 && $reste > 0) {
            return __('events.duration_format_hours_minutes', ['h' => $heures, 'm' => $reste]);
        }

        if ($heures > 0) {
            return __('events.duration_format_hours', ['h' => $heures]);
        }

        return __('events.duration_format_minutes', ['m' => $reste]);
    }

    /**
     * TASK-1802 (demande Cyril) — prevenir la Boucle qu'un Evenement y est
     * propose.
     *
     * Meme motif que `notifierLaDemandeDAide()` ci-dessus, pour la meme raison
     * (voir son docblock) : un fait COLLECTIF, emis uniquement a la CREATION
     * (jamais sur une modification ou une annulation — Cyril n'a demande que
     * « nouvel evenement propose »), aux membres ACTIFS de la Boucle sauf son
     * auteur.
     *
     * L'objet notifie est le MESSAGE d'annonce, pas la Boucle : meme doctrine
     * que la mention (D5 du CDC) — la notification mene DIRECTEMENT a
     * l'annonce dans ChatLoop, et son courriel y relit tout ce qu'il faut
     * (titre, date, description, lieu/URL) sans seconde lecture de
     * l'Evenement. La sourdine PAR BOUCLE reste verifiee a l'emission via le
     * 4e argument d'`allowsMany()` — `loopScopeFor()` a ete etendue a cette
     * cle pour que `LoopNotificationPreference` s'y applique, exactement comme
     * pour une demande d'aide.
     */
    private function notifierNouvelEvenement(Loop $loop, User $auteur, LoopMessage $message): void
    {
        $destinataires = User::query()
            ->whereIn(
                'id',
                // `currentlyInLoopOrganization()` (demande Cyril, 10/11) :
                // une adhesion ACTIVE peut survivre au depart de son
                // titulaire vers une AUTRE Organization (ligne
                // `loop_members` non nettoyee — mesure en recette, jumeau
                // PROD). Sans ce filtre, `emit()` refuse ce destinataire
                // (fail-closed sur la frontiere de tenant) et son exception,
                // non rattrapee ICI, annulait la transaction ENTIERE de
                // `sendEventMessage()` : plus personne n'etait prevenu, pas
                // meme le message ChatLoop, pour UNE seule ligne perimee.
                LoopMember::query()
                    ->where('loop_id', $loop->id)
                    ->where('organization_id', $loop->organization_id)
                    ->where('status', 'active')
                    ->currentlyInLoopOrganization()
                    ->pluck('user_id'),
            )
            ->whereKeyNot($auteur->getKey())
            ->get();

        if ($destinataires->isEmpty()) {
            return;
        }

        $preferences = app(NotificationPreferenceResolver::class);

        $verdicts = $preferences->allowsMany(
            $destinataires,
            NotificationCatalogue::LOOP_EVENT_CREATED,
            [
                NotificationCatalogue::CHANNEL_IN_APP,
                NotificationCatalogue::CHANNEL_EMAIL,
                NotificationCatalogue::CHANNEL_WEB_PUSH,
            ],
            (string) $loop->id,
        );

        $destinataires = $destinataires->filter(
            fn (User $destinataire): bool => (bool) ($verdicts[(string) $destinataire->id][NotificationCatalogue::CHANNEL_IN_APP] ?? false)
        );

        if ($destinataires->isEmpty()) {
            return;
        }

        $emetteur = app(NotificationEmitter::class);

        foreach ($destinataires as $destinataire) {
            $emetteur->emit(
                notificationKey: NotificationCatalogue::LOOP_EVENT_CREATED,
                organization: (string) $loop->organization_id,
                recipient: $destinataire,
                eventId: (string) $message->getKey(),
                objectType: NotificationCatalogue::OBJECT_LOOP,
                objectId: (string) $loop->getKey(),
                actor: $auteur,
                canauxAutorises: $verdicts[(string) $destinataire->id] ?? null,
            );
        }
    }

    /**
     * TASK-1772 (demande Cyril, 06/11) — ANNONCER UN DEPOT DE FICHIER.
     *
     * « Quand on upload un fichier dans Dossiers, il manque un message dans la
     * Boucle disant X vient de deposer le fichier Y. »
     *
     * Meme motif que `sendEventMessage()` : ChatLoop est le centre vivant de la
     * Boucle, donc ce qui se passe dans ses Cards doit y laisser une trace
     * lisible. UN message par depot, jamais un par fichier — deposer dix pieces
     * ne doit pas produire dix lignes.
     *
     * La garde `assertCanSend()` n'est PAS appelee ici, et c'est delibere : le
     * droit en jeu est celui de DEPOSER dans le Dossier, deja tranche par
     * `DossierPolicy` avant d'arriver ici. Un administrateur d'Organization qui
     * depose sans etre membre actif de la Boucle doit quand meme y laisser la
     * trace — sinon le fichier apparait dans la Card sans que personne ne
     * sache d'ou il vient. Ce n'est pas une prise de parole : le message est
     * de type `dossier_file`, rendu comme un reperage, pas comme une bulle.
     *
     * TASK-1826 — etendue pour porter les IDENTIFIANTS des fichiers, pas
     * seulement leurs noms : la carte premium resout desormais les fichiers
     * EN DIRECT (type, taille, Dossier, lien d'ouverture reel), au lieu de
     * rendre un snapshot de noms fige. Les messages ANTERIEURS a cette TASK
     * n'ont que `files` (noms) dans leur metadata — `file_ids` y est absent,
     * et la vue degrade vers l'ancien rendu (voir `loop-chat.blade.php`).
     * Aucune migration necessaire : `metadata` est un JSON libre, y ajouter
     * une cle ne casse aucune ligne existante.
     *
     * @param  Collection<int, DossierFile>  $files  les fichiers deposes, dans l'ordre
     */
    public function sendDossierFileMessage(Loop $loop, User $sender, Collection $files): ?LoopMessage
    {
        $files = $files->values();

        if ($files->isEmpty()) {
            return null;
        }

        $noms = $files->map(fn (DossierFile $f) => $f->nomLisible())->all();

        $body = count($noms) === 1
            ? __('loops.chat_dossier_file_deposited', ['name' => $sender->publicDisplayName(), 'file' => $noms[0]])
            : __('loops.chat_dossier_files_deposited', ['name' => $sender->publicDisplayName(), 'count' => count($noms)]);

        return DB::transaction(function () use ($loop, $sender, $files, $noms, $body) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'dossier_file',
                'metadata' => [
                    'event' => 'uploaded',
                    // Les noms sont bornes : une Card peut recevoir cinquante
                    // pieces d'un coup, et `metadata` n'est pas un journal.
                    'files' => array_slice($noms, 0, 10),
                    'file_ids' => $files->take(10)->pluck('id')->all(),
                    'count' => $files->count(),
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * TASK-1826 — Annoncer dans ChatLoop qu'un Article a ete publie ou
     * partage dans cette Boucle.
     *
     * Meme motif que les projections precedentes (sendPollEventMessage,
     * sendEventMessage, sendDossierFileMessage) : un `type` propre, un
     * `metadata` MINIMAL (seul l'identifiant canonique), et c'est la VUE qui
     * relit l'Article EN DIRECT — jamais un snapshot fige, pour ne jamais
     * afficher un titre ou un resume perime ou devenu inaccessible.
     *
     * IDEMPOTENT, et c'est l'essentiel de cette methode : un Article peut
     * etre publie puis partage dans une Boucle, ou partage (en brouillon)
     * puis publie ensuite — deux chemins d'appel distincts
     * (`BlogPostObserver`, `BlogPostLoopController`, `DossierArticleController`)
     * peuvent chacun y mener. Un seul message doit en sortir : on verifie
     * qu'aucun message de ce type ne porte deja cet `blog_post_id` pour CETTE
     * Boucle avant d'en creer un second.
     *
     * Pas de `assertCanSend()` : le droit en jeu est celui de PUBLIER ou de
     * PARTAGER l'Article, deja tranche par `BlogPostPolicy`/`DossierPolicy`
     * avant d'arriver ici — meme raisonnement que `sendDossierFileMessage()`.
     */
    public function sendArticlePublishedMessage(Loop $loop, User $sender, BlogPost $post): ?LoopMessage
    {
        $dejaAnnonce = LoopMessage::where('loop_id', $loop->id)
            ->where('type', 'blog_post')
            ->where('metadata->blog_post_id', $post->id)
            ->exists();

        if ($dejaAnnonce) {
            return null;
        }

        $body = __('loops.chat_article_published', ['name' => $sender->publicDisplayName(), 'title' => $post->title]);

        return DB::transaction(function () use ($loop, $sender, $post, $body) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'blog_post',
                'metadata' => [
                    'event' => 'published',
                    'blog_post_id' => $post->id,
                ],
                'organization_id' => $loop->organization_id,
            ]);

            event(new LoopMessageCreated($message));

            $loop->touch();

            return $message;
        });
    }

    /**
     * TASK-1826 — Annoncer dans ChatLoop qu'une action de Roadmap a ete
     * creee. UNE SEULE fois, a la creation : jamais a l'edition, jamais a un
     * changement de statut — meme doctrine que `sendPollEventMessage()`
     * (« deux evenements seulement, jamais un par vote »).
     *
     * `metadata` ne porte QUE l'identifiant : le statut affiche dans la carte
     * doit toujours etre celui, ACTUEL, de l'action — jamais celui fige au
     * moment de la creation. C'est la vue (`LoopChat::projectedRoadmapItems()`)
     * qui relit l'action en direct a chaque rendu.
     *
     * IDEMPOTENT comme les deux methodes precedentes. Pas de `assertCanSend()` :
     * `LoopRoadmapCard::createAction()` a deja verifie `canWrite()`, qui
     * autorise aussi un administrateur non membre actif — meme raisonnement
     * que `sendDossierFileMessage()`.
     */
    public function sendRoadmapActionMessage(Loop $loop, User $sender, LoopRoadmapItem $item): ?LoopMessage
    {
        $dejaAnnonce = LoopMessage::where('loop_id', $loop->id)
            ->where('type', 'roadmap_action')
            ->where('metadata->roadmap_item_id', $item->id)
            ->exists();

        if ($dejaAnnonce) {
            return null;
        }

        $body = __('loops.chat_roadmap_action_created', ['name' => $sender->publicDisplayName(), 'title' => $item->title]);

        return DB::transaction(function () use ($loop, $sender, $item, $body) {
            $message = LoopMessage::create([
                'loop_id' => $loop->id,
                'sender_id' => $sender->id,
                'body' => $body,
                'type' => 'roadmap_action',
                'metadata' => [
                    'event' => 'created',
                    'roadmap_item_id' => $item->id,
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
