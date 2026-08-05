<?php

namespace App\Support\Loops;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\User;
use App\Services\Loops\LoopEventService;
use Carbon\CarbonImmutable;

/**
 * Mettre un Evenement en forme pour l'ecran — une fois, pour deux ecrans.
 *
 * La Card d'une Boucle et l'agenda d'une Organization montrent la meme chose,
 * avec les memes regles de droits. Sans cet objet, la seconde aurait recopie la
 * premiere, et les deux auraient diverge a la premiere evolution.
 *
 * Rien ici n'ecrit ni ne decide : les droits sont demandes a LoopEventService,
 * qui reste l'autorite.
 */
class LoopEventPresenter
{
    public function __construct(private LoopEventService $events) {}

    /**
     * @param  array{with_attendees?: bool}  $options
     * @return array<string, mixed>
     */
    public function present(LoopEvent $event, User $user, ?Loop $loop = null, array $options = []): array
    {
        $loop ??= $event->loop;
        $counts = $this->events->counts($event);

        return [
            'model' => $event,
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'format' => $event->format,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            'timezone' => $event->timezone,
            // Deja converti : la vue affiche, elle ne calcule pas de fuseau.
            'starts_local' => $event->startsAtLocal(),
            'ends_local' => $event->endsAtLocal(),
            'location' => $event->location,
            'meeting_url' => $event->meeting_url,
            'visibility' => $event->visibility,
            'is_organization_wide' => $event->isOrganizationWide(),
            'is_cancelled' => $event->isCancelled(),
            'is_past' => $event->isPast(),
            'cancelled_at' => $event->cancelled_at,
            'author' => $event->creator?->publicDisplayName() ?? __('events.unknown_author'),
            'loop_id' => $event->loop_id,
            'loop_name' => $loop?->name,
            'counts' => $counts,
            'my_response' => $this->events->responseOf($user, $event),
            'can_respond' => $loop !== null && $this->events->canRespondTo($user, $event, $loop),
            'can_manage' => $loop !== null && $this->events->canManageEvent($user, $event, $loop),
            'can_delete' => $loop !== null
                && $this->events->canManageEvent($user, $event, $loop)
                && $counts['going'] + $counts['maybe'] + $counts['not_going'] === 0,
            // Charge seulement si on l'a demande : une Boucle de deux cents
            // personnes n'a pas a payer ce chargement pour lire un titre.
            'attendees' => ($options['with_attendees'] ?? false)
                ? $this->events->respondents($event)
                : null,
        ];
    }

    /**
     * La grille d'un mois : six semaines de sept jours, evenements attaches.
     *
     * Six semaines toujours, jamais cinq : une grille dont la hauteur change
     * d'un mois a l'autre fait sauter la page a chaque navigation.
     *
     * Le decoupage suit le fuseau de chaque Evenement — une reunion a 00h30 a
     * Paris tombe le bon jour, pas la veille en UTC.
     *
     * @param  string  $month  Y-m
     * @param  array<int, array<string, mixed>>  $events  deja passes par present()
     * @return array{label: string, weeks: array<int, array<int, array<string, mixed>>>}
     */
    public function monthGrid(string $month, array $events): array
    {
        try {
            $first = CarbonImmutable::createFromFormat('Y-m', $month)->startOfMonth();
        } catch (\Throwable) {
            $first = CarbonImmutable::now()->startOfMonth();
        }

        $byDay = [];
        foreach ($events as $event) {
            $byDay[$event['starts_local']->format('Y-m-d')][] = $event;
        }

        // La semaine commence le lundi : c'est la convention du public de ce
        // produit, et `startOfWeek()` la suit deja via la locale.
        $cursor = $first->startOfWeek(CarbonImmutable::MONDAY);
        $today = CarbonImmutable::now()->format('Y-m-d');

        $weeks = [];
        for ($w = 0; $w < 6; $w++) {
            $days = [];
            for ($d = 0; $d < 7; $d++) {
                $key = $cursor->format('Y-m-d');
                $days[] = [
                    'date' => $cursor,
                    'day' => $cursor->day,
                    'in_month' => $cursor->month === $first->month,
                    'is_today' => $key === $today,
                    'events' => $byDay[$key] ?? [],
                ];
                $cursor = $cursor->addDay();
            }
            $weeks[] = $days;
        }

        return ['label' => $first->translatedFormat('F Y'), 'weeks' => $weeks];
    }
}
