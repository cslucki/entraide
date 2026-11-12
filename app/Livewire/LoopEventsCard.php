<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Services\LoopMessageService;
use App\Services\Loops\EventException;
use App\Services\Loops\LoopEventService;
use App\Support\Loops\LoopEventPresenter;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * La Card Evenements d'une Boucle.
 *
 * Comme la Card Sondages : aucune regle metier ici. Le composant appelle
 * LoopEventService et affiche ce qu'il repond, refus compris. C'est ce qui fait
 * que les regles tiennent aussi bien sur une route directe que sur un clic.
 *
 * Deux vues, liste et mois. La liste est la vue par defaut : c'est celle qui
 * repond a « qu'est-ce qui arrive bientot », qui est la question qu'on se pose
 * en ouvrant une Card.
 */
class LoopEventsCard extends Component
{
    public Loop $loop;

    public string $view = 'list'; // list | calendar

    /** Mois affiche par le calendrier, au format Y-m. */
    public string $month = '';

    /**
     * TASK-1802 (demande Cyril, 10/11) — « par defaut un affichage sur 7
     * jours [...] possibilite via un menu de selectionner semaine ou mois ».
     * Une grille sur un mois entier repond a « quand en gros ? » ; une sur
     * sept jours repond a « que se passe-t-il cette semaine ? » — la
     * question la plus frequente en ouvrant la Card, d'ou le defaut.
     */
    public string $calendarGranularity = 'week'; // week | month

    /** Lundi de la semaine affichee, au format Y-m-d (quand granularite = week). */
    public string $weekStart = '';

    /**
     * Evenement dont le detail est ouvert depuis un clic dans le calendrier.
     * Memes donnees et memes actions que la vue liste (`event-row` partage) :
     * voir le detail d'un evenement ne doit pas en dire moins qu'en liste.
     */
    public ?string $selectedEventId = null;

    // ── Formulaire ──────────────────────────────────────────────────────────

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $title = '';

    public string $description = '';

    public string $format = LoopEvent::FORMAT_IN_PERSON;

    public string $startsAt = '';

    public string $endsAt = '';

    /**
     * TASK-1802 (demande Cyril, 10/11) — « simplifier la saisie de la date
     * de debut et de fin, car le choix des heures n'est pas pratique au
     * niveau des minutes ». Plutot que de choisir une fin dans l'absolu
     * (deuxieme selecteur minute par minute), on choisit une DUREE relative
     * au debut — un geste plus proche de la vraie question (« combien de
     * temps ? ») que « a quelle minute precise est-ce que ca s'arrete ? ».
     *
     * Valeurs : `''` (aucune fin), une cle de `DURATION_MINUTES`, ou
     * `self::DURATION_CUSTOM` (dates libres, comportement d'avant cette
     * TASK). `$endsAt` reste la SEULE verite envoyee au service — cette
     * propriete ne fait que le calculer pour la personne.
     */
    public string $duration = '';

    public const DURATION_CUSTOM = 'custom';

    /** @var array<string, int> minutes, indexees par la valeur du menu. */
    public const DURATION_MINUTES = [
        '30' => 30, '60' => 60, '90' => 90, '120' => 120,
        '180' => 180, '240' => 240, '480' => 480,
    ];

    /**
     * Vrai quand la fin calculee deborde sur un AUTRE jour que le debut (ou
     * en mode « dates personnalisees ») : c'est le seul cas ou revoir la
     * date de fin sert a quelque chose, donc le seul ou le champ reapparait
     * (demande explicite de Cyril : « si duree [...] fait qu'on depasse la
     * date du jour, la on affiche le champ pour saisir la date »).
     */
    public bool $durationCrossesDay = false;

    public string $timezone = '';

    /**
     * Le fuseau que le navigateur declare, pose une seule fois au montage.
     *
     * Faute de preference enregistree — ce produit n'en a ni sur `users` ni sur
     * `organizations` — c'est la seule source qui sache reellement ou se trouve
     * la personne. Elle reste une source et jamais une autorite : le serveur la
     * verifie contre les identifiants IANA de PHP avant de s'en servir.
     *
     * Renseigne par Alpine au premier rendu, puis plus jamais : redetecter a
     * chaque rendu Livewire ecraserait un choix fait a la main.
     */
    public string $browserTimezone = '';

    public string $location = '';

