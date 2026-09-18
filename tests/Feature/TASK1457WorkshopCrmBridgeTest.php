<?php

namespace Tests\Feature;

use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\Crm\CrmContactService;
use App\Services\Crm\CrmTimelineService;
use App\Services\Crm\WorkshopCrmBridge;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopRegistrationService;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1457 — Workshop → CRM + F0 (Growth V3 §15, Mini-CRM V2 §6/§10,
 * audit OPUS F0, MASTER pulse #47) :
 *  1. F0 : `recordOnceByKey()` — un fait REPETABLE identifie par (contact, cle)
 *     jamais par type seul : atelier A puis B = deux faits ; meme inscription
 *     rejouee = un fait ; `recordOnce()` inchange ; `dedupe_key` unique en base ;
 *  2. le bridge a la participation confirmee : Contact retrouve ou cree dans la
 *     MEME Organization (`source = workshop`, `source_ref = session`), lie au
 *     User, timeline `workshop_participation_confirmed` avec la provenance
 *     (Journey exacte, campagne, shortcut), fait `crm_contact_linked` au journal ;
 *     idempotent ; aucun doublon Contact/User ; aucun transcript Guest ;
 *  3. tenant : un User d'une autre Organization ne cree rien ; un Contact
 *     existant (email) est reutilise, jamais duplique ; un Contact deja lie a un
 *     autre User n'est pas vole (LogicException, rien d'ecrit) ;
 *  4. la telemetrie CRM ne casse jamais l'inscription (bridge en panne →
 *     Registration quand meme).
 */
