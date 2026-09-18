<?php

namespace Tests\Feature;

use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationShortcut;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Models\WorkshopSessionInterest;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopInterestService;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1452 — B4-B Guest session selection / interest (Growth V3 §10, MASTER
 * Q78) : un visiteur pseudonyme choisit une session depuis la page publique de
 * l'atelier. Interet != inscription : aucun User, aucune place consommee.
 *
 * Preuves :
 *  1. le geste : premier geste Guest (cookie pose, attribution relue en base),
 *     une ligne (session, visiteur), `session_selected` journalise UNE fois ;
 *     idempotent (double clic, retrait, re-selection = meme ligne, meme fait) ;
 *     la page reflète le choix du visiteur ;
 *  2. fail-closed : brouillon / annulee / passee / autre atelier / atelier non
 *     publie / Organization privee / User connecte = 404, rien d'ecrit ;
 *     la capacite n'est PAS consommee (deux visiteurs sur capacite 1) ;
 *  3. `workshop_viewed` : une fois par (visiteur, atelier), lecture pure du
 *     cookie (aucune identite creee par un affichage), jamais pour un cookie
 *     d'une autre Organization ;
 *  4. le service : coherence tenant (faute de code), visibilite des choix
 *     bornee au visiteur et a l'atelier.
 */
