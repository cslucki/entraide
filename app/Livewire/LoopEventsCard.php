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

    // ── Formulaire ──────────────────────────────────────────────────────────

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $title = '';

    public string $description = '';

    public string $format = LoopEvent::FORMAT_IN_PERSON;

    public string $startsAt = '';

    public string $endsAt = '';

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
        // Le mois affiche par defaut : celui d'ici, pas celui d'un fuseau
        // choisi d'avance.
        $this->month = CarbonImmutable::now($this->preferredTimezone())->format('Y-m');
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
        $this->endsAt = '';
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

        return view('livewire.loop-events-card', [
            'upcoming' => $upcoming->sortBy('starts_at')->values(),
            'past' => $past->sortByDesc('starts_at')->values(),
            'calendar' => $presenter->monthGrid($this->month, $events->all()),
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