class TASK1457WorkshopCrmBridgeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private Workshop $wa;

    private WorkshopSession $s1;

    private WorkshopSession $s2;

    private AcquisitionJourney $journey;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');
        $this->a = Organization::factory()->create(['slug' => 'org-a-1457', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-1457', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);

        $journeys = app(AcquisitionJourneyService::class);
        $this->journey = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION, 'campaign' => 'sept-2026'], $this->adminA);
        $journeys->publish($this->journey, $this->adminA);

        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $this->wa = $workshops->create($this->a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($this->wa, $this->adminA);
        $this->s1 = $sessions->create($this->wa, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $sessions->publish($this->s1, $this->adminA);
        $wb = $workshops->create($this->a, ['title' => 'Aller plus loin', 'slug' => 'ia-avance', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($wb, $this->adminA);
        $this->s2 = $sessions->create($wb, ['starts_at' => '2026-10-15 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $sessions->publish($this->s2, $this->adminA);
    }

    private function member(string $email, string $name = 'Membre Un'): User
    {
        return User::factory()->create(['organization_id' => $this->a->id, 'name' => $name, 'first_name' => 'Prénom', 'email' => $email, 'email_verified_at' => now()]);
    }

    // ── 1. F0 ──────────────────────────────────────────────────────────────

    public function test_f0_a_repeatable_timeline_fact_is_identified_by_its_key_never_by_type_alone(): void
    {
        $this->assertTrue(Schema::hasColumn('crm_contact_events', 'dedupe_key'));
        $contact = app(CrmContactService::class)->findOrCreate($this->a, ['email' => 'un@example.test', 'first_name' => 'Un'], $this->adminA);
        $timeline = app(CrmTimelineService::class);

        $first = $timeline->recordOnceByKey($contact, CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED, 'workshop_participation_confirmed:reg-A', ['workshop_session_id' => 'A']);
        $second = $timeline->recordOnceByKey($contact, CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED, 'workshop_participation_confirmed:reg-B', ['workshop_session_id' => 'B']);
        $replay = $timeline->recordOnceByKey($contact, CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED, 'workshop_participation_confirmed:reg-A', ['workshop_session_id' => 'A']);
        $this->assertNotNull($first);
        $this->assertNotNull($second, 'atelier A puis atelier B = deux faits');
        $this->assertNull($replay, 'la meme inscription rejouee = un seul fait');
        $this->assertSame(2, $contact->events()->where('type', CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED)->count());
        $this->assertSame($contact->id.':workshop_participation_confirmed:reg-A', $first->dedupe_key);

        // recordOnce() est inchange : un type non repetable garde sa regle « type seul », et l'inverse est refuse.
        $this->assertNotNull($timeline->recordOnce($contact, CrmContactEvent::TYPE_ACCOUNT_CREATED, []));
        $this->assertNull($timeline->recordOnce($contact, CrmContactEvent::TYPE_ACCOUNT_CREATED, []));
        try {
            $timeline->recordOnceByKey($contact, CrmContactEvent::TYPE_ACCOUNT_CREATED, 'x');
            $this->fail('un fait unique a vie ne passe pas par la cle');
        } catch (LogicException) {
        }
        try {
            $timeline->recordOnce($contact, CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED, []);
            $this->fail('un fait repetable exige sa cle');
        } catch (LogicException) {
        }
        try {
            $timeline->recordOnceByKey($contact, CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED, '', []);
            $this->fail('cle vide');
        } catch (LogicException) {
        }
        // Les faits historiques sans cle restent intacts (NULL n'est pas unique).
        $this->assertSame(1, $contact->events()->whereNull('dedupe_key')->count());
    }

    // ── 2. Le bridge a la participation confirmee ──────────────────────────

    public function test_the_confirmed_participation_finds_or_creates_and_links_the_contact_with_its_provenance_idempotently(): void
    {
        $member = $this->member('un@example.test');
        $visitor = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->a, ['acquisition_journey_id' => $this->journey->id, 'utm_campaign' => 'sept-2026', 'shortcut' => 'demo']);
        $visitor->forceFill(['claimed_user_id' => $member->id, 'claimed_at' => now()])->save();
        // Le listener Registered (T1413) a deja cree/lie un Contact `signup` ? Ici non (pas de Registered) : le bridge cree.
        $this->assertSame(0, CrmContact::count());

        $registration = app(WorkshopRegistrationService::class)->register($this->s1, $member);

        $contact = CrmContact::query()->sole();
        $this->assertSame([$this->a->id, $member->id, 'un@example.test', CrmContact::SOURCE_WORKSHOP, $this->s1->id], [$contact->organization_id, $contact->user_id, $contact->email, $contact->source, $contact->source_ref]);
        $fact = $contact->events()->where('type', CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED)->get();
        $this->assertCount(1, $fact);
        $this->assertSame($contact->id.':workshop_participation_confirmed:'.$registration->id, $fact->first()->dedupe_key);
        $this->assertSame($this->journey->id, $fact->first()->payload['acquisition_journey_id'], 'la provenance : Journey exacte');
        $this->assertSame('sept-2026', $fact->first()->payload['utm_campaign']);
        $this->assertSame('demo', $fact->first()->payload['shortcut']);
        $this->assertNull($fact->first()->author_user_id, 'fait systeme');
        $this->assertStringNotContainsString('conversation', json_encode($fact->first()->payload), 'aucun transcript Guest dans le CRM');
        $linked = AcquisitionEvent::query()->where('event', AcquisitionEvent::CRM_CONTACT_LINKED)->sole();
        $this->assertSame([$member->id, $visitor->id, $this->journey->id], [$linked->user_id, $linked->guest_visitor_id, $linked->acquisition_journey_id]);

        // Rejeu de l'inscription (double POST) : un seul Contact, un seul fait, un seul evenement.
        app(WorkshopRegistrationService::class)->register($this->s1, $member);
        app(WorkshopCrmBridge::class)->onParticipationConfirmed($registration->fresh());
        $this->assertSame(1, CrmContact::count());
        $this->assertSame(1, $contact->events()->where('type', CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED)->count());
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::CRM_CONTACT_LINKED)->count());

        // Un second atelier : le MEME Contact, un second fait (timeline multi-workshop), source_ref inchange (premier moment).
        app(WorkshopRegistrationService::class)->register($this->s2, $member);
        $this->assertSame(1, CrmContact::count());
        $this->assertSame(2, $contact->events()->where('type', CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED)->count());
        $this->assertSame($this->s1->id, $contact->fresh()->source_ref, 'la provenance CRM d\'origine n\'est pas reecrite');

        // La timeline utile (V2 §10) : l'OrgAdmin lit le fait, l'atelier et la session sur la fiche Contact — sans jointure ni transcript.
        $page = $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', [$this->a, $contact]))->assertOk()->getContent();
        $this->assertStringContainsString('data-crm-event="workshop_participation_confirmed"', $page);
        $this->assertStringContainsString(__('crm.event.workshop_participation_confirmed'), $page);
        $this->assertStringContainsString('Découvrir l&#039;IA', $page, 'le titre de l\'atelier vient du payload');
        $this->assertStringContainsString('01/10/2026 18:30', $page, 'la session dans son fuseau');
        $this->assertStringNotContainsString('consent', $page, 'aucune notion de consentement deduite (MASTER #49)');
        $this->assertStringNotContainsString($fact->first()->dedupe_key, $page, 'la cle de dedup est une identite technique, jamais affichee (MASTER #50)');
    }

    // ── 3. Tenant et reutilisation ─────────────────────────────────────────

    public function test_the_bridge_reuses_an_existing_contact_never_duplicates_and_never_crosses_tenants(): void
    {
        // Un Contact manuel existe deja pour cet email (OrgAdmin l'a saisi) : il est reutilise et lie, ses champs ne sont pas ecrases.
        $existing = app(CrmContactService::class)->findOrCreate($this->a, ['email' => 'deja@example.test', 'first_name' => 'Saisi', 'last_name' => 'Par admin'], $this->adminA);
        $member = $this->member('deja@example.test', 'Membre Deja');
        app(WorkshopRegistrationService::class)->register($this->s1, $member);
        $this->assertSame(1, CrmContact::count());
        $this->assertSame([$member->id, 'Saisi', CrmContact::SOURCE_MANUAL], [$existing->fresh()->user_id, $existing->fresh()->first_name, $existing->fresh()->source], 'lie, jamais duplique, jamais ecrase');
        $this->assertSame(1, $existing->events()->where('type', CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED)->count());

        // Un Contact deja lie a un AUTRE User : refus (LogicException du CRM) sous rescue → l'inscription tient, aucun vol.
        $other = $this->member('autre@example.test', 'Autre');
        $stolen = app(CrmContactService::class)->findOrCreate($this->a, ['email' => 'vole@example.test'], $this->adminA);
        app(CrmContactService::class)->linkToUser($stolen, $other);
        $thief = User::factory()->create(['organization_id' => $this->a->id, 'email' => 'vole@example.test', 'email_verified_at' => now()]);
        $registration = app(WorkshopRegistrationService::class)->register($this->s1, $thief);
        $this->assertTrue($registration->isRegistered(), 'l\'inscription ne depend jamais du CRM');
        $this->assertSame($other->id, $stolen->fresh()->user_id);
        $this->assertSame(0, $stolen->events()->where('type', CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED)->count());

        // Aucun Contact dans une autre Organization.
        $this->assertSame(0, CrmContact::query()->where('organization_id', $this->b->id)->count());

        // Le bridge appele DIRECTEMENT avec une inscription incoherente (membre de B dans une session de A) : rien, nulle part (S3).
        $memberB = User::factory()->create(['organization_id' => $this->b->id, 'email' => 'b@example.test', 'email_verified_at' => now()]);
        $rogue = WorkshopRegistration::query()->forceCreate(['organization_id' => $this->a->id, 'workshop_id' => $this->wa->id, 'workshop_session_id' => $this->s1->id, 'user_id' => $memberB->id, 'status' => WorkshopRegistration::STATUS_REGISTERED, 'registered_at' => now()]);
        $this->assertNull(app(WorkshopCrmBridge::class)->onParticipationConfirmed($rogue->fresh()));
        $this->assertSame(0, CrmContact::query()->where('email', 'b@example.test')->count(), 'aucun Contact cree pour un membre d\'une autre Organization');
        $memberB = User::factory()->create(['organization_id' => $this->b->id, 'email_verified_at' => now()]);
        try {
            app(WorkshopRegistrationService::class)->register($this->s1, $memberB);
            $this->fail('autre Organization');
        } catch (LogicException) {
        }
        $this->assertSame(0, CrmContact::query()->where('organization_id', $this->b->id)->count());
    }

    // ── 4. La telemetrie CRM ne casse jamais l'inscription ─────────────────

    public function test_a_broken_crm_bridge_never_breaks_the_registration(): void
    {
        $this->instance(WorkshopCrmBridge::class, new class(app(CrmContactService::class), app(CrmTimelineService::class), app(AcquisitionEventRecorder::class)) extends WorkshopCrmBridge
        {
            public function onParticipationConfirmed(WorkshopRegistration $registration): ?CrmContact
            {
                throw new \RuntimeException('crm down');
            }
        });
        $member = $this->member('panne@example.test');
        $registration = app(WorkshopRegistrationService::class)->register($this->s1, $member);
        $this->assertTrue($registration->isRegistered());
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::PARTICIPATION_CONFIRMED)->count(), 'le fait produit est journalise meme si le CRM tombe');
        $this->assertSame(0, CrmContact::count());
    }
}
