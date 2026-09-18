<?php

namespace Tests\Feature;

use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationShortcut;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Models\WorkshopSessionInterest;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopRegistrationService;
use App\Services\Workshops\WorkshopResumption;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionFull;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1453 — signup / resumption / registration (Growth V3 §10, §11, §12 ;
 * MASTER Q78/Q80) : le flux canonique
 *   Guest interest → signup → email Verified → reprise → Registration.
 *
 * Preuves :
 *  1. bout en bout, meme navigateur : interet Guest → inscription (compte) →
 *     verification → atterrissage sur l'atelier choisi (reprise structuree) →
 *     « Confirmer ma participation » → Registration avec provenance (visiteur
 *     claime, Journey exacte) → participation_confirmed + converted (Journey
 *     workshop_participation, dedupe journey+user) ; l'Interest reste ; aucune
 *     auto-inscription ; annulation / reactivation = meme ligne, un seul fait ;
 *  2. capacite au moment canonique : capacite 1, deux Users verifies → le
 *     second est refuse honnetement (rien d'ecrit) ; l'Interest Guest ne
 *     consomme rien ; sous verrou ;
 *  3. fail-closed : non verifie → verification.notice sans ecriture ; autre
 *     Organization → 403 ; Guest → login ; session brouillon/passee → 404 ;
 *     User d'une autre Organization ne voit aucun etat prive ;
 *  4. email existant (V3 §12) : meme Organization → refus (compte unique),
 *     autre Organization → refus sans revelation, aucun Guest/Registration
 *     cross-tenant ; reprise cross-browser = repli normal ; la reference de
 *     reprise n'est jamais une URL et se revalide (atelier retire = repli).
 */
class TASK1453WorkshopRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $adminB;

    private Workshop $workshopA;

    private WorkshopSession $s1;

    private AcquisitionJourney $journey;

    private WorkshopRegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0]);

        $this->a = Organization::factory()->create(['slug' => 'org-a-1453', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-1453', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
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
        $this->service = app(WorkshopRegistrationService::class);
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
        // Le cookie Guest EMIS par une reponse precedente reste en file (CookieJar) : un autre navigateur ne l'a pas.
        Cookie::unqueue(GuestVisitorResolver::COOKIE);
    }

    private function pageUrl(): string
    {
        return route('organization.workshop.show', ['organization' => $this->a->slug, 'workshop' => 'ia-90']);
    }

    private function registerUrl(WorkshopSession $session): string
    {
        return route('organization.workshop.session.register', ['organization' => $this->a->slug, 'workshop' => 'ia-90', 'session' => $session->id]);
    }

    /**
     * Un Guest qui a choisi S1 depuis la page (premier geste, attribution `demo`). Chaque appel est un
     * NOUVEAU navigateur : cle brute explicite (le controller mis en cache par la Route garde, en test,
     * l'instance du resolver et sa cle emise — en production, une requete = un processus).
     */
    private function guestWithInterest(): string
    {
        $this->forgetAuth();
        $raw = Str::random(64);
        $this->asBrowser($raw)->post(route('organization.workshop.session.interest', ['organization' => $this->a->slug, 'workshop' => 'ia-90', 'session' => $this->s1->id]), ['attribution' => ['shortcut' => 'demo']])->assertRedirect();
        $this->assertNotNull(GuestVisitor::query()->where('visitor_key_hash', GuestVisitorResolver::hash($raw))->first(), 'le visiteur est cree au geste');

        return $raw;
    }

    private function signup(string $email, ?string $raw): TestResponse
    {
        $client = $raw === null ? $this : $this->asBrowser($raw);

        return $client->post(route('organization.register', ['organization' => $this->a->slug]), [
            'name' => 'Nouvelle Membre', 'first_name' => 'Nouvelle', 'email' => $email, 'phone' => '+33600000000', 'country_code' => 'FR',
            'password' => 'password-solide-1453', 'password_confirmation' => 'password-solide-1453',
        ]);
    }

    private function verify(User $user, ?string $raw): TestResponse
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
        $client = $raw === null ? $this->actingAs($user) : $this->actingAs($user)->asBrowser($raw);

        return $client->get($url);
    }

    private function verifiedMember(Organization $organization, string $email): User
    {
        return User::factory()->create(['organization_id' => $organization->id, 'email' => $email, 'email_verified_at' => now()]);
    }

    // ── 1. Bout en bout, meme navigateur ───────────────────────────────────

    public function test_guest_interest_then_signup_verification_resumption_and_explicit_registration_keep_the_provenance(): void
    {
        $raw = $this->guestWithInterest();
        $visitor = GuestVisitor::query()->sole();
        $this->assertSame($this->journey->id, $visitor->acquisition_journey_id);

        // Inscription (compte) avec le cookie : la reprise est PARQUEE (reference structuree, jamais une URL), aucune inscription auto.
        $this->signup('nouvelle@example.test', $raw)->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', 'nouvelle@example.test')->firstOrFail();
        $parked = session(WorkshopResumption::SESSION_KEY);
        $this->assertSame(['organization_id' => $this->a->id, 'workshop_id' => $this->workshopA->id, 'workshop_session_id' => $this->s1->id], $parked);
        $this->assertSame(0, WorkshopRegistration::count(), 'aucune inscription automatique');

        // Non verifie : la page ne propose pas de confirmation, le POST redirige vers la verification, rien n'est ecrit.
        $html = $this->actingAs($user)->asBrowser($raw)->get($this->pageUrl())->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-verify-first', $html);
        $this->assertStringNotContainsString('data-workshop-register="', $html);
        $this->assertStringNotContainsString('data-workshop-interest="', $html, 'un User connecte n\'a plus le geste Guest');
        $this->actingAs($user)->post($this->registerUrl($this->s1))->assertRedirect(route('verification.notice'));
        $this->assertSame(0, WorkshopRegistration::count());

        // Verification (meme navigateur) : claim SW-11 + reprise vers l'atelier choisi, pas vers le dashboard.
        $this->verify($user, $raw)->assertRedirect($this->pageUrl().'?verified=1');
        $this->assertSame($user->id, $visitor->fresh()->claimed_user_id, 'le claim SW-11 est intact');
        $this->assertNull(session(WorkshopResumption::SESSION_KEY), 'la reference est consommee');
        $this->assertSame(0, WorkshopRegistration::count(), 'toujours aucune inscription automatique');
        $interest = WorkshopSessionInterest::query()->sole();
        $this->assertTrue($interest->isSelected(), 'l\'Interest n\'est jamais efface au claim');

        // Sur l'atelier : sa session choisie est mise en evidence, la confirmation est un geste explicite.
        $html = $this->actingAs($user)->get($this->pageUrl())->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-register="'.$this->s1->id.'"', $html);
        $this->assertStringContainsString('data-workshop-guest-choice', $html);
        $this->actingAs($user)->post($this->registerUrl($this->s1))->assertRedirect($this->pageUrl())->assertSessionHas('workshop_registration', $this->s1->id);

        $registration = WorkshopRegistration::query()->sole();
        $this->assertSame([$this->a->id, $this->workshopA->id, $this->s1->id, $user->id, $visitor->id, $this->journey->id, WorkshopRegistration::STATUS_REGISTERED], [$registration->organization_id, $registration->workshop_id, $registration->workshop_session_id, $registration->user_id, $registration->guest_visitor_id, $registration->acquisition_journey_id, $registration->status]);
        $this->assertTrue($interest->fresh()->isSelected(), 'l\'Interest reste une preuve historique');

        $confirmed = AcquisitionEvent::query()->where('event', AcquisitionEvent::PARTICIPATION_CONFIRMED)->sole();
        $this->assertSame([$user->id, $visitor->id, $this->journey->id, 'demo'], [$confirmed->user_id, $confirmed->guest_visitor_id, $confirmed->acquisition_journey_id, $confirmed->shortcut]);
        $this->assertSame(AcquisitionEvent::PARTICIPATION_CONFIRMED.':registration:'.$registration->id, $confirmed->dedupe_key);
        $converted = AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->sole();
        $this->assertSame(AcquisitionEvent::CONVERTED.':journey:'.$this->journey->id.':user:'.$user->id, $converted->dedupe_key, 'une Journey = une conversion de ce User');
        $this->assertSame(['goal' => 'workshop_participation', 'workshop' => 'ia-90', 'session' => $this->s1->id], $converted->metadata);

        // La page : « Participation confirmee » + annuler ; rejeu du POST = meme ligne ; annulation puis reactivation = meme ligne, aucun second fait.
        $html = $this->actingAs($user)->get($this->pageUrl())->assertOk()->getContent();
        $this->assertStringContainsString('data-workshop-registration-cancel="'.$this->s1->id.'"', $html);
        $this->actingAs($user)->post($this->registerUrl($this->s1))->assertRedirect();
        $this->assertSame(1, WorkshopRegistration::count());
        $this->actingAs($user)->delete(route('organization.workshop.session.register.cancel', ['organization' => $this->a->slug, 'workshop' => 'ia-90', 'session' => $this->s1->id]))->assertRedirect();
        $this->assertSame(WorkshopRegistration::STATUS_CANCELLED, $registration->fresh()->status);
        $this->actingAs($user)->post($this->registerUrl($this->s1))->assertRedirect();
        $this->assertSame([WorkshopRegistration::STATUS_REGISTERED, null], [$registration->fresh()->status, $registration->fresh()->cancelled_at]);
        $this->assertSame(1, WorkshopRegistration::count());
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::PARTICIPATION_CONFIRMED)->count());
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->count());
    }

    // ── 2. Capacite au moment canonique ────────────────────────────────────

    public function test_capacity_is_enforced_at_registration_time_only_and_a_guest_interest_never_consumes_it(): void
    {
        // Deux interets Guest sur une capacite de 1 : rien n'est consomme.
        $this->guestWithInterest();
        $this->guestWithInterest();
        $this->assertSame(2, WorkshopSessionInterest::count());

        $first = $this->verifiedMember($this->a, 'first@example.test');
        $second = $this->verifiedMember($this->a, 'second@example.test');
        $this->forgetAuth();
        $this->actingAs($first)->post($this->registerUrl($this->s1))->assertRedirect()->assertSessionHas('workshop_registration');
        $this->forgetAuth();
        $this->actingAs($second)->post($this->registerUrl($this->s1))->assertRedirect($this->pageUrl())->assertSessionHas('workshop_registration_full', $this->s1->id)->assertSessionMissing('workshop_registration');
        $this->assertSame(1, WorkshopRegistration::count(), 'refus honnete : rien d\'ecrit');
        // Sans provenance Guest (aucun visiteur claime) : participation_confirmed oui, mais AUCUNE conversion —
        // meme si l'Organization possede une Journey publiee `workshop_participation` (MASTER Q80 : la Journey EXACTE attribuee, jamais deduite).
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::PARTICIPATION_CONFIRMED)->where('user_id', $first->id)->count());
        $this->assertNull(WorkshopRegistration::query()->sole()->acquisition_journey_id);
        $this->assertSame(0, AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->count(), 'aucune Journey attribuee = aucune conversion');
        $this->assertSame(0, AcquisitionEvent::query()->where('event', AcquisitionEvent::PARTICIPATION_CONFIRMED)->where('user_id', $second->id)->count());
        $this->assertStringContainsString('data-workshop-registration-full', $this->actingAs($second)->get($this->pageUrl())->getContent());

        // Une annulation libere la place ; la reactivation du premier est refusee si le second a pris la place.
        $this->service->cancel($this->s1, $first);
        $this->assertTrue($this->service->register($this->s1, $second)->isRegistered());
        try {
            $this->service->register($this->s1, $first);
            $this->fail('complet : la reactivation est refusee au moment canonique');
        } catch (WorkshopSessionFull) {
        }
        $this->assertSame(2, WorkshopRegistration::count(), 'deux lignes (une annulee, une active), jamais plus');
        $this->assertSame(1, WorkshopRegistration::query()->registered()->count());

        // Sans capacite : illimite ; le compte des inscrits n'est PAS exposé au public.
        $this->s1->forceFill(['capacity' => null])->save();
        $this->assertTrue($this->service->register($this->s1->fresh(), $first)->isRegistered());
        $this->forgetAuth();
        $html = $this->get($this->pageUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('first@example.test', $html);
        $this->assertStringNotContainsString('data-workshop-registered', $html);
    }

    // ── 3. Fail-closed ─────────────────────────────────────────────────────

    public function test_registration_is_fail_closed_for_guests_unverified_users_other_organizations_and_non_public_sessions(): void
    {
        $sessions = app(WorkshopSessionService::class);
        $draft = $sessions->create($this->workshopA, ['starts_at' => '2026-10-15 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $past = $sessions->create($this->workshopA, ['starts_at' => '2026-09-01 18:30', 'timezone' => 'Europe/Paris'], $this->adminA);
        $sessions->publish($past, $this->adminA);

        // Guest : le geste membre exige une session (auth).
        $this->forgetAuth();
        $this->post($this->registerUrl($this->s1))->assertRedirect();
        $this->assertSame(0, WorkshopRegistration::count());

        // Non verifie : redirection vers la verification, rien d'ecrit.
        $unverified = User::factory()->create(['organization_id' => $this->a->id, 'email_verified_at' => null]);
        $this->actingAs($unverified)->post($this->registerUrl($this->s1))->assertRedirect(route('verification.notice'));
        try {
            $this->service->register($this->s1, $unverified);
            $this->fail('non verifie');
        } catch (LogicException) {
        }

        // Autre Organization : 403, aucune information, rien d'ecrit ; la page ne montre aucun etat prive.
        $memberB = $this->verifiedMember($this->b, 'b@example.test');
        $this->forgetAuth();
        $this->actingAs($memberB)->post($this->registerUrl($this->s1))->assertForbidden();
        $html = $this->actingAs($memberB)->get($this->pageUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('data-workshop-register="', $html);
        $this->assertStringNotContainsString('data-workshop-verify-first', $html);
        try {
            $this->service->register($this->s1, $memberB);
            $this->fail('autre Organization');
        } catch (LogicException) {
        }

        // Session brouillon / passee : 404 ; atelier retire : 404.
        $memberA = $this->verifiedMember($this->a, 'a@example.test');
        $this->forgetAuth();
        $this->actingAs($memberA)->post($this->registerUrl($draft))->assertNotFound();
        $this->actingAs($memberA)->post($this->registerUrl($past))->assertNotFound();
        app(WorkshopService::class)->retire($this->workshopA, $this->adminA);
        $this->actingAs($memberA)->post($this->registerUrl($this->s1))->assertNotFound();
        $this->assertSame(0, WorkshopRegistration::count());
        $this->assertSame(0, AcquisitionEvent::query()->whereIn('event', [AcquisitionEvent::PARTICIPATION_CONFIRMED, AcquisitionEvent::CONVERTED])->count());
    }

    // ── 4. Email existant, cross-browser, reference revalidee ──────────────

    public function test_existing_email_is_refused_without_disclosure_and_the_resumption_is_a_revalidated_reference_not_a_url(): void
    {
        $this->verifiedMember($this->a, 'deja@example.test');
        $this->verifiedMember($this->b, 'ailleurs@example.test');
        $raw = $this->guestWithInterest();

        // Email deja present dans la MEME Organization : refus (compte unique), rien de nouveau.
        $users = User::count();
        $this->signup('deja@example.test', $raw)->assertSessionHasErrors('email');
        // Email d'une AUTRE Organization : refus identique, aucune Organization revelee, aucun Guest/inscription cross-tenant.
        $response = $this->signup('ailleurs@example.test', $raw);
        $response->assertSessionHasErrors('email');
        $this->assertStringNotContainsString('B Guild', (string) session('errors')?->first('email'));
        $this->assertSame($users, User::count());
        $this->assertSame(1, GuestVisitor::count());
        $this->assertSame(0, WorkshopRegistration::count());
        $this->assertNull(session(WorkshopResumption::SESSION_KEY), 'rien n\'est parque sans compte cree');

        // Cross-browser : inscription avec le cookie, verification SANS (autre navigateur) → repli normal, aucun claim, aucune reprise.
        $this->signup('cross@example.test', $raw)->assertSessionHasNoErrors();
        $cross = User::where('email', 'cross@example.test')->firstOrFail();
        $this->forgetAuth();
        $this->verify($cross, null)->assertRedirect(route('dashboard', absolute: false).'?verified=1');
        $this->assertNull(GuestVisitor::query()->sole()->claimed_user_id);

        // La reference de reprise se REVALIDE : atelier retire entre-temps → repli normal ; tenant force → repli.
        $resumption = app(WorkshopResumption::class);
        session()->put(WorkshopResumption::SESSION_KEY, ['organization_id' => $this->a->id, 'workshop_id' => $this->workshopA->id, 'workshop_session_id' => $this->s1->id]);
        $memberA = $this->verifiedMember($this->a, 'membre@example.test');
        $this->assertSame($this->pageUrl(), $resumption->consume($memberA));
        $this->assertNull(session(WorkshopResumption::SESSION_KEY));
        session()->put(WorkshopResumption::SESSION_KEY, ['organization_id' => $this->a->id, 'workshop_id' => $this->workshopA->id, 'workshop_session_id' => $this->s1->id]);
        $memberB = $this->verifiedMember($this->b, 'membre-b@example.test');
        $this->assertNull($resumption->consume($memberB), 'un User d\'une autre Organization ne reprend rien');
        session()->put(WorkshopResumption::SESSION_KEY, 'https://evil.example/phish');
        $this->assertNull($resumption->consume($memberA), 'une URL brute n\'est jamais suivie');
        app(WorkshopService::class)->retire($this->workshopA, $this->adminA);
        session()->put(WorkshopResumption::SESSION_KEY, ['organization_id' => $this->a->id, 'workshop_id' => $this->workshopA->id, 'workshop_session_id' => $this->s1->id]);
        $this->assertNull($resumption->consume($memberA), 'atelier retire : repli normal');
    }
}