    public string $meetingUrl = '';

    public string $visibility = LoopEvent::VISIBILITY_LOOP;

    /** Vrai quand on modifie un Evenement auquel des gens ont deja repondu. */
    public bool $editingHasResponses = false;

    // ── Etat d'ecran ────────────────────────────────────────────────────────

    /** @var array<string, bool> */
    public array $showAttendees = [];

    public ?string $confirmingCancelId = null;

    public ?string $confirmingDeleteId = null;

    public ?string $confirmingVisibilityId = null;

    public ?string $pendingVisibility = null;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
        // Le mois et la semaine affiches par defaut : ceux d'ici, pas ceux
        // d'un fuseau choisi d'avance.
        $this->month = CarbonImmutable::now($this->preferredTimezone())->format('Y-m');
        $this->weekStart = CarbonImmutable::now($this->preferredTimezone())
            ->startOfWeek(CarbonImmutable::MONDAY)
            ->format('Y-m-d');
    }

    private function service(): LoopEventService
    {
        return app(LoopEventService::class);
    }

    /**
     * Le fuseau a proposer maintenant : celui du navigateur s'il a parle, sinon
     * ce que le serveur sait faire de mieux.
     */
    private function preferredTimezone(): string
    {
        return LoopEvent::resolveTimezone(null, $this->browserTimezone);
    }

    /**
     * Retenir ce que le navigateur declare, la premiere fois seulement.
     *
     * Ne touche jamais a `$this->timezone` : la detection alimente les
     * formulaires ouverts *ensuite*, elle ne defait pas un choix en cours.
     */
    public function setBrowserTimezone(string $timezone): void
    {
        if ($this->browserTimezone === '' && LoopEvent::isValidTimezone($timezone)) {
            $this->browserTimezone = $timezone;
        }
    }

    // ── Duree (TASK-1802) ─────────────────────────────────────────────────

    public function updatedDuration(): void
    {
        $this->applyDuration();
    }

    public function updatedStartsAt(): void
    {
        $this->applyDuration();
    }

    /**
     * Calcule `$endsAt` depuis `$startsAt` + `$duration`, et decide si le
     * champ de fin doit rester visible.
     *
     * Deux cas ou le champ REAPPARAIT, et un seul ou il reste cache :
     *   - `DURATION_CUSTOM` : dates libres, le champ a toujours servi a ca ;
     *   - une duree qui pousse la fin sur un AUTRE jour calendaire que le
     *     debut — demande explicite de Cyril. Une duree qui reste dans la
     *     meme journee n'a rien a montrer de plus : `$endsAt` est deja juste.
     */
    private function applyDuration(): void
    {
        if ($this->duration === '') {
            $this->endsAt = '';
            $this->durationCrossesDay = false;

            return;
        }

        if ($this->duration === self::DURATION_CUSTOM) {
            $this->durationCrossesDay = true;

            return;
        }

        $minutes = self::DURATION_MINUTES[$this->duration] ?? null;

        if ($minutes === null || $this->startsAt === '') {
            return;
        }

        try {
            $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $this->startsAt);
        } catch (\Throwable) {
            return;
        }

        if ($start === false) {
            return;
        }

        $end = $start->addMinutes($minutes);

        $this->endsAt = $end->format('Y-m-d\TH:i');
        $this->durationCrossesDay = ! $end->isSameDay($start);
    }

    /**
     * La duree selectionnee pour un Evenement EXISTANT (edition) : celle du
     * menu qui correspond EXACTEMENT a l'ecart deja enregistre, sinon
     * `DURATION_CUSTOM` — un ecart qui ne tombe sur aucune case du menu
     * (pose avant cette TASK, ou cree par l'ancien champ libre) reste
     * modifiable tel quel, jamais arrondi en silence.
     */
    private function durationFor(LoopEvent $event): string
    {
        if ($event->ends_at === null) {
            return '';
        }

        // `diffInMinutes()` rend un FLOAT : une comparaison stricte contre les
        // entiers de `DURATION_MINUTES` echouerait toujours (120.0 !== 120).
        $minutes = (int) $event->starts_at->diffInMinutes($event->ends_at);
        $cle = array_search($minutes, self::DURATION_MINUTES, true);

        return $cle === false ? self::DURATION_CUSTOM : $cle;
    }

    private function user()
    {
        return auth()->user();
    }

    // ── Vues ────────────────────────────────────────────────────────────────

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['list', 'calendar'], true) ? $view : 'list';
    }

    /**
     * TASK-1347 : le `!` n'est pas decoratif.
     *
     * Sans lui, `createFromFormat('Y-m', ...)` complete le JOUR manquant avec
     * celui d'AUJOURD'HUI. Un 31, viser un mois de 30 jours fabrique une date
     * inexistante (« 2026-09-31 ») que PHP fait deborder sur le mois suivant —
     * AVANT que `startOfMonth()` n'ait son mot a dire. La navigation partait
     * alors d'octobre en croyant partir de septembre, et se trompait d'un mois.
     * Le `!` remet a zero tout ce que le format ne nomme pas : le jour vaut 1,
     * il n'y a plus rien a faire deborder. C'est l'idiome deja retenu ailleurs
     * dans le depot (`!Y-m-d`).
     *
     * `startOfMonth()` devient redondant et reste : il dit l'intention sans
     * exiger de connaitre la semantique du `!`.
     */
    public function shiftMonth(int $delta): void
    {
        $this->month = CarbonImmutable::createFromFormat('!Y-m', $this->month)
            ->startOfMonth()
            ->addMonths($delta)
            ->format('Y-m');
    }

    public function shiftWeek(int $delta): void
    {
        $this->weekStart = CarbonImmutable::createFromFormat('!Y-m-d', $this->weekStart)
            ->startOfWeek(CarbonImmutable::MONDAY)
            ->addWeeks($delta)
            ->format('Y-m-d');
    }

    /**
     * Bascule semaine/mois en gardant les deux curseurs a peu pres au meme
     * endroit : sans ca, revenir de « mois » a « semaine » rouvrirait sur LA
     * semaine d'aujourd'hui, pas celle qu'on regardait a l'instant.
     */
    public function setCalendarGranularity(string $granularity): void
    {
        if (! in_array($granularity, ['week', 'month'], true) || $granularity === $this->calendarGranularity) {
            return;
        }

        if ($granularity === 'week') {
            $this->weekStart = CarbonImmutable::createFromFormat('!Y-m', $this->month)
                ->startOfMonth()
                ->startOfWeek(CarbonImmutable::MONDAY)
                ->format('Y-m-d');
        } else {
            $this->month = CarbonImmutable::createFromFormat('!Y-m-d', $this->weekStart)
                ->format('Y-m');
        }

        $this->calendarGranularity = $granularity;
    }

    public function openEventDetail(string $eventId): void
    {
        $this->selectedEventId = $eventId;
    }

    public function closeEventDetail(): void
    {
        $this->selectedEventId = null;
    }

    // ── Formulaire ──────────────────────────────────────────────────────────

    /**
     * @param  string|null  $detectedTimezone  ce que le navigateur declare, passe
     *                                         par le clic qui ouvre le formulaire
     */
    public function openCreateForm(?string $detectedTimezone = null): void
    {
        $this->resetMessages();

        // La detection voyage avec l'ouverture du formulaire, et non a
        // l'initialisation d'Alpine : `x-init` s'execute avant que Livewire ait
        // fini d'hydrater le composant, et l'appel partait dans le vide — un
        // navigateur a Chicago se voyait alors proposer le repli. Ici, le moment
        // est celui ou la valeur sert, sans course possible.
        if ($detectedTimezone !== null) {
            $this->setBrowserTimezone($detectedTimezone);
        }

        if (! $this->service()->canCreate($this->user(), $this->loop)) {
            $this->errorMessage = __('events.error_not_allowed');

            return;
        }

        $this->editingId = null;
        $this->editingHasResponses = false;
        $this->title = '';
        $this->description = '';
        $this->format = LoopEvent::FORMAT_IN_PERSON;
        // Le fuseau d'abord, la date ensuite : « demain 18h » doit vouloir dire
        // demain 18h *chez la personne*, pas demain 18h a Paris. Une date par
        // defaut evite par ailleurs un aller-retour — personne n'organise une
        // reunion pour l'instant meme.
        $this->timezone = $this->preferredTimezone();
        $this->startsAt = CarbonImmutable::now($this->timezone)
            ->addDay()->setTime(18, 0)->format('Y-m-d\TH:i');
        // 1 heure par defaut : la duree la plus frequente, et la fin se
        // calcule toute seule — personne n'a besoin d'y toucher pour une
        // rencontre ordinaire.
        $this->duration = '60';
        $this->applyDuration();
        $this->location = '';
        $this->meetingUrl = '';
        $this->visibility = LoopEvent::VISIBILITY_LOOP;
        $this->showForm = true;
    }

    public function openEditForm(string $eventId): void
    {
        $this->resetMessages();

        $event = $this->resolveEvent($eventId);

        if (! $event || ! $this->service()->canManageEvent($this->user(), $event, $this->loop)) {
            $this->errorMessage = __('events.error_not_allowed');

            return;
        }

        $this->editingId = $event->id;
        // Sert a prevenir, jamais a interdire : les reponses survivent a toute
        // modification.
        $this->editingHasResponses = $event->hasResponses();
        $this->title = $event->title;
        $this->description = (string) $event->description;
        $this->format = $event->format;
        // Celui de l'Evenement, sans discussion : une rencontre a Chicago reste
        // a Chicago, meme ouverte depuis Paris.
        $this->timezone = $event->timezone;
        $this->startsAt = $event->startsAtLocal()->format('Y-m-d\TH:i');
        $this->endsAt = $event->endsAtLocal()?->format('Y-m-d\TH:i') ?? '';
        $this->duration = $this->durationFor($event);
        $this->durationCrossesDay = $this->duration === self::DURATION_CUSTOM
            || ($event->endsAtLocal() !== null && ! $event->endsAtLocal()->isSameDay($event->startsAtLocal()));
        $this->location = (string) $event->location;
        $this->meetingUrl = (string) $event->meeting_url;
        $this->visibility = $event->visibility;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->editingHasResponses = false;
        $this->resetMessages();
    }

    public function save(): void
    {
        $this->resetMessages();

        $payload = [
            'title' => $this->title,
            'description' => $this->description,
            'format' => $this->format,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'timezone' => $this->timezone,
            'location' => $this->location,
            'meeting_url' => $this->meetingUrl,
            'visibility' => $this->visibility,
        ];

        try {
            if ($this->editingId !== null) {
                $event = $this->resolveEvent($this->editingId);

                if (! $event) {
                    $this->errorMessage = __('events.error_not_allowed');

                    return;
                }

                $result = $this->service()->update($this->user(), $event, $this->loop, $payload);

                // Une virgule changee ne merite pas un message ; une date, si.
                if ($result['notable']) {
                    $this->announce($result['event'], 'updated');
                }
            } else {
                $event = $this->service()->create($this->user(), $this->loop, $payload);
                $this->announce($event, 'created');
            }
        } catch (EventException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->showForm = false;
        $this->editingId = null;
        $this->editingHasResponses = false;
    }

    // ── Reponses ────────────────────────────────────────────────────────────

    public function respond(string $eventId, string $response): void
    {
        $this->resetMessages();

        $event = $this->resolveEvent($eventId);

        if (! $event) {
            return;
        }

        try {
            $this->service()->respond($this->user(), $event, $this->loop, $response);
            $this->successMessage = __('events.response_saved');
        } catch (EventException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function toggleAttendees(string $eventId): void
    {
        $this->showAttendees[$eventId] = ! ($this->showAttendees[$eventId] ?? false);
    }

    // ── Annulation, suppression, portee ─────────────────────────────────────

    public function confirmCancel(string $eventId): void
    {
        $this->resetMessages();
        $this->confirmingCancelId = $eventId;
    }

    public function confirmDelete(string $eventId): void
    {
        $this->resetMessages();
        $this->confirmingDeleteId = $eventId;
    }

    public function confirmVisibility(string $eventId, string $visibility): void
    {
        $this->resetMessages();
        $this->confirmingVisibilityId = $eventId;
        $this->pendingVisibility = $visibility;
    }

    public function cancelConfirmation(): void
    {
        $this->confirmingCancelId = null;
        $this->confirmingDeleteId = null;
        $this->confirmingVisibilityId = null;
        $this->pendingVisibility = null;
    }

    public function cancelEvent(string $eventId): void
    {
        $this->resetMessages();
        $this->confirmingCancelId = null;

        $event = $this->resolveEvent($eventId);

        if (! $event) {
            return;
        }

        try {
            $result = $this->service()->cancel($this->user(), $event, $this->loop);

            if ($result['changed']) {
                $this->announce($result['event'], 'cancelled');
            }
        } catch (EventException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function deleteEvent(string $eventId): void
    {
        $this->resetMessages();
        $this->confirmingDeleteId = null;

        $event = $this->resolveEvent($eventId);

        if (! $event) {
            return;
        }

        try {
            $this->service()->delete($this->user(), $event, $this->loop);
            $this->successMessage = __('events.deleted');
        } catch (EventException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function applyVisibility(): void
    {
        $this->resetMessages();

        $eventId = $this->confirmingVisibilityId;
        $visibility = $this->pendingVisibility;
        $this->cancelConfirmation();

        if ($eventId === null || $visibility === null) {
            return;
        }

        $event = $this->resolveEvent($eventId);

        if (! $event) {
            return;
        }

        try {
            $this->service()->changeVisibility($this->user(), $event, $this->loop, $visibility);
        } catch (EventException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    // ── Rendu ───────────────────────────────────────────────────────────────

    public function render()
    {
        $user = $this->user();
        $service = $this->service();

        if (! $user || ! $service->canView($user, $this->loop)) {
            return view('livewire.loop-events-card', [
                'upcoming' => collect(), 'past' => collect(), 'calendar' => [],
                'selectedEvent' => null,
                'canCreate' => false, 'canPublishOrg' => false, 'readOnly' => true,
                'timezoneOptions' => LoopEvent::TIMEZONES,
            ]);
        }

        $presenter = app(LoopEventPresenter::class);

        $events = $service->forLoop($this->loop)->map(
            fn (LoopEvent $event) => $presenter->present($event, $user, $this->loop, [
                'with_attendees' => $this->showAttendees[$event->id] ?? false,
            ]),
        );

        // « Passe » inclut les annules : ils quittent l'agenda vivant sans
        // quitter l'historique.
        [$past, $upcoming] = $events->partition(
            fn (array $e) => $e['is_past'] || $e['is_cancelled'],
        );

        $calendar = $this->calendarGranularity === 'week'
            ? $presenter->weekGrid($this->weekStart, $events->all())
            : $presenter->monthGrid($this->month, $events->all());

        return view('livewire.loop-events-card', [
            'upcoming' => $upcoming->sortBy('starts_at')->values(),
            'past' => $past->sortByDesc('starts_at')->values(),
            'calendar' => $calendar,
            'selectedEvent' => $this->selectedEventId !== null
                ? $events->firstWhere('id', $this->selectedEventId)
                : null,
            'canCreate' => $service->canCreate($user, $this->loop),
            'canPublishOrg' => $service->canPublishToOrganization($user, $this->loop),
            'readOnly' => ! $service->canCreate($user, $this->loop)
                && ! $service->canRespond($user, $this->loop),
            // Le fuseau affiche figure toujours dans le menu, meme absent de la
            // liste courte : sinon on ne pourrait pas y revenir apres l'avoir
            // quitte.
            'timezoneOptions' => LoopEvent::timezoneOptions($this->timezone),
        ]);
    }

    private function resolveEvent(string $eventId): ?LoopEvent
    {
        // Re-porte sur la Boucle et l'Organization : jamais de confiance dans un
        // identifiant venu du navigateur.
        return LoopEvent::where('id', $eventId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->first();
    }

    private function resetMessages(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;
    }

    /**
     * Annoncer dans ChatLoop sans jamais empecher l'Evenement d'exister.
     *
     * Le message est un accessoire : s'il echoue, la rencontre reste. Il vit
     * donc hors de la transaction du service, et son echec est journalise plutot
     * que remonte.
     */
    private function announce(LoopEvent $event, string $kind): void
    {
        try {
            app(LoopMessageService::class)->sendEventMessage($this->loop, $this->user(), $event, $kind);

            // Apres l'envoi, et dans le try : un message qui n'est pas parti ne
            // doit pas faire rafraichir un fil ou il n'y a rien de neuf.
            //
            // Meme nom d'evenement que la Card Sondage. ChatLoop rattrapait deja
            // ces messages au battement suivant de son `wire:poll.3s` ; ceci
            // supprime l'attente, sans remplacer le sondage periodique, qui sert
            // les messages des autres.
            $this->dispatch('loop-activity-published', loopId: $this->loop->id);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
