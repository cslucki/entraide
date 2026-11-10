<?php

namespace Tests\Feature;

use App\Livewire\LoopEventsCard;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopEventService;
use App\Support\Loops\LoopEventPresenter;
use App\Support\Loops\LoopTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1802 (suite, demande Cyril, 10/11) — dans la Card Evenements, vue
 * calendrier :
 *
 * 1. « Par defaut un affichage sur 7 jours. »
 * 2. « Possibilite via un menu de selectionner semaine ou mois. »
 * 3. « Quand on clique sur un evenement on voit son detail. »
 */
class TASK1802EventCalendarViewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle Calendrier',
            'slug' => Str::slug('Boucle Calendrier').'-'.uniqid(),
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $this->owner->id,
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->loop);

        LoopMember::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        app()->instance('current_organization', $this->org);
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): LoopEvent
    {
        return app(LoopEventService::class)->create($this->owner, $this->loop, array_merge([
            'title' => 'Reunion de semaine',
            'description' => null,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::now()->addDay()->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => 'Salle A',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ], $overrides));
    }

    public function test_the_calendar_opens_on_a_seven_day_week_by_default(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('setView', 'calendar')
            ->assertSet('calendarGranularity', 'week')
            ->assertViewHas('calendar', fn (array $calendar) => count($calendar['days']) === 7);
    }

    public function test_a_menu_switches_between_week_and_month(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('setView', 'calendar')
            ->call('setCalendarGranularity', 'month')
            ->assertSet('calendarGranularity', 'month')
            ->assertViewHas('calendar', fn (array $calendar) => array_key_exists('weeks', $calendar));

        $component->call('setCalendarGranularity', 'week')
            ->assertSet('calendarGranularity', 'week')
            ->assertViewHas('calendar', fn (array $calendar) => array_key_exists('days', $calendar));
    }

    public function test_an_unknown_granularity_is_ignored(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('setCalendarGranularity', 'year')
            ->assertSet('calendarGranularity', 'week');
    }

    public function test_switching_to_month_lands_on_the_week_s_own_month(): void
    {
        $event = $this->event(['starts_at' => CarbonImmutable::parse('2026-12-15 10:00')->format('Y-m-d\TH:i')]);

        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('setView', 'calendar')
            ->set('weekStart', CarbonImmutable::parse('2026-12-15')->startOfWeek()->format('Y-m-d'))
            ->call('setCalendarGranularity', 'month')
            ->assertSet('month', '2026-12')
            ->assertViewHas('calendar', fn (array $calendar) => str_contains($calendar['label'], $event->starts_at->isoFormat('MMMM')));
    }

    public function test_shifting_the_week_moves_by_seven_days(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('setView', 'calendar');

        $before = $component->get('weekStart');

        $component->call('shiftWeek', 1);

        $this->assertSame(
            CarbonImmutable::parse($before)->addWeek()->format('Y-m-d'),
            $component->get('weekStart'),
        );
    }

    public function test_an_event_lands_on_its_local_day_in_the_week_grid(): void
    {
        // 00h30 a Paris, c'est encore la veille en UTC : la grille doit suivre
        // le fuseau de l'Evenement, meme logique que `monthGrid()`.
        $event = $this->event(['starts_at' => '2026-08-12T00:30', 'timezone' => 'Europe/Paris']);

        $presenter = app(LoopEventPresenter::class);
        $presented = $presenter->present($event, $this->owner, $this->loop);
        $grid = $presenter->weekGrid('2026-08-10', [$presented]);

        $this->assertCount(7, $grid['days']);

        $found = null;
        foreach ($grid['days'] as $day) {
            if ($day['events'] !== []) {
                $found = $day;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame('2026-08-12', $found['date']->format('Y-m-d'));
    }

    public function test_clicking_an_event_in_the_calendar_shows_its_detail(): void
    {
        $event = $this->event(['title' => 'Atelier mensuel', 'location' => 'Salle B']);

        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('setView', 'calendar')
            ->call('openEventDetail', $event->id)
            ->assertSet('selectedEventId', $event->id)
            ->assertSee('Atelier mensuel')
            ->assertSee('Salle B');
    }

    public function test_closing_the_detail_clears_the_selection(): void
    {
        $event = $this->event();

        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openEventDetail', $event->id)
            ->assertSet('selectedEventId', $event->id)
            ->call('closeEventDetail')
            ->assertSet('selectedEventId', null);
    }

    public function test_the_detail_view_renders_nothing_when_no_event_is_selected(): void
    {
        $this->event();

        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->assertSet('selectedEventId', null)
            ->assertViewHas('selectedEvent', null);
    }
}
