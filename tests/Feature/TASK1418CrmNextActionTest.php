<?php

namespace Tests\Feature;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmNextActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1418 — CRM-6, la prochaine action.
 *
 * - une action a la fois, portee par le Contact ; planifier remplace, marquer
 *   faite efface ; chaque geste laisse un fait, aucun ne touche le dernier
 *   contact ;
 * - « Aujourd'hui / En retard / Cette semaine » se mesurent AUX BORNES, sur
 *   un mercredi fige ;
 * - tenant : planifier ou terminer sur un Contact d'ailleurs = 404, rien
 *   d'ecrit ; un acteur d'ailleurs est refuse par le service.
 */
class TASK1418CrmNextActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    private User $adminB;

    private CrmContact $contactA;

    private CrmContact $contactB;

    private CrmNextActionService $actions;

    protected function setUp(): void
    {
        parent::setUp();

        // Mercredi 9 septembre 2026, 10h : la semaine ISO finit dimanche 13.
        $this->travelTo(Carbon::parse('2026-09-09 10:00:00'));

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1418', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1418', 'is_active' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
        $this->orgB->update(['admin_id' => $this->adminB->id]);

        $contacts = app(CrmContactService::class);
        $this->contactA = $contacts->findOrCreate($this->orgA, ['first_name' => 'Zorglub', 'last_name' => 'Amaranthe', 'email' => 'zorglub@example.com']);
        $this->contactB = $contacts->findOrCreate($this->orgB, ['first_name' => 'Quixotic', 'last_name' => 'Bellwether', 'email' => 'quixotic@example.com']);
        $this->actions = app(CrmNextActionService::class);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    private function list(array $query = []): string
    {
        return route('organization.admin.crm.contacts', ['organization' => $this->orgA->slug] + $query);
    }

    // ── 1. Planifier / marquer faite ────────────────────────────────────────

    public function test_planning_sets_the_action_writes_a_fact_and_leaves_the_last_contact_alone(): void
    {
        $event = $this->actions->plan($this->contactA, 'quote', Carbon::parse('2026-09-11'), '14:30', '  Envoyer le devis atelier  ', $this->adminA);

        $fresh = $this->contactA->fresh();
        $this->assertTrue($fresh->hasNextAction());
        $this->assertSame('quote', $fresh->next_action_type);
        $this->assertSame('2026-09-11', $fresh->next_action_date->format('Y-m-d'));
        $this->assertSame('14:30', $fresh->nextActionTime());
        $this->assertSame('11/09 · 14:30', $fresh->nextActionDueLabel());
        $this->assertSame('Envoyer le devis atelier', $fresh->next_action_label);
        $this->assertFalse($fresh->isNextActionOverdue());
        $this->assertNull($fresh->last_interaction_at, 'planifier n est pas un contact');

        $this->assertSame(CrmContactEvent::TYPE_NEXT_ACTION_PLANNED, $event->type);
        $this->assertSame($this->adminA->id, $event->author_user_id);
        $this->assertSame('quote', $event->payload['action_type']);
        $this->assertSame('2026-09-11', $event->payload['date']);
        $this->assertSame('14:30', $event->payload['time']);
        $this->assertSame('Envoyer le devis atelier', $event->payload['label']);

        // Replanifier REMPLACE (une seule action a la fois) et laisse un second fait.
        $this->actions->plan($this->contactA, 'call', Carbon::parse('2026-09-08'), null, null, $this->adminA);
        $fresh = $this->contactA->fresh();
        $this->assertSame('call', $fresh->next_action_type);
        $this->assertNull($fresh->next_action_label);
        $this->assertNull($fresh->nextActionTime(), 'sans heure = null, jamais 00:00');
        $this->assertTrue($fresh->isNextActionOverdue(), 'hier = en retard');

        // Aujourd'hui avec une heure deja passee : PAS en retard (V1, journee commerciale).
        $this->actions->plan($this->contactA, 'call', Carbon::parse('2026-09-09'), '08:00', null, $this->adminA);
        $this->assertFalse($this->contactA->fresh()->isNextActionOverdue());
        $this->assertSame(__('crm.next_action.today').' · 08:00', $this->contactA->fresh()->nextActionDueLabel());
        $this->assertSame(3, $this->contactA->events()->where('type', CrmContactEvent::TYPE_NEXT_ACTION_PLANNED)->count());

        try {
            $this->actions->plan($this->contactA, 'call', Carbon::parse('2026-09-11'), '25:99', null, $this->adminA);
            $this->fail('heure invalide refusee');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $this->actions->plan($this->contactA, 'pigeon', Carbon::parse('2026-09-11'), null, null, $this->adminA);
    }

    public function test_completing_clears_the_action_and_records_what_was_planned(): void
    {
        $this->assertNull($this->actions->complete($this->contactA, $this->adminA), 'rien de prevu : rien n est ecrit');
        $this->assertSame(0, $this->contactA->events()->count());

        $this->actions->plan($this->contactA, 'meeting', Carbon::parse('2026-09-10'), '09:15', 'Cafe', $this->adminA);
        $done = $this->actions->complete($this->contactA, $this->adminA);

        $fresh = $this->contactA->fresh();
        $this->assertFalse($fresh->hasNextAction());
        $this->assertNull($fresh->next_action_type);
        $this->assertNull($fresh->next_action_date);
        $this->assertNull($fresh->next_action_time);
        $this->assertNull($fresh->next_action_label);
        $this->assertNull($fresh->last_interaction_at);

        $this->assertSame(CrmContactEvent::TYPE_NEXT_ACTION_DONE, $done->type);
        $this->assertSame('meeting', $done->payload['action_type']);
        $this->assertSame('2026-09-10', $done->payload['date']);
        $this->assertSame('09:15', $done->payload['time']);
        $this->assertSame('Cafe', $done->payload['label']);
        $this->assertSame(['next_action_planned', 'next_action_done'], $this->contactA->events()->chronological()->pluck('type')->all());
    }

    // ── 2. Tenant ───────────────────────────────────────────────────────────

    public function test_an_actor_of_another_organization_is_refused_by_the_service(): void
    {
        try {
            $this->actions->plan($this->contactA, 'call', Carbon::parse('2026-09-11'), null, null, $this->adminB);
            $this->fail('acteur d une autre Organization refuse');
        } catch (LogicException) {
        }

        $this->assertFalse($this->contactA->fresh()->hasNextAction());
        $this->assertSame(0, $this->contactA->events()->count());
    }

    public function test_planning_or_completing_a_contact_of_another_organization_is_a_404_and_writes_nothing(): void
    {
        $this->actions->plan($this->contactB, 'call', Carbon::parse('2026-09-11'), null, null, $this->adminB);

        $this->actingAs($this->adminA)
            ->post(route('organization.admin.crm.contacts.next-action.plan', ['organization' => $this->orgA->slug, 'contact' => $this->contactB->id]), ['next_action_type' => 'sms', 'next_action_date' => '2026-09-12'])
            ->assertNotFound();
        $this->actingAs($this->adminA)
            ->post(route('organization.admin.crm.contacts.next-action.complete', ['organization' => $this->orgA->slug, 'contact' => $this->contactB->id]))
            ->assertNotFound();

        $fresh = $this->contactB->fresh();
        $this->assertSame('call', $fresh->next_action_type);
        $this->assertSame(1, $this->contactB->events()->count());
    }

    // ── 3. Aujourd'hui / En retard / Cette semaine, aux bornes ──────────────

    public function test_due_filters_measure_exact_boundaries(): void
    {
        $contacts = app(CrmContactService::class);
        $mk = function (string $name, ?string $due) use ($contacts) {
            $c = $contacts->findOrCreate($this->orgA, ['first_name' => $name, 'last_name' => 'Borne', 'email' => strtolower($name).'@example.com']);
            if ($due !== null) {
                $this->actions->plan($c, 'call', Carbon::parse($due), null, null, $this->adminA);
            }

            return $c;
        };
        $mk('Hier', '2026-09-08');          // en retard
        $mk('Cejour', '2026-09-09');        // aujourd'hui (et cette semaine)
        $mk('Dimanche', '2026-09-13');      // cette semaine (derniere borne)
        $mk('Lundi', '2026-09-14');         // semaine suivante
        $mk('Rien', null);                  // rien de prevu

        $this->actingAs($this->adminA)->get($this->list(['due' => 'today']))
            ->assertOk()->assertSee('Cejour')->assertDontSee('Hier')->assertDontSee('Dimanche')->assertDontSee('Lundi')->assertDontSee('Rien Borne');
        $this->actingAs($this->adminA)->get($this->list(['due' => 'overdue']))
            ->assertOk()->assertSee('Hier')->assertDontSee('Cejour')->assertDontSee('Dimanche')->assertDontSee('Rien Borne');
        $this->actingAs($this->adminA)->get($this->list(['due' => 'week']))
            ->assertOk()->assertSee('Cejour')->assertSee('Dimanche')->assertDontSee('Hier')->assertDontSee('Lundi')->assertDontSee('Rien Borne');

        // Sans filtre d'echeance, tout le monde est la, et « Hier » porte le marqueur de retard.
        $this->actingAs($this->adminA)->get($this->list())
            ->assertOk()->assertSee('Hier')->assertSee('Rien Borne')->assertSee('data-crm-overdue', false);

        // Un parametre inconnu ne filtre rien.
        $this->actingAs($this->adminA)->get($this->list(['due' => 'jamais']))
            ->assertOk()->assertSee('Lundi')->assertSee('Rien Borne');
    }

    public function test_with_a_due_filter_the_list_is_ordered_by_due_date(): void
    {
        $contacts = app(CrmContactService::class);
        $late = $contacts->findOrCreate($this->orgA, ['first_name' => 'Tardif', 'last_name' => 'Ordre', 'email' => 'tardif@example.com']);
        $soon = $contacts->findOrCreate($this->orgA, ['first_name' => 'Proche', 'last_name' => 'Ordre', 'email' => 'proche@example.com']);
        $this->actions->plan($late, 'call', Carbon::parse('2026-09-12'), null, null, $this->adminA);
        $this->actions->plan($soon, 'call', Carbon::parse('2026-09-10'), null, null, $this->adminA);

        $html = $this->actingAs($this->adminA)->get($this->list(['due' => 'week']))->assertOk()->getContent();
        $this->assertTrue(strpos($html, 'Proche Ordre') < strpos($html, 'Tardif Ordre'));
    }

    // ── 4. Surfaces ─────────────────────────────────────────────────────────

    public function test_the_page_shows_the_action_and_the_forms_and_http_actions_leave_their_trace(): void
    {
        $show = route('organization.admin.crm.contacts.show', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]);
        $plan = route('organization.admin.crm.contacts.next-action.plan', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]);
        $complete = route('organization.admin.crm.contacts.next-action.complete', ['organization' => $this->orgA->slug, 'contact' => $this->contactA->id]);

        $this->actingAs($this->adminA)->get($show)->assertOk()
            ->assertSee('data-crm-next-action-none', false)->assertSee('data-crm-next-action-form', false);

        $this->actingAs($this->adminA)->from($show)
            ->post($plan, ['next_action_type' => 'quote', 'next_action_date' => '2026-09-08', 'next_action_time' => '16:00', 'next_action_label' => 'Devis'])
            ->assertSessionHasNoErrors()->assertRedirect($show)->assertSessionHas('success', __('crm.flash_next_action_planned'));

        $this->actingAs($this->adminA)->get($show)->assertOk()
            ->assertSee('data-crm-next-action-current', false)
            ->assertSee(__('crm.action_type.quote'))
            ->assertSee('data-crm-overdue', false)
            ->assertSee('08/09 · 16:00')
            ->assertSee('Devis')
            ->assertSee('data-crm-next-action-done', false)
            ->assertSee('data-crm-event="next_action_planned"', false);

        $this->actingAs($this->adminA)->from($show)->post($complete)
            ->assertRedirect($show)->assertSessionHas('success', __('crm.flash_next_action_done'));
        $this->assertFalse($this->contactA->fresh()->hasNextAction());
        $this->actingAs($this->adminA)->get($show)->assertOk()
            ->assertSee('data-crm-next-action-none', false)->assertSee('data-crm-event="next_action_done"', false);

        $this->actingAs($this->adminA)->from($show)->post($complete)
            ->assertSessionHas('success', __('crm.flash_next_action_nothing'));

        $this->actingAs($this->adminA)->from($show)->post($plan, ['next_action_type' => 'pigeon', 'next_action_date' => 'pas-une-date', 'next_action_time' => '9h'])
            ->assertSessionHasErrors(['next_action_type', 'next_action_date', 'next_action_time']);
    }

    public function test_the_list_shows_the_next_action_column_the_due_filter_and_the_quick_plan_form(): void
    {
        $this->actions->plan($this->contactA, 'whatsapp', Carbon::parse('2026-09-11'), null, 'Relance', $this->adminA);

        $this->actingAs($this->adminA)->get($this->list())->assertOk()
            ->assertSee('data-crm-due-filter', false)
            ->assertSee('data-crm-next-action-cell', false)
            ->assertSee(__('crm.action_type.whatsapp'))
            ->assertSee('11/09')
            ->assertSee('Relance')
            ->assertSee('data-crm-plan-form', false)
            ->assertDontSee('data-crm-overdue');
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    public function test_the_probe_distinguishes_overdue_from_upcoming(): void
    {
        $this->actions->plan($this->contactA, 'call', Carbon::parse('2026-09-08'), null, null, $this->adminA);
        $this->actions->plan($this->contactB, 'call', Carbon::parse('2026-09-10'), null, null, $this->adminB);
        $this->assertSame(__('crm.next_action.tomorrow'), $this->contactB->fresh()->nextActionDueLabel());

        $this->assertTrue($this->contactA->fresh()->isNextActionOverdue());
        $this->assertFalse($this->contactB->fresh()->isNextActionOverdue());
    }
}
