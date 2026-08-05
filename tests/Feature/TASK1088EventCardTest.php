<?php

namespace Tests\Feature;

use App\Livewire\LoopEventsCard;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\EventException;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\Loops\LoopEventService;
use App\Services\Loops\LoopLifecycleService;
use App\Services\Loops\LoopPresetSyncService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopEventPresenter;
use App\Support\Loops\LoopTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La Card Evenements : proposer une rencontre, dire si on vient, la retrouver.
 *
 * Trois choses sont defendues ici plus que le reste : **une Boucle privee ne
 * publie jamais a l'Organization**, un Evenement auquel on a repondu ne se
 * supprime plus, et l'agenda d'une personne ne laisse jamais filtrer une
 * rencontre d'une Boucle dont elle n'est pas membre.
 */
class TASK1088EventCardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $orgAdmin;

    private User $owner;

    private User $facilitator;

    private User $member;

    private User $other;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);

        $this->owner = $this->userInOrg();
        $this->facilitator = $this->userInOrg();
        $this->member = $this->userInOrg();
        $this->other = $this->userInOrg();

        // Publique : la portee Organization n'est possible que hors Boucle
        // privee, et la plupart des tests en ont besoin. Le cas prive a ses
        // propres tests, avec sa propre Boucle.
        $this->loop = $this->makeLoop('Boucle Dialogue', 'public');

        $this->join($this->loop, $this->owner, 'owner');
        $this->join($this->loop, $this->facilitator, 'facilitator');
        $this->join($this->loop, $this->member, 'member');
        $this->join($this->loop, $this->other, 'member');

        app()->instance('current_organization', $this->org);
    }

    private function userInOrg(?Organization $org = null): User
    {
        return User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
    }

    private function makeLoop(string $name, string $visibility = 'public', ?Organization $org = null): Loop
    {
        $org ??= $this->org;

        $loop = Loop::create([
            'organization_id' => $org->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name).'-'.uniqid(),
            'type' => 'general',
            'status' => 'active',
            'visibility' => $visibility,
            'created_by' => $this->owner->id,
        ]);

        app(LoopTypeRegistry::class)->applyPreset($loop);

        return $loop;
    }

    private function join(Loop $loop, User $user, string $role): void
    {
        LoopMember::create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function service(): LoopEventService
    {
        return app(LoopEventService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Reunion d\'equipe',
            'description' => null,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::now()->addDays(3)->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => 'Salle du fond',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function event(?User $author = null, array $overrides = [], ?Loop $loop = null): LoopEvent
    {
        return $this->service()->create(
            $author ?? $this->member,
            $loop ?? $this->loop,
            $this->payload($overrides),
        );
    }

    // ── Modele, fuseaux, contraintes ────────────────────────────────────────

    public function test_a_local_time_is_stored_in_utc_with_its_zone(): void
    {
        // 19h00 a Paris en aout = 17h00 UTC.
        $event = $this->event(overrides: ['starts_at' => '2026-08-12T19:00', 'timezone' => 'Europe/Paris']);

        $this->assertSame('2026-08-12 17:00:00', $event->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Paris', $event->timezone);
        // Et relu dans son fuseau, il redit 19h00.
        $this->assertSame('19:00', $event->startsAtLocal()->format('H:i'));
    }

    public function test_another_zone_lands_on_another_instant(): void
    {
        // 19h00 a Chicago en aout = 00h00 UTC le lendemain.
        $event = $this->event(overrides: ['starts_at' => '2026-08-12T19:00', 'timezone' => 'America/Chicago']);

        $this->assertSame('2026-08-13 00:00:00', $event->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('19:00', $event->startsAtLocal()->format('H:i'));
    }

    public function test_a_winter_date_uses_the_winter_offset(): void
    {
        // Le meme « 19:00 » a Paris vaut 18:00 UTC en janvier et 17:00 en aout :
        // c'est ce que stocker un fuseau IANA, et non un decalage, permet de
        // resoudre tout seul.
        $winter = $this->event(overrides: ['starts_at' => '2027-01-12T19:00', 'timezone' => 'Europe/Paris']);
        $summer = $this->event(overrides: ['starts_at' => '2027-08-12T19:00', 'timezone' => 'Europe/Paris']);

        $this->assertSame('18:00', $winter->starts_at->utc()->format('H:i'));
        $this->assertSame('17:00', $summer->starts_at->utc()->format('H:i'));
    }

    public function test_an_unknown_timezone_is_refused(): void
    {
        $this->expectException(EventException::class);
        $this->event(overrides: ['timezone' => 'Mars/Olympus']);
    }

    public function test_one_response_per_person_is_enforced_by_the_database(): void
    {
        $event = $this->event();

        LoopEventResponse::create([
            'organization_id' => $this->org->id, 'event_id' => $event->id,
            'user_id' => $this->member->id, 'response' => LoopEventResponse::GOING,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        LoopEventResponse::create([
            'organization_id' => $this->org->id, 'event_id' => $event->id,
            'user_id' => $this->member->id, 'response' => LoopEventResponse::MAYBE,
        ]);
    }

    // ── Creation et validation ──────────────────────────────────────────────

    public function test_every_active_member_may_propose_a_meeting(): void
    {
        foreach ([$this->owner, $this->facilitator, $this->member] as $user) {
            $this->assertTrue($this->service()->canCreate($user, $this->loop));
        }
    }

    public function test_a_non_member_may_not_create(): void
    {
        $stranger = $this->userInOrg();

        $this->expectException(EventException::class);
        $this->service()->create($stranger, $this->loop, $this->payload());
    }

    public function test_someone_from_another_organization_is_refused(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->assertFalse($this->service()->canCreate($stranger, $this->loop));
        $this->assertFalse($this->service()->canView($stranger, $this->loop));
    }

    public function test_an_empty_title_is_refused(): void
    {
        $this->expectException(EventException::class);
        $this->event(overrides: ['title' => '   ']);
    }

    public function test_an_end_before_the_start_is_refused(): void
    {
        $this->expectException(EventException::class);
        $this->event(overrides: [
            'starts_at' => '2026-08-12T19:00',
            'ends_at' => '2026-08-12T18:00',
        ]);
    }

    public function test_an_in_person_meeting_needs_a_place(): void
    {
        $this->expectException(EventException::class);
        $this->event(overrides: ['format' => LoopEvent::FORMAT_IN_PERSON, 'location' => '']);
    }

    public function test_an_online_meeting_needs_a_link(): void
    {
        $this->expectException(EventException::class);
        $this->event(overrides: [
            'format' => LoopEvent::FORMAT_ONLINE, 'location' => '', 'meeting_url' => '',
        ]);
    }

    public function test_a_hybrid_meeting_needs_both(): void
    {
        $event = $this->event(overrides: [
            'format' => LoopEvent::FORMAT_HYBRID,
            'location' => 'Salle A', 'meeting_url' => 'https://meet.example.test/abc',
        ]);

        $this->assertSame(LoopEvent::FORMAT_HYBRID, $event->format);
        $this->assertNotNull($event->location);
        $this->assertNotNull($event->meeting_url);
    }

    public function test_a_dangerous_link_is_refused(): void
    {
        // `javascript:` n'a rien a faire dans un href qu'on rendra cliquable.
        $this->expectException(EventException::class);
        $this->event(overrides: [
            'format' => LoopEvent::FORMAT_ONLINE, 'location' => '',
            'meeting_url' => 'javascript:alert(1)',
        ]);
    }

    public function test_a_very_long_title_is_cut_not_refused(): void
    {
        $event = $this->event(overrides: ['title' => str_repeat('a', 400)]);

        $this->assertSame(255, mb_strlen($event->title));
    }

    // ── Portee, et la regle des Boucles privees ─────────────────────────────

    public function test_a_private_loop_never_publishes_to_the_organization(): void
    {
        $private = $this->makeLoop('Boucle privee', 'private');
        $this->join($private, $this->owner, 'owner');

        // Meme le proprietaire, meme avec la permission : la Boucle decide.
        $this->assertFalse($this->service()->canPublishToOrganization($this->owner, $private));

        $this->expectException(EventException::class);
        $this->service()->create($this->owner, $private, $this->payload([
            'visibility' => LoopEvent::VISIBILITY_ORGANIZATION,
        ]));
    }

    public function test_a_forged_scope_change_on_a_private_loop_is_refused(): void
    {
        $private = $this->makeLoop('Boucle privee 2', 'private');
        $this->join($private, $this->owner, 'owner');
        $event = $this->event($this->owner, loop: $private);

        try {
            $this->service()->changeVisibility($this->owner, $event, $private, LoopEvent::VISIBILITY_ORGANIZATION);
            $this->fail('Le changement de portée aurait dû être refusé.');
        } catch (EventException) {
            // C'est le refus le plus important de cette tache.
        }

        $this->assertSame(LoopEvent::VISIBILITY_LOOP, $event->fresh()->visibility);
    }

    public function test_a_plain_member_may_not_publish_to_the_organization(): void
    {
        $this->assertFalse($this->service()->canPublishToOrganization($this->member, $this->loop));
        $this->assertTrue($this->service()->canPublishToOrganization($this->owner, $this->loop));
        $this->assertTrue($this->service()->canPublishToOrganization($this->facilitator, $this->loop));
    }

    public function test_changing_the_scope_keeps_every_answer(): void
    {
        $event = $this->event($this->owner, ['visibility' => LoopEvent::VISIBILITY_ORGANIZATION]);
        $this->service()->respond($this->member, $event, $this->loop, LoopEventResponse::GOING);

        $this->service()->changeVisibility($this->owner, $event, $this->loop, LoopEvent::VISIBILITY_LOOP);

        // Perdre l'acces ne retire pas la parole qu'on a donnee.
        $this->assertSame(1, LoopEventResponse::where('event_id', $event->id)->count());
        $this->assertSame(1, $this->service()->counts($event)['going']);
    }

    // ── Reponses ────────────────────────────────────────────────────────────

    public function test_the_three_answers_work(): void
    {
        $event = $this->event();

        foreach ([LoopEventResponse::GOING, LoopEventResponse::MAYBE, LoopEventResponse::NOT_GOING] as $answer) {
            $this->service()->respond($this->member, $event, $this->loop, $answer);
            $this->assertSame($answer, $this->service()->responseOf($this->member, $event));
        }

        // Trois appels, une seule reponse : on change d'avis, on n'en ajoute pas.
        $this->assertSame(1, LoopEventResponse::where('event_id', $event->id)->count());
    }

    public function test_an_unknown_answer_is_refused(): void
    {
        $event = $this->event();

        $this->expectException(EventException::class);
        $this->service()->respond($this->member, $event, $this->loop, 'peut_etre_bien');
    }

    public function test_a_cancelled_event_accepts_no_answer(): void
    {
        $event = $this->event($this->member);
        $this->service()->cancel($this->member, $event, $this->loop);

        $this->expectException(EventException::class);
        $this->service()->respond($this->other, $event->fresh(), $this->loop, LoopEventResponse::GOING);
    }

    public function test_the_counts_split_the_three_answers(): void
    {
        $event = $this->event($this->owner);

        $this->service()->respond($this->member, $event, $this->loop, LoopEventResponse::GOING);
        $this->service()->respond($this->other, $event, $this->loop, LoopEventResponse::MAYBE);
        $this->service()->respond($this->facilitator, $event, $this->loop, LoopEventResponse::NOT_GOING);

        $this->assertSame(
            ['going' => 1, 'maybe' => 1, 'not_going' => 1],
            $this->service()->counts($event),
        );
    }

    public function test_the_attendee_list_names_who_answered_what(): void
    {
        $event = $this->event($this->owner);
        $this->service()->respond($this->member, $event, $this->loop, LoopEventResponse::GOING);

        $names = $this->service()->respondents($event);

        $this->assertSame([$this->member->publicDisplayName()], $names[LoopEventResponse::GOING]);
        $this->assertSame([], $names[LoopEventResponse::MAYBE]);
    }

    public function test_an_organization_wide_event_is_answerable_by_a_non_member(): void
    {
        $outsider = $this->userInOrg();
        $event = $this->event($this->owner, ['visibility' => LoopEvent::VISIBILITY_ORGANIZATION]);

        // C'est le sens meme de l'avoir remonte.
        $this->assertTrue($this->service()->canRespondTo($outsider, $event, $this->loop));
        $this->service()->respond($outsider, $event, $this->loop, LoopEventResponse::GOING);

        $this->assertSame(1, $this->service()->counts($event)['going']);
    }

    public function test_a_loop_scoped_event_is_not_answerable_by_a_non_member(): void
    {
        $outsider = $this->userInOrg();
        $event = $this->event($this->owner);

        $this->assertFalse($this->service()->canRespondTo($outsider, $event, $this->loop));
    }

    // ── Modification ────────────────────────────────────────────────────────

    public function test_an_event_with_answers_stays_editable_and_keeps_them(): void
    {
        $event = $this->event($this->member);
        $this->service()->respond($this->other, $event, $this->loop, LoopEventResponse::GOING);

        $result = $this->service()->update($this->member, $event, $this->loop, $this->payload([
            'title' => 'Reunion deplacee',
            'starts_at' => CarbonImmutable::now()->addDays(5)->format('Y-m-d\TH:i'),
        ]));

        // Reinitialiser les participants leur retirerait une parole donnee.
        $this->assertSame('Reunion deplacee', $result['event']->title);
        $this->assertSame(1, $this->service()->counts($event)['going']);
    }

    public function test_moving_the_date_is_worth_announcing_but_a_typo_is_not(): void
    {
        $event = $this->event($this->member);

        $renamed = $this->service()->update($this->member, $event, $this->loop, $this->payload([
            'title' => 'Autre titre',
        ]));
        $this->assertFalse($renamed['notable']);

        $moved = $this->service()->update($this->member, $event->fresh(), $this->loop, $this->payload([
            'title' => 'Autre titre',
            'starts_at' => CarbonImmutable::now()->addDays(9)->format('Y-m-d\TH:i'),
        ]));
        $this->assertTrue($moved['notable']);
    }

    public function test_another_member_may_not_edit_someone_elses_event(): void
    {
        $event = $this->event($this->member);

        $this->assertFalse($this->service()->canManageEvent($this->other, $event, $this->loop));
        $this->assertTrue($this->service()->canManageEvent($this->owner, $event, $this->loop));
        $this->assertTrue($this->service()->canManageEvent($this->facilitator, $event, $this->loop));
        $this->assertTrue($this->service()->canManageEvent($this->member, $event, $this->loop));
    }

    public function test_a_cancelled_event_is_no_longer_edited(): void
    {
        $event = $this->event($this->member);
        $this->service()->cancel($this->member, $event, $this->loop);

        $this->expectException(EventException::class);
        $this->service()->update($this->member, $event->fresh(), $this->loop, $this->payload());
    }

    // ── Annulation et suppression ───────────────────────────────────────────

    public function test_an_event_without_answers_can_be_deleted(): void
    {
        $event = $this->event($this->member);
        $this->service()->delete($this->member, $event, $this->loop);

        $this->assertDatabaseMissing('loop_events', ['id' => $event->id]);
    }

    public function test_an_event_with_an_answer_is_never_deleted(): void
    {
        $event = $this->event($this->member);
        $this->service()->respond($this->other, $event, $this->loop, LoopEventResponse::GOING);

        try {
            $this->service()->delete($this->member, $event, $this->loop);
            $this->fail('La suppression aurait dû être refusée.');
        } catch (EventException) {
            // Effacer effacerait la parole de quelqu'un d'autre.
        }

        $this->assertDatabaseHas('loop_events', ['id' => $event->id]);
    }

    public function test_cancelling_keeps_the_history(): void
    {
        $event = $this->event($this->member);
        $this->service()->respond($this->other, $event, $this->loop, LoopEventResponse::GOING);

        $this->service()->cancel($this->member, $event, $this->loop);
        $fresh = $event->fresh();

        $this->assertTrue($fresh->isCancelled());
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame($this->member->id, $fresh->cancelled_by);
        $this->assertSame(1, $this->service()->counts($fresh)['going']);
    }

    public function test_cancelling_twice_is_not_an_error_and_announces_once(): void
    {
        $event = $this->event($this->member);

        $first = $this->service()->cancel($this->member, $event, $this->loop);
        $second = $this->service()->cancel($this->member, $event->fresh(), $this->loop);

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
    }

    public function test_another_member_may_not_cancel(): void
    {
        $event = $this->event($this->member);

        $this->expectException(EventException::class);
        $this->service()->cancel($this->other, $event, $this->loop);
    }

    // ── Depart d'un membre ──────────────────────────────────────────────────

    public function test_a_departed_member_keeps_their_answer_in_the_history(): void
    {
        $event = $this->event($this->owner);
        $this->service()->respond($this->other, $event, $this->loop, LoopEventResponse::GOING);

        LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $this->other->id)
            ->update(['status' => 'left']);

        $this->assertSame(1, $this->service()->counts($event)['going']);
    }

    public function test_an_event_survives_the_departure_of_its_author(): void
    {
        $event = $this->event($this->member);
        $this->member->delete();

        $fresh = LoopEvent::find($event->id);

        $this->assertNotNull($fresh);
        $this->assertNull($fresh->created_by);
    }

    // ── Agenda de l'Organization ────────────────────────────────────────────

    public function test_the_agenda_shows_the_events_of_my_own_loops(): void
    {
        $event = $this->event($this->owner);

        $ids = $this->service()->agendaFor($this->member, $this->org->id)->pluck('id');

        $this->assertContains($event->id, $ids);
    }

    public function test_the_agenda_hides_a_loop_event_of_a_loop_i_am_not_in(): void
    {
        $foreignLoop = $this->makeLoop('Boucle sans moi');
        $this->join($foreignLoop, $this->owner, 'owner');
        $hidden = $this->event($this->owner, loop: $foreignLoop);

        $ids = $this->service()->agendaFor($this->member, $this->org->id)->pluck('id');

        // Le test qui compte : connaitre l'identifiant ne suffit pas.
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_the_agenda_shows_an_organization_wide_event_of_a_loop_i_am_not_in(): void
    {
        $otherLoop = $this->makeLoop('Autre Boucle');
        $this->join($otherLoop, $this->owner, 'owner');
        $shared = $this->event($this->owner, ['visibility' => LoopEvent::VISIBILITY_ORGANIZATION], $otherLoop);

        $ids = $this->service()->agendaFor($this->member, $this->org->id)->pluck('id');

        $this->assertContains($shared->id, $ids);
    }

    public function test_the_agenda_never_crosses_organizations(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $mine = $this->event($this->owner, ['visibility' => LoopEvent::VISIBILITY_ORGANIZATION]);

        $ids = $this->service()->agendaFor($stranger, $otherOrg->id)->pluck('id');

        $this->assertNotContains($mine->id, $ids);
    }

    public function test_the_agenda_page_renders_for_a_member(): void
    {
        $event = $this->event($this->owner);

        $this->actingAs($this->member)
            ->get(route('organization.events.agenda', ['organization' => $this->org->slug]))
            ->assertOk()
            ->assertSee($event->title);
    }

    public function test_the_agenda_page_refuses_another_organization(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->actingAs($stranger)
            ->get(route('organization.events.agenda', ['organization' => $this->org->slug]))
            ->assertNotFound();
    }

    public function test_the_agenda_page_filters_by_loop(): void
    {
        $otherLoop = $this->makeLoop('Seconde Boucle');
        $this->join($otherLoop, $this->member, 'member');

        $mine = $this->event($this->member, ['title' => 'Dans la premiere']);
        $this->event($this->member, ['title' => 'Dans la seconde'], $otherLoop);

        $this->actingAs($this->member)
            ->get(route('organization.events.agenda', [
                'organization' => $this->org->slug, 'loop' => $this->loop->id,
            ]))
            ->assertOk()
            ->assertSee('Dans la premiere')
            ->assertDontSee('Dans la seconde');
    }

    // ── Calendrier ──────────────────────────────────────────────────────────

    public function test_the_month_grid_always_has_six_weeks_of_seven_days(): void
    {
        $grid = app(LoopEventPresenter::class)->monthGrid('2026-08', []);

        $this->assertCount(6, $grid['weeks']);
        foreach ($grid['weeks'] as $week) {
            $this->assertCount(7, $week);
        }
    }

    public function test_an_event_lands_on_its_local_day_in_the_grid(): void
    {
        // 00h30 a Paris, c'est encore la veille en UTC. La grille doit suivre le
        // fuseau de l'Evenement, pas celui du serveur.
        $event = $this->event(overrides: ['starts_at' => '2026-08-12T00:30', 'timezone' => 'Europe/Paris']);

        $presenter = app(LoopEventPresenter::class);
        $presented = $presenter->present($event, $this->member, $this->loop);
        $grid = $presenter->monthGrid('2026-08', [$presented]);

        $found = null;
        foreach ($grid['weeks'] as $week) {
            foreach ($week as $day) {
                if ($day['events'] !== []) {
                    $found = $day;
                }
            }
        }

        $this->assertNotNull($found);
        $this->assertSame('2026-08-12', $found['date']->format('Y-m-d'));
    }

    // ── ChatLoop ────────────────────────────────────────────────────────────

    public function test_proposing_an_event_announces_it_in_chatloop(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('title', 'Cafe du mardi')
            ->set('location', 'Le comptoir')
            ->call('save');

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'loop_event')->first();

        $this->assertNotNull($message);
        $this->assertSame('created', $message->metadata['event']);
        $this->assertStringContainsString('Cafe du mardi', $message->body);
        $this->assertArrayHasKey('starts_at', $message->metadata);
        $this->assertArrayHasKey('timezone', $message->metadata);
    }

    public function test_cancelling_announces_it(): void
    {
        $event = $this->event($this->member);

        Livewire::actingAs($this->member)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('cancelEvent', $event->id);

        $cancelled = LoopMessage::where('loop_id', $this->loop->id)
            ->where('type', 'loop_event')
            ->get()
            ->filter(fn ($m) => ($m->metadata['event'] ?? null) === 'cancelled');

        $this->assertCount(1, $cancelled);
    }

    public function test_answering_announces_nothing(): void
    {
        $event = $this->event($this->owner);
        $before = LoopMessage::where('loop_id', $this->loop->id)->count();

        $this->service()->respond($this->member, $event, $this->loop, LoopEventResponse::GOING);

        // Une reunion a dix n'a pas besoin de dix lignes dans la conversation.
        $this->assertSame($before, LoopMessage::where('loop_id', $this->loop->id)->count());
    }

    // ── Card desactivee et Boucle archivee ──────────────────────────────────

    public function test_disabling_the_card_blocks_writing_but_keeps_the_events(): void
    {
        $event = $this->event();

        app(LoopCardCompositionService::class)->disable($this->loop, 'core.events');
        $loop = $this->loop->fresh();

        $this->assertFalse($this->service()->canView($this->member, $loop));
        $this->assertFalse($this->service()->canCreate($this->member, $loop));
        $this->assertFalse($this->service()->canRespond($this->member, $loop));
        $this->assertDatabaseHas('loop_events', ['id' => $event->id]);
    }

    public function test_a_direct_write_is_refused_when_the_card_is_off(): void
    {
        app(LoopCardCompositionService::class)->disable($this->loop, 'core.events');

        $this->expectException(EventException::class);
        $this->service()->create($this->member, $this->loop->fresh(), $this->payload());
    }

    public function test_re_enabling_the_card_finds_the_events_again(): void
    {
        $event = $this->event();
        $composition = app(LoopCardCompositionService::class);

        $composition->disable($this->loop, 'core.events');
        $composition->enable($this->loop->fresh(), 'core.events');

        $this->assertTrue($this->service()->canView($this->member, $this->loop->fresh()));
        $this->assertSame($event->id, LoopEvent::where('loop_id', $this->loop->id)->first()->id);
    }

    public function test_an_archived_loop_keeps_its_events_readable(): void
    {
        $event = $this->event();
        app(LoopLifecycleService::class)->archive($this->owner, $this->loop);

        $this->assertTrue($this->service()->canView($this->member, $this->loop->fresh()));
        $this->assertDatabaseHas('loop_events', ['id' => $event->id]);
    }

    public function test_an_archived_loop_accepts_no_event_writing(): void
    {
        $event = $this->event();
        app(LoopLifecycleService::class)->archive($this->owner, $this->loop);
        $loop = $this->loop->fresh();

        $this->assertFalse($this->service()->canCreate($this->member, $loop));
        $this->assertFalse($this->service()->canRespond($this->member, $loop));
        $this->assertFalse($this->service()->canRespondTo($this->member, $event, $loop));

        $this->expectException(EventException::class);
        $this->service()->respond($this->member, $event, $loop, LoopEventResponse::GOING);
    }

    public function test_reactivating_finds_the_events_exactly_as_they_were(): void
    {
        $event = $this->event($this->owner);
        $this->service()->respond($this->member, $event, $this->loop, LoopEventResponse::GOING);

        $lifecycle = app(LoopLifecycleService::class);
        $lifecycle->archive($this->owner, $this->loop);
        $lifecycle->reactivate($this->owner, $this->loop->fresh());

        $fresh = LoopEvent::find($event->id);

        $this->assertTrue($fresh->isScheduled());
        $this->assertSame(1, $this->service()->counts($fresh)['going']);
        $this->assertTrue($this->service()->canRespond($this->member, $this->loop->fresh()));
    }

    // ── Registre et socle ───────────────────────────────────────────────────

    public function test_the_card_is_declared_once_and_renders(): void
    {
        $registry = app(LoopCardRegistry::class);

        $this->assertTrue($registry->exists('core.events'));
        $this->assertTrue($registry->isRenderable('core.events'));
        $this->assertSame('loop-events-card', $registry->componentFor('core.events'));
        $this->assertSame('events.view', $registry->viewPermissionFor('core.events'));
        $this->assertFalse($registry->isRequired('core.events'));
    }

    public function test_the_administration_offers_the_card_and_counts_its_events(): void
    {
        $this->event();
        $this->event();

        $this->assertContains('core.events', app(LoopCardRegistry::class)->manageableKeys());

        $composition = collect(app(LoopCardCompositionService::class)->compositionFor($this->loop))
            ->firstWhere('key', 'core.events');

        $this->assertNotNull($composition);
        $this->assertTrue($composition['enabled']);
        $this->assertSame(2, $composition['data_count']);
    }

    public function test_the_dialogue_preset_carries_the_card(): void
    {
        // « general » EST le type Dialogue.
        $this->assertContains('core.events', app(LoopTypeRegistry::class)->cardsFor('general'));
        $this->assertNotContains('core.events', app(LoopTypeRegistry::class)->cardsFor('project'));
    }

    public function test_the_preset_synchronisation_is_additive_and_idempotent(): void
    {
        $bare = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Sans preset', 'slug' => 'sans-preset-'.uniqid(),
            'type' => 'general', 'status' => 'active', 'visibility' => 'public',
            'created_by' => $this->owner->id,
        ]);

        $sync = app(LoopPresetSyncService::class);
        $sync->sync('general');

        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $bare->id, 'card_key' => 'core.events', 'enabled' => true,
        ]);

        $this->assertSame(0, $sync->sync('general')['loops_affected']);
    }

    public function test_a_locally_disabled_event_card_is_never_relit_by_the_preset(): void
    {
        app(LoopCardCompositionService::class)->disable($this->loop, 'core.events');

        app(LoopPresetSyncService::class)->sync('general');

        $this->assertDatabaseHas('loop_cards', [
            'loop_id' => $this->loop->id, 'card_key' => 'core.events', 'enabled' => false,
        ]);
    }

    public function test_the_workspace_offers_the_card_to_a_member(): void
    {
        $keys = app(LoopCardRegistry::class)
            ->workspaceCardsFor($this->loop->fresh(), $this->member)
            ->pluck('key')->all();

        $this->assertContains('core.events', $keys);
    }

    // ── Composant ───────────────────────────────────────────────────────────

    public function test_the_card_separates_upcoming_from_past_and_cancelled(): void
    {
        $upcoming = $this->event($this->member, ['title' => 'Bientot']);
        $cancelled = $this->event($this->member, ['title' => 'Annule']);
        $this->service()->cancel($this->member, $cancelled, $this->loop);

        $component = Livewire::actingAs($this->member)
            ->test(LoopEventsCard::class, ['loop' => $this->loop]);

        $this->assertSame([$upcoming->id], $component->viewData('upcoming')->pluck('id')->all());
        $this->assertSame([$cancelled->id], $component->viewData('past')->pluck('id')->all());
    }

    public function test_the_card_shows_the_refusal_instead_of_failing_silently(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('openCreateForm')
            ->set('title', '')
            ->call('save')
            ->assertSet('errorMessage', __('events.error_title_required'));
    }

    public function test_a_member_answers_through_the_card(): void
    {
        $event = $this->event($this->owner);

        Livewire::actingAs($this->member)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('respond', $event->id, LoopEventResponse::GOING)
            ->assertSet('errorMessage', null);

        $this->assertSame(1, $this->service()->counts($event)['going']);
    }

    public function test_the_card_of_a_stranger_lists_nothing(): void
    {
        $otherOrg = Organization::factory()->create(['is_active' => true]);
        $stranger = User::factory()->create(['organization_id' => $otherOrg->id]);

        $this->event();

        $component = Livewire::actingAs($stranger)
            ->test(LoopEventsCard::class, ['loop' => $this->loop]);

        $this->assertCount(0, $component->viewData('upcoming'));
        $this->assertCount(0, $component->viewData('past'));
    }

    public function test_the_month_navigation_moves_the_grid(): void
    {
        Livewire::actingAs($this->member)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->set('month', '2026-08')
            ->call('shiftMonth', 1)
            ->assertSet('month', '2026-09')
            ->call('shiftMonth', -2)
            ->assertSet('month', '2026-07');
    }
}
