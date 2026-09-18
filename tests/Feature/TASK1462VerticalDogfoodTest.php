<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationShortcut;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Models\WorkshopSessionInterest;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1462 — Dogfood vertical (Growth V3 §18, MASTER #52 A) : LA chaine 1→13,
 * integree, avec le provider FAKE — Journey publiee → Shortcut/campagne →
 * navigateur neuf → bonne Organization → Shell Welcome → intention → atelier
 * present dans le runtime → page atelier → session choisie → signup → email
 * verifie (claim + reprise) → participation confirmee → OrgAdmin voit
 * l'inscription → CRM Contact lie, timeline, provenance, cockpits, journal.
 * Un seul test : une seule verite mesuree de bout en bout.
 */
class TASK1462VerticalDogfoodTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private User $adminA;

    private User $superAdmin;

    private AcquisitionJourney $journey;

    private Workshop $workshop;

    private WorkshopSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $this->a = Organization::factory()->create(['slug' => 'org-a-1462', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        app(GuestShellPolicyService::class)->update($this->a, ['enabled' => true, 'max_messages' => 5]);
        OrganizationAiSetting::create(['organization_id' => $this->a->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $b = Organization::factory()->create(['slug' => 'org-b-1462', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->superAdmin = User::factory()->create(['organization_id' => $b->id, 'is_admin' => true]);

        // 1. Journey publiee (objectif : participation a un atelier) ; 2. Shortcut + campagne.
        $journeys = app(AcquisitionJourneyService::class);
        $this->journey = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION, 'campaign' => 'sept-2026'], $this->adminA);
        $journeys->publish($this->journey, $this->adminA);
        OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'rentree', 'destination' => 'organization_home', 'acquisition_journey_key' => 'rentree', 'campaign' => 'sept-2026', 'active' => true, 'created_by' => $this->superAdmin->id]);

        // L'atelier reel, publie, avec une session publiee a venir.
        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $this->workshop = $workshops->create($this->a, ['title' => 'Découvrir BouclePro', 'slug' => 'decouvrir', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($this->workshop, $this->adminA);
        $this->session = $sessions->create($this->workshop, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris', 'capacity' => 10], $this->adminA);
        $sessions->publish($this->session, $this->adminA);
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    private function browser(string $raw): static
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

    public function test_the_whole_vertical_from_the_short_link_to_the_crm_contact_is_one_measured_truth(): void
    {
        $raw = Str::random(64);

        // 3-4. Navigateur neuf, lien court : 302 interne vers la bonne Organization, attribution portee par l'URL, fait journalise AVANT le 302.
        $this->forgetAuth();
        $landing = $this->get('/s/rentree')->assertRedirect();
        $target = $landing->headers->get('Location');
        $this->assertStringStartsWith(route('organization.home', ['organization' => $this->a->slug]), $target);
        $this->assertStringContainsString('shortcut=rentree', $target);
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::SHORTCUT_OPENED)->count());

        // 5-6. Shell Welcome : premier geste = identite Guest attribuee (Journey exacte, campagne, lien court) ; le modele (fake) recoit l'atelier reel dans son runtime.
        GuestShellAgent::fake([new TextResponse('Bienvenue ! L\'atelier « Découvrir BouclePro » a lieu le 1er octobre.', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->browser($raw)->postJson(route('organization.shell.message', ['organization' => $this->a->slug]), ['message' => 'Je voudrais découvrir BouclePro, quel atelier puis-je rejoindre ?', 'attribution' => ['shortcut' => 'rentree', 'utm_campaign' => 'sept-2026']])
            ->assertOk()->assertJsonPath('turn', 'answered');
        $visitor = GuestVisitor::forOrganization($this->a)->where('visitor_key_hash', GuestVisitorResolver::hash($raw))->firstOrFail();
        $this->assertSame([$this->journey->id, 'sept-2026', 'rentree'], [$visitor->acquisition_journey_id, $visitor->utm_campaign, $visitor->shortcut], 'attribution FIRST TOUCH relue en base, jamais crue sur parole');
        $workshopUrl = route('organization.workshop.show', ['organization' => $this->a->slug, 'workshop' => 'decouvrir']);
        GuestShellAgent::assertPrompted(fn (AgentPrompt $p) => str_contains((string) $p->agent->instructions(), 'Découvrir BouclePro') && str_contains((string) $p->agent->instructions(), $workshopUrl));

        // 7-8. Page atelier publique (aucune donnee privee) ; session choisie (interet Guest, pas une inscription).
        $this->browser($raw)->get($workshopUrl)->assertOk()->assertSee('Découvrir BouclePro')->assertDontSee('meet.')->assertDontSee('@example.test');
        $this->browser($raw)->post(route('organization.workshop.session.interest', ['organization' => $this->a->slug, 'workshop' => 'decouvrir', 'session' => $this->session->id]))->assertRedirect($workshopUrl);
        $this->assertSame(1, WorkshopSessionInterest::query()->where('workshop_session_id', $this->session->id)->count());
        $this->assertSame(0, WorkshopRegistration::query()->count(), 'un interet n\'est jamais une inscription');

        // 9. Signup dans le meme navigateur : le formulaire (signup_started, lecture pure du cookie) puis le POST (reference de reprise parquee, aucune inscription automatique).
        $this->browser($raw)->get(route('organization.register', ['organization' => $this->a->slug]))->assertOk();
        $this->browser($raw)->post(route('organization.register', ['organization' => $this->a->slug]), [
            'name' => 'Vertical', 'first_name' => 'Dogfood', 'email' => 'vertical@example.test', 'phone' => '+33600000000', 'country_code' => 'FR',
            'password' => 'password-solide-1462', 'password_confirmation' => 'password-solide-1462',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', 'vertical@example.test')->firstOrFail();
        $this->assertSame(0, WorkshopRegistration::query()->count());
        $this->assertSame(0, CrmContact::query()->count(), 'aucun Contact commercial au simple signup');

        // 10. Email verifie dans le meme navigateur : claim SW-11, reprise structuree vers l'atelier, Contact CRM (identite, jamais consentement).
        $verifyUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
        $this->actingAs($user)->browser($raw)->get($verifyUrl)->assertRedirect($workshopUrl.'?verified=1'); // reprise structuree (T1453) : retour sur l'atelier, signale verifie
        $this->assertSame($user->id, $visitor->fresh()->claimed_user_id);
        $contact = CrmContact::query()->sole();
        $this->assertSame([$this->a->id, $user->id, CrmContact::SOURCE_SHELL_WELCOME], [$contact->organization_id, $contact->user_id, $contact->source]);

        // 11. Geste explicite : participation confirmee — capacite verrouillee, provenance = le visiteur porteur de l'interet, conversion de la Journey exacte.
        $this->actingAs($user)->post(route('organization.workshop.session.register', ['organization' => $this->a->slug, 'workshop' => 'decouvrir', 'session' => $this->session->id]))->assertRedirect();
        $registration = WorkshopRegistration::query()->sole();
        $this->assertSame([$user->id, $visitor->id, $this->journey->id, WorkshopRegistration::STATUS_REGISTERED], [$registration->user_id, $registration->guest_visitor_id, $registration->acquisition_journey_id, $registration->status]);
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->where('acquisition_journey_id', $this->journey->id)->where('user_id', $user->id)->count());

        // 12. OrgAdmin voit l'inscription (cockpit + inscrits nominatifs + lien Contact) ; SuperAdmin voit la conversion.
        $this->forgetAuth();
        $this->actingAs($this->adminA)->get(route('organization.admin.workshops', $this->a))->assertOk()->assertSee('1 inscrit');
        $registrants = $this->actingAs($this->adminA)->get(route('organization.admin.workshops.registrants', [$this->a, $this->workshop]))->assertOk()->getContent();
        $this->assertStringContainsString('vertical@example.test', $registrants);
        $this->assertStringContainsString('Rentrée v1', $registrants);
        $this->assertStringContainsString('/s/rentree', $registrants);
        $this->assertStringContainsString('data-registrant-contact="'.$contact->id.'"', $registrants);
        $this->assertStringContainsString('data-admin-workshop-registrations="1"', $this->actingAs($this->superAdmin)->get(route('admin.workshops'))->assertOk()->getContent());

        // 13. CRM : un seul Contact, lie, timeline (shell_claimed + participation), provenance first touch, journal complet — aucun transcript, aucune cle.
        $this->assertSame(1, CrmContact::query()->count());
        $this->assertSame(1, $contact->events()->where('type', CrmContactEvent::TYPE_SHELL_CLAIMED)->count());
        $this->assertSame(1, $contact->events()->where('type', CrmContactEvent::TYPE_WORKSHOP_PARTICIPATION_CONFIRMED)->count());
        $page = $this->actingAs($this->adminA)->get(route('organization.admin.crm.contacts.show', [$this->a, $contact]))->assertOk()->getContent();
        foreach (['data-crm-attribution-journey>Rentrée v1<', 'data-crm-attribution-campaign>sept-2026<', 'data-crm-attribution-shortcut>/s/rentree<', 'Découvrir BouclePro · 01/10/2026 18:30', 'data-crm-event="shell_claimed"', 'data-crm-event="workshop_participation_confirmed"'] as $expected) {
            $this->assertStringContainsString($expected, $page);
        }
        foreach (['Bienvenue !', 'Je voudrais découvrir', $visitor->visitor_key_hash, 'consent'] as $never) {
            $this->assertStringNotContainsString($never, $page);
        }
        $events = AcquisitionEvent::query()->orderBy('created_at')->orderBy('id')->pluck('event')->all();
        foreach ([AcquisitionEvent::SHORTCUT_OPENED, AcquisitionEvent::GUEST_CREATED, AcquisitionEvent::CONVERSATION_STARTED, AcquisitionEvent::WORKSHOP_VIEWED, AcquisitionEvent::SESSION_SELECTED, AcquisitionEvent::SIGNUP_STARTED, AcquisitionEvent::ACCOUNT_CREATED, AcquisitionEvent::EMAIL_VERIFIED, AcquisitionEvent::CRM_CONTACT_LINKED, AcquisitionEvent::PARTICIPATION_CONFIRMED, AcquisitionEvent::CONVERTED] as $expected) {
            $this->assertContains($expected, $events, "journal : {$expected}");
        }
    }
}
