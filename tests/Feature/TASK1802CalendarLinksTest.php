<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopEventService;
use App\Support\Loops\LoopEventCalendarLinks;
use App\Support\Loops\LoopTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1802 (suite, demande Cyril, 10/11) — « un bouton pour ajouter à son
 * agenda [...] compatible Google Agenda et si possible les autres services
 * [...] si on ne peut pas faire un truc compatible sur tout, pop-up ».
 *
 * `LoopEventCalendarLinks` ne decide rien : il traduit un Evenement en
 * quatre liens (Google, Outlook, Yahoo, `.ics`), toujours en UTC.
 */
class TASK1802CalendarLinksTest extends TestCase
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
            'name' => 'Boucle Agenda',
            'slug' => Str::slug('Boucle Agenda').'-'.uniqid(),
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
            'title' => 'Atelier agenda',
            'description' => null,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => 'Salle A',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ], $overrides));
    }

    public function test_google_link_carries_the_utc_date_range_and_location(): void
    {
        $event = $this->event([
            'ends_at' => CarbonImmutable::parse('2026-12-01 11:30')->format('Y-m-d\TH:i'),
        ]);

        $links = LoopEventCalendarLinks::forEvent($event);

        // 10:00-11:30 Europe/Paris (CET, UTC+1) en decembre -> 09:00-10:30 UTC.
        $this->assertStringContainsString('dates=20261201T090000Z%2F20261201T103000Z', $links['google']);
        $this->assertStringContainsString('text=Atelier+agenda', $links['google']);
        $this->assertStringContainsString('location=Salle+A', $links['google']);
    }

    public function test_an_event_without_an_end_gets_a_one_hour_window_without_corrupting_the_start(): void
    {
        // Lecon payee en ecrivant la classe : `starts_at`/`ends_at` sont des
        // Carbon MUTABLES. Sans `copy()`, calculer la fin par defaut aurait
        // modifie le DEBUT en place, et les deux auraient porte le MEME
        // instant — un evenement de duree zero dans l'agenda ajoute.
        $event = $this->event(['ends_at' => '']);
        $startBefore = $event->starts_at->toIso8601String();

        $links = LoopEventCalendarLinks::forEvent($event);

        $this->assertSame($startBefore, $event->starts_at->toIso8601String());
        $this->assertStringContainsString('dates=20261201T090000Z%2F20261201T100000Z', $links['google']);
    }

    public function test_outlook_link_uses_iso8601_start_and_end(): void
    {
        $event = $this->event([
            'ends_at' => CarbonImmutable::parse('2026-12-01 11:30')->format('Y-m-d\TH:i'),
        ]);

        $links = LoopEventCalendarLinks::forEvent($event);

        // `toIso8601String()` ecrit le decalage en `+00:00`, pas en `Z` —
        // equivalent pour tout lecteur ISO 8601, Outlook compris.
        $this->assertStringContainsString('startdt=2026-12-01T09%3A00%3A00%2B00%3A00', $links['outlook']);
        $this->assertStringContainsString('enddt=2026-12-01T10%3A30%3A00%2B00%3A00', $links['outlook']);
    }

    public function test_yahoo_link_encodes_the_duration_as_hhmm(): void
    {
        $event = $this->event([
            'ends_at' => CarbonImmutable::parse('2026-12-01 11:30')->format('Y-m-d\TH:i'),
        ]);

        $links = LoopEventCalendarLinks::forEvent($event);

        $this->assertStringContainsString('dur=0130', $links['yahoo']);
        $this->assertStringContainsString('st=20261201T090000Z', $links['yahoo']);
    }

    public function test_the_ics_link_escapes_commas_and_newlines_in_the_description(): void
    {
        $event = $this->event(['description' => "Apporter, si possible,\nson ordinateur."]);

        $links = LoopEventCalendarLinks::forEvent($event);

        $decoded = rawurldecode(str_replace('data:text/calendar;charset=utf8,', '', $links['ics']));

        $this->assertStringContainsString('BEGIN:VCALENDAR', $decoded);
        $this->assertStringContainsString('SUMMARY:Atelier agenda', $decoded);
        $this->assertStringContainsString('DESCRIPTION:Apporter\\, si possible\\,\\nson ordinateur.', $decoded);
        $this->assertStringContainsString('LOCATION:Salle A', $decoded);
    }

    public function test_an_online_event_puts_the_meeting_url_in_the_location(): void
    {
        $event = $this->event([
            'format' => LoopEvent::FORMAT_ONLINE,
            'location' => '',
            'meeting_url' => 'https://visio.example.org/atelier',
        ]);

        $links = LoopEventCalendarLinks::forEvent($event);

        $this->assertStringContainsString('location=https%3A%2F%2Fvisio.example.org%2Fatelier', $links['google']);
    }
}
