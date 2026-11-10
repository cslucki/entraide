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
 * TASK-1802 (suite, demande Cyril, 10/11) — « le design est loin d'etre
 * premium. Que pourrait-on faire en mode bento et qui reste dans l'esprit
 * BouclePro ? » Mockup valide par Cyril (« Oui c'est bien »), applique a
 * `loops/partials/event-row.blade.php`, partage par la Card et l'agenda.
 *
 * Ce fichier ne verifie AUCUNE couleur ni mise en page (hors de portee d'un
 * test PHP) : il verifie que l'information reste TOUTE PRESENTE apres le
 * reagencement en tuiles — c'est le risque reel d'une refonte visuelle,
 * perdre un champ en route.
 */
class TASK1802EventRowBentoTest extends TestCase
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
            'name' => 'Boucle Bento',
            'slug' => Str::slug('Boucle Bento').'-'.uniqid(),
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
            'title' => 'Atelier bento',
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

    private function renderCard(): string
    {
        return Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->html();
    }

    public function test_the_date_tile_carries_weekday_day_month_time_and_timezone(): void
    {
        $this->event(['starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'), 'timezone' => 'Europe/Paris']);

        $html = $this->renderCard();

        $this->assertStringContainsString('10:00', $html);
        $this->assertStringContainsString('Europe/Paris', $html);
        $this->assertStringContainsString('2026', $html);
    }

    public function test_an_event_with_an_end_shows_the_time_range_in_the_date_tile(): void
    {
        $this->event([
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'),
            'ends_at' => CarbonImmutable::parse('2026-12-01 11:30')->format('Y-m-d\TH:i'),
        ]);

        $html = $this->renderCard();

        $this->assertStringContainsString('10:00–11:30', $html);
    }

    public function test_a_location_only_event_shows_the_where_tile_with_the_address(): void
    {
        $this->event(['location' => 'Salle du fond']);

        $html = $this->renderCard();

        $this->assertStringContainsString('Salle du fond', $html);
        // Le format n'apparait qu'UNE fois dans la meta du titre quand il n'y a
        // pas de tuile « ou » dediee ; avec une tuile « ou », il reapparait en
        // en-tete de cette tuile — deux occurrences.
        $this->assertSame(2, substr_count($html, __('events.format_in_person')));
    }

    public function test_an_online_event_shows_the_where_tile_with_a_clickable_link(): void
    {
        $this->event(['format' => LoopEvent::FORMAT_ONLINE, 'meeting_url' => 'https://visio.example.org/bento']);

        $html = $this->renderCard();

        $this->assertStringContainsString(
            '<a href="https://visio.example.org/bento" target="_blank" rel="noopener noreferrer"',
            $html,
        );
    }

    /**
     * `LoopEventService::create()` exige un lieu pour une rencontre « sur
     * place » — ce cas n'est donc PLUS atteignable par le formulaire. Mais il
     * reste atteignable par une ligne plus ancienne que cette regle (donnee
     * figee, meme precaution que pour les messages ChatLoop de cette TASK) :
     * cree ici en passant directement par le modele, hors du service.
     */
    public function test_a_legacy_in_person_event_without_a_location_has_no_where_tile(): void
    {
        $event = LoopEvent::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'created_by' => $this->owner->id,
            'status' => LoopEvent::STATUS_SCHEDULED,
            'title' => 'Vieille rencontre sans lieu',
            'description' => null,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00', 'Europe/Paris')->utc(),
            'ends_at' => null,
            'timezone' => 'Europe/Paris',
            'location' => '',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ]);

        $html = $this->renderCard();

        $this->assertStringContainsString($event->title, $html);
        // Le format ne figure qu'UNE fois (meta du titre) : pas de tuile
        // « ou » dediee a afficher en double quand il n'y a rien a y mettre.
        $this->assertSame(1, substr_count($html, __('events.format_in_person')));
    }

    public function test_the_description_tile_renders_when_present(): void
    {
        $this->event(['description' => 'Apporter son ordinateur portable.']);

        $html = $this->renderCard();

        $this->assertStringContainsString('Apporter son ordinateur portable.', $html);
    }

    public function test_the_presence_tile_always_shows_the_three_counts(): void
    {
        $this->event();

        $html = $this->renderCard();

        $this->assertStringContainsString(__('events.going_short').' 0', $html);
        $this->assertStringContainsString(__('events.maybe_short').' 0', $html);
        $this->assertStringContainsString(__('events.not_going_short').' 0', $html);
    }

    public function test_the_title_and_author_remain_visible_in_the_headline_tile(): void
    {
        $this->event(['title' => 'Cafe du mardi']);

        $html = $this->renderCard();

        $this->assertStringContainsString('Cafe du mardi', $html);
        $this->assertStringContainsString(__('events.by', ['name' => $this->owner->publicDisplayName()]), $html);
    }

    public function test_the_agenda_page_renders_the_same_bento_row(): void
    {
        $this->event(['title' => 'Rencontre agenda', 'location' => 'Hall principal']);

        $html = $this->actingAs($this->owner)
            ->get(route('organization.events.agenda', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rencontre agenda', $html);
        $this->assertStringContainsString('Hall principal', $html);
    }
}