class TASK1452GuestSessionInterestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $adminB;

    private Workshop $workshopA;

    private WorkshopSession $s1;

    private WorkshopSession $s2;

    private AcquisitionJourney $journey;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0]);

        $this->a = Organization::factory()->create(['slug' => 'org-a-1452', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-1452', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->b->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->b->update(['admin_id' => $this->adminB->id]);

        $journeys = app(AcquisitionJourneyService::class);
        $this->journey = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION], $this->adminA);
        $journeys->publish($this->journey, $this->adminA);
        OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'demo', 'destination' => 'organization_home', 'acquisition_journey_key' => 'rentree', 'campaign' => 'sept-2026', 'active' => true, 'created_by' => $this->adminA->id]);

        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $this->workshopA = $workshops->create($this->a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($this->workshopA, $this->adminA);
        $this->s1 = $sessions->create($this->workshopA, ['starts_at' => '2026-10-01 18:30', 'ends_at' => '2026-10-01 20:00', 'timezone' => 'Europe/Paris', 'capacity' => 1], $this->adminA);
        $sessions->publish($this->s1, $this->adminA);
        $this->s2 = $sessions->create($this->workshopA, ['starts_at' => '2026-10-08 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $sessions->publish($this->s2, $this->adminA);
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    private function asBrowser(string $raw): static
    {
        return $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie($raw));
    }

    private function forgetAuth(): void
    {
        auth()->logout();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    private function pageUrl(?Organization $organization = null, string $slug = 'ia-90'): string
    {
        return route('organization.workshop.show', ['organization' => ($organization ?? $this->a)->slug, 'workshop' => $slug]);
    }

    private function interestUrl(WorkshopSession $session, ?Organization $organization = null, string $slug = 'ia-90'): string
    {
        return route('organization.workshop.session.interest', ['organization' => ($organization ?? $this->a)->slug, 'workshop' => $slug, 'session' => $session->id]);
    }

    private function select(?string $raw, WorkshopSession $session, array $attribution = []): TestResponse
    {
        $client = $raw === null ? $this : $this->asBrowser($raw);

        return $client->post($this->interestUrl($session), ['attribution' => $attribution]);
    }

    private function rawGuestCookieOf(TestResponse $response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestVisitorResolver::COOKIE) {
                return CookieValuePrefix::remove(app('encrypter')->decrypt((string) $cookie->getValue(), false));
            }
        }

        return null;
    }

    // ── 1. Le geste ────────────────────────────────────────────────────────

    public function test_a_guest_chooses_a_session_as_a_first_gesture_idempotently_and_the_page_reflects_it(): void
    {
        $this->forgetAuth();
        // Aucune identite avant le geste : afficher ne cree rien.
        $this->get($this->pageUrl().'?shortcut=demo&utm_source=linkedin')->assertOk()->assertCookieMissing(GuestVisitorResolver::COOKIE);
        $this->assertSame(0, GuestVisitor::count());

        $response = $this->select(null, $this->s1, ['shortcut' => 'demo', 'utm_source' => 'linkedin'])->assertRedirect($this->pageUrl())->assertSessionHas('workshop_interest', $this->s1->id);
        $raw = $this->rawGuestCookieOf($response);
        $this->assertNotNull($raw, 'premier geste : le cookie first-party est pose');
        $visitor = GuestVisitor::query()->sole();
        $this->assertSame($this->a->id, $visitor->organization_id);
        $this->assertSame($this->journey->id, $visitor->acquisition_journey_id, 'l\'attribution est relue en base (TASK-1447)');
        $this->assertSame('demo', $visitor->shortcut);
        $this->assertSame('linkedin', $visitor->utm_source);

        $interest = WorkshopSessionInterest::query()->sole();
        $this->assertSame([$this->a->id, $this->workshopA->id, $this->s1->id, $visitor->id, WorkshopSessionInterest::STATUS_SELECTED], [$interest->organization_id, $interest->workshop_id, $interest->workshop_session_id, $interest->guest_visitor_id, $interest->status]);
        $event = AcquisitionEvent::query()->where('event', AcquisitionEvent::SESSION_SELECTED)->sole();
        $this->assertSame($visitor->id, $event->guest_visitor_id);
        $this->assertSame($this->journey->id, $event->acquisition_journey_id);
        $this->assertSame('demo', $event->shortcut);
        $this->assertSame(['workshop' => 'ia-90', 'session' => $this->s1->id], $event->metadata);
        $this->assertSame(AcquisitionEvent::SESSION_SELECTED.':interest:'.$interest->id, $event->dedupe_key);
        $this->assertSame(0, User::where('organization_id', $this->a->id)->where('id', '!=', $this->adminA->id)->count(), 'aucun faux User');

        // La page, avec le cookie : S1 choisie, S2 selectionnable ; le flash d'honnetete.
        $html = $this->asBrowser($raw)->get($this->pageUrl())->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-interest-withdraw="'.$this->s1->id.'"', $html);
        $this->assertStringContainsString('data-workshop-interest-selected', $html);
        $this->assertStringContainsString('data-workshop-interest="'.$this->s2->id.'"', $html);
        $this->assertStringNotContainsString('data-workshop-interest="'.$this->s1->id.'"', $html);

        // Double clic / second onglet : meme ligne, meme fait.
        $this->select($raw, $this->s1)->assertRedirect();
        $this->assertSame(1, WorkshopSessionInterest::count());
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::SESSION_SELECTED)->count());
        $this->assertSame(1, GuestVisitor::count(), 'le meme visiteur, pas un second');

        // Retrait puis re-selection : la ligne est reactivee, aucun second fait.
        $this->asBrowser($raw)->delete(route('organization.workshop.session.interest.withdraw', ['organization' => $this->a->slug, 'workshop' => 'ia-90', 'session' => $this->s1->id]))->assertRedirect($this->pageUrl());
        $this->assertSame(WorkshopSessionInterest::STATUS_WITHDRAWN, $interest->fresh()->status);
        $this->assertNotNull($interest->fresh()->withdrawn_at);
        $this->assertStringContainsString('data-workshop-interest="'.$this->s1->id.'"', $this->asBrowser($raw)->get($this->pageUrl())->getContent());
        $this->select($raw, $this->s1)->assertRedirect();
        $this->assertSame(WorkshopSessionInterest::STATUS_SELECTED, $interest->fresh()->status);
        $this->assertNull($interest->fresh()->withdrawn_at);
        $this->assertSame(1, WorkshopSessionInterest::count());
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::SESSION_SELECTED)->count());

        // Une seconde session : une seconde ligne, un second fait.
        $this->select($raw, $this->s2)->assertRedirect();
        $this->assertSame(2, WorkshopSessionInterest::count());
        $this->assertSame(2, AcquisitionEvent::query()->where('event', AcquisitionEvent::SESSION_SELECTED)->count());
    }

    // ── 2. Fail-closed, et la capacite n'est pas consommee ─────────────────

    public function test_only_a_published_upcoming_session_of_a_published_workshop_accepts_a_guest_interest_and_capacity_is_informative(): void
    {
        $sessions = app(WorkshopSessionService::class);
        $draft = $sessions->create($this->workshopA, ['starts_at' => '2026-10-15 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $cancelled = $sessions->create($this->workshopA, ['starts_at' => '2026-10-16 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $sessions->publish($cancelled, $this->adminA);
        $sessions->cancel($cancelled, $this->adminA);
        $past = $sessions->create($this->workshopA, ['starts_at' => '2026-09-01 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $sessions->publish($past, $this->adminA);

        $workshops = app(WorkshopService::class);
        $workshopB = $workshops->create($this->b, ['title' => 'Atelier B', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $this->adminB);
        $workshops->publish($workshopB, $this->adminB);
        $sb = $sessions->create($workshopB, ['starts_at' => '2026-10-20 18:30', 'timezone' => 'Europe/Paris'], $this->adminB);
        $sessions->publish($sb, $this->adminB);

        $this->forgetAuth();
        foreach ([$draft, $cancelled, $past] as $session) {
            $this->post($this->interestUrl($session))->assertNotFound();
        }
        // La session de B sous l'atelier de A (meme slug) : n'existe pas.
        $this->post($this->interestUrl($sb))->assertNotFound();
        // L'atelier retire : ses sessions ne sont plus selectionnables.
        $workshops->retire($this->workshopA, $this->adminA);
        $this->post($this->interestUrl($this->s1))->assertNotFound();
        $workshops->publish($this->workshopA, $this->adminA);
        // Organization privee.
        $this->a->update(['is_public' => false]);
        $this->post($this->interestUrl($this->s1))->assertNotFound();
        $this->a->update(['is_public' => true]);
        $this->assertSame(0, WorkshopSessionInterest::count());
        $this->assertSame(0, GuestVisitor::count(), 'un refus ne cree aucune identite');
        $this->assertSame(0, AcquisitionEvent::count());

        // Un User connecte n'exprime pas d'interet Guest (il passera par le flux canonique) ; la page ne lui propose pas le geste.
        $this->actingAs($this->adminA)->post($this->interestUrl($this->s1))->assertNotFound();
        $this->assertStringNotContainsString('data-workshop-interest=', $this->actingAs($this->adminA)->get($this->pageUrl())->assertOk()->getContent());
        $this->forgetAuth();

        // Capacite 1, deux visiteurs : deux interets — la capacite est INFORMATIVE, aucune place consommee.
        $this->select(Str::random(64), $this->s1)->assertRedirect();
        $this->forgetAuth();
        $this->select(Str::random(64), $this->s1)->assertRedirect();
        $this->assertSame(2, WorkshopSessionInterest::query()->where('workshop_session_id', $this->s1->id)->selected()->count());
        $this->assertSame(1, $this->s1->fresh()->capacity);
        $this->assertStringContainsString('data-workshop-session-capacity', $this->get($this->pageUrl())->getContent());
    }

    // ── 3. workshop_viewed ─────────────────────────────────────────────────

    public function test_workshop_viewed_is_journaled_once_per_known_visitor_and_workshop_without_creating_any_identity(): void
    {
        $this->forgetAuth();
        $this->get($this->pageUrl())->assertOk();
        $this->assertSame(0, AcquisitionEvent::count(), 'sans cookie : aucun visiteur, aucun fait');
        $this->assertSame(0, GuestVisitor::count());

        $raw = Str::random(64);
        $visitor = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $raw]), $this->a, ['shortcut' => 'demo', 'acquisition_journey_id' => $this->journey->id]);
        $this->asBrowser($raw)->get($this->pageUrl())->assertOk();
        $this->asBrowser($raw)->get($this->pageUrl())->assertOk();
        $viewed = AcquisitionEvent::query()->where('event', AcquisitionEvent::WORKSHOP_VIEWED)->sole();
        $this->assertSame($visitor->id, $viewed->guest_visitor_id);
        $this->assertSame($this->journey->id, $viewed->acquisition_journey_id);
        $this->assertSame(['workshop' => 'ia-90'], $viewed->metadata);
        $this->assertSame(1, GuestVisitor::count());

        // Le cookie d'un visiteur de B sur la page de A : inconnu ici, rien.
        $this->forgetAuth();
        $rawB = Str::random(64);
        app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $rawB]), $this->b);
        $this->asBrowser($rawB)->get($this->pageUrl())->assertOk();
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::WORKSHOP_VIEWED)->count());
        $this->assertSame(2, GuestVisitor::count(), 'aucune identite creee cote A');
    }

    // ── 4. Le service ──────────────────────────────────────────────────────

    public function test_the_interest_service_is_tenant_consistent_and_scopes_choices_to_the_visitor_and_workshop(): void
    {
        $service = app(WorkshopInterestService::class);
        $visitorA = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->a);
        $visitorB = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->b);

        try {
            $service->select($this->s1, $visitorB);
            $this->fail('un visiteur d\'une autre Organization ne choisit pas ici');
        } catch (LogicException) {
        }
        $this->assertSame(0, WorkshopSessionInterest::count());

        $service->select($this->s1, $visitorA);
        $this->assertSame([$this->s1->id], $service->selectedSessionIds($visitorA, (string) $this->workshopA->id));
        $this->assertSame([], $service->selectedSessionIds($visitorB, (string) $this->workshopA->id));
        $service->withdraw($this->s1, $visitorA);
        $this->assertSame([], $service->selectedSessionIds($visitorA, (string) $this->workshopA->id));
        $this->assertNull($service->withdraw($this->s2, $visitorA), 'retirer un choix inexistant ne cree rien');
        $this->assertSame(1, WorkshopSessionInterest::count());
    }
}
