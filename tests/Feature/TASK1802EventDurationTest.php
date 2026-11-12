<?php

namespace Tests\Feature;

use App\Livewire\LoopEventsCard;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1802 (demande Cyril, 10/11) — « simplifier la saisie de la date de
 * début et de fin, car le choix des heures n'est pas pratique au niveau des
 * minutes. On ajoute un menu "Durée" et si durée est > à x heures ce qui
 * fait qu'on dépasse la date du jour, là on affiche le champ pour saisir la
 * date. »
 *
 * La FIN se choisit donc comme une durée relative au début : `applyDuration()`
 * calcule `$endsAt` tout seul, et le champ de date ne reapparait que si cette
 * duree deborde sur un AUTRE jour calendaire que le debut (ou en mode « dates
 * personnalisees », l'echappatoire conservee pour les cas hors menu).
 */
class TASK1802EventDurationTest extends TestCase
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
            'name' => 'Boucle Duree',
            'slug' => Str::slug('Boucle Duree').'-'.uniqid(),
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

    public function test_opening_the_form_defaults_to_a_one_hour_duration_with_the_end_hidden(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->assertSet('duration', '60')
            ->assertSet('durationCrossesDay', false);
    }

    public function test_choosing_a_duration_computes_the_end_from_the_start(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('startsAt', '2026-12-01T14:00')
            ->set('duration', '90');

        $this->assertSame('2026-12-01T15:30', $component->get('endsAt'));
        $component->assertSet('durationCrossesDay', false);
    }

    public function test_a_duration_crossing_midnight_reveals_the_end_field(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('startsAt', '2026-12-01T23:00')
            ->set('duration', '180');

        // 23:00 + 3h = 02:00 le lendemain : un autre jour calendaire.
        $this->assertSame('2026-12-02T02:00', $component->get('endsAt'));
        $component->assertSet('durationCrossesDay', true);
    }

    public function test_changing_the_start_recomputes_the_end_for_the_same_duration(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('startsAt', '2026-12-01T10:00')
            ->set('duration', '60');

        $this->assertSame('2026-12-01T11:00', $component->get('endsAt'));

        $component->set('startsAt', '2026-12-01T16:00');

        $this->assertSame('2026-12-01T17:00', $component->get('endsAt'));
    }

    public function test_no_end_time_clears_the_end_and_hides_the_field(): void
    {
        $component = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('duration', '');

        $this->assertSame('', $component->get('endsAt'));
        $component->assertSet('durationCrossesDay', false);
    }

    public function test_custom_mode_always_shows_the_end_field(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('duration', LoopEventsCard::DURATION_CUSTOM)
            ->assertSet('durationCrossesDay', true);
    }

    public function test_saving_with_a_duration_persists_the_computed_end(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('title', 'Atelier')
            ->set('startsAt', '2026-12-01T14:00')
            ->set('timezone', 'Europe/Paris')
            ->set('duration', '90')
            ->set('location', 'Salle A')
            ->call('save');

        $event = LoopEvent::where('loop_id', $this->loop->id)->firstOrFail();

        $this->assertNotNull($event->ends_at);
        $this->assertSame(90, (int) $event->starts_at->diffInMinutes($event->ends_at));
    }

    public function test_editing_an_event_whose_gap_matches_a_preset_selects_it_with_the_end_hidden(): void
    {
        $event = app(LoopEventService::class)->create($this->owner, $this->loop, [
            'title' => 'Reunion',
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'),
            'ends_at' => CarbonImmutable::parse('2026-12-01 12:00')->format('Y-m-d\TH:i'),
            'timezone' => 'Europe/Paris',
            'location' => 'Salle A',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openEditForm', $event->id)
            ->assertSet('duration', '120')
            ->assertSet('durationCrossesDay', false);
    }

    public function test_editing_an_event_with_a_non_standard_gap_falls_back_to_custom(): void
    {
        $event = app(LoopEventService::class)->create($this->owner, $this->loop, [
            'title' => 'Reunion',
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'),
            'ends_at' => CarbonImmutable::parse('2026-12-01 11:17')->format('Y-m-d\TH:i'),
            'timezone' => 'Europe/Paris',
            'location' => 'Salle A',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openEditForm', $event->id)
            ->assertSet('duration', LoopEventsCard::DURATION_CUSTOM)
            ->assertSet('durationCrossesDay', true)
            ->assertSet('endsAt', '2026-12-01T11:17');
    }

    public function test_editing_an_event_without_any_end_selects_no_duration(): void
    {
        $event = app(LoopEventService::class)->create($this->owner, $this->loop, [
            'title' => 'Reunion',
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => 'Salle A',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ]);

        Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openEditForm', $event->id)
            ->assertSet('duration', '')
            ->assertSet('durationCrossesDay', false);
    }
}
