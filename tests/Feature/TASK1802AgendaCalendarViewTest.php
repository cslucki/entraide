<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopEventService;
use App\Support\Loops\LoopTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1802 (suite, demande Cyril) — « on va réutiliser cette vue
 * "Calendrier" sur la page Agenda ». Memes grilles que la Card
 * (`LoopEventPresenter::weekGrid()`/`monthGrid()`), navigation par liens GET
 * puisque cette page n'est pas un composant Livewire.
 *
 * Puis, demande suivante — « supprime la vue calendrier [...] uniquement
 * sur la version smartphone car ça ne se voit pas » : le bouton bascule ET
 * le panneau calendrier restent dans le DOM mais caches par CSS (`hidden
 * md:...`) en dessous de `md` ; la liste, elle, reste TOUJOURS montee, pour
 * qu'un lien `?view=calendar` ouvert depuis un telephone ne tombe jamais
 * sur un ecran vide.
 */
class TASK1802AgendaCalendarViewTest extends TestCase
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
            'name' => 'Boucle Agenda Calendrier',
            'slug' => Str::slug('Boucle Agenda Calendrier').'-'.uniqid(),
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
            'title' => 'Atelier calendrier agenda',
            'description' => null,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => 'Salle A',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ], $overrides));
    }

    private function agenda(array $query = []): string
    {
        return $this->actingAs($this->owner)
            ->get(route('organization.events.agenda', ['organization' => $this->org->slug] + $query))
            ->assertOk()
            ->getContent();
    }

    public function test_the_agenda_offers_a_list_calendar_toggle_hidden_on_mobile(): void
    {
        $this->event();

        $html = $this->agenda();

        $this->assertStringContainsString(e(__('events.view_list')), $html);
        $this->assertStringContainsString(e(__('events.view_calendar')), $html);
        $this->assertStringContainsString('view=calendar', $html);
        // Cache sur smartphone (TASK-1802 suite) : le groupe de boutons est
        // bien `hidden` par defaut, `md:inline-flex` le restitue a partir
        // de `md`.
        $this->assertMatchesRegularExpression(
            '/class="hidden md:inline-flex[^"]*"[^>]*>\s*<a[^>]*view=list/s',
            $html,
        );
    }

    public function test_the_calendar_view_shows_a_seven_day_week_by_default(): void
    {
        $event = $this->event(['title' => 'Atelier semaine par defaut']);

        $html = $this->agenda(['view' => 'calendar']);

        $this->assertStringContainsString(e(__('events.view_week')), $html);
        $this->assertStringContainsString(e(__('events.view_month')), $html);
        $this->assertStringContainsString($event->title, $html);
        // Panneau calendrier cache sur smartphone, visible a partir de `md`.
        $this->assertStringContainsString('hidden md:block', $html);
    }

    public function test_the_calendar_view_switches_to_month_via_the_query_string(): void
    {
        $event = $this->event(['title' => 'Atelier vue mois']);

        $html = $this->agenda(['view' => 'calendar', 'granularity' => 'month']);

        // La table du mois porte les 7 lettres de jour (L M M J V S D) —
        // absentes de la grille horaire de la semaine.
        $this->assertStringContainsString('>L<', $html);
        $this->assertStringContainsString('>D<', $html);
        $this->assertStringContainsString($event->title, $html);
    }

    public function test_the_list_stays_mounted_when_the_calendar_view_is_active(): void
    {
        $event = $this->event(['title' => 'Atelier jamais perdu sur mobile']);

        $html = $this->agenda(['view' => 'calendar']);

        // La liste reste TOUJOURS dans le document — seul `md:hidden` la
        // cache a partir de `md` — pour qu'un lien `?view=calendar` ouvert
        // depuis un telephone ne tombe jamais sur un ecran vide.
        $this->assertMatchesRegularExpression('/class="space-y-3 md:hidden"/', $html);
        // L'Evenement apparait DEUX fois : une dans la liste (toujours
        // montee, repli mobile) et une dans son pop-up de detail du
        // calendrier (`agenda-event` est inclus par les deux).
        $this->assertSame(
            2,
            substr_count($html, 'data-agenda-rsvp="'.$event->id.'"'),
        );
    }

    public function test_the_list_has_no_mobile_hiding_class_when_it_is_the_active_view(): void
    {
        $this->event();

        $html = $this->agenda();

        $this->assertMatchesRegularExpression('/class="space-y-3\s*"/', $html);
        $this->assertStringNotContainsString('space-y-3 md:hidden', $html);
    }

    public function test_clicking_a_calendar_event_opens_its_detail_popup(): void
    {
        $event = $this->event(['title' => 'Atelier pop-up calendrier']);

        $html = $this->agenda(['view' => 'calendar']);

        $this->assertStringContainsString("selectedEventId = '{$event->id}'", $html);
        $this->assertStringContainsString("selectedEventId === '{$event->id}'", $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
    }

    public function test_calendar_navigation_preserves_the_active_filters(): void
    {
        $this->event(['format' => LoopEvent::FORMAT_ONLINE, 'meeting_url' => 'https://visio.example.org/x']);

        $html = $this->agenda(['view' => 'calendar', 'format' => LoopEvent::FORMAT_ONLINE]);

        // Les liens de navigation (semaine precedente/suivante) repartent de
        // l'URL COURANTE : le filtre « format » doit donc y survivre.
        $this->assertMatchesRegularExpression('/href="[^"]*format='.LoopEvent::FORMAT_ONLINE.'[^"]*"[^>]*aria-label="'.preg_quote(e(__('events.previous_month')), '/').'"/', $html);
    }
}
