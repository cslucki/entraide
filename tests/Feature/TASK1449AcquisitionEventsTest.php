<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\GuestConversation;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\OrganizationShortcut;
use App\Models\User;
use App\Services\Acquisition\AcquisitionEventRecorder;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1449 — Acquisition events / attribution (Growth Workshops Acquisition
 * V3 §5, MASTER Q77) : un journal APPEND-ONLY, tenante, borne, permettant de
 * RECONSTRUIRE le parcours lien -> Guest -> compte -> Verified et de rattacher
 * une conversion a sa provenance FIRST TOUCH (Journey exacte, UTM, shortcut).
 *
 * Preuves :
 *  1. `shortcut_opened` AVANT le 302, une ligne par ouverture, sans cookie ni
 *     Guest ; 404 = rien ; journal en panne = le 302 part quand meme ;
 *  2. `guest_created` + `conversation_started` au premier geste, une fois
 *     chacun, avec l'attribution du visiteur relue en base ;
 *  3. `signup_started` a l'affichage du formulaire, une fois par visiteur,
 *     lecture pure du cookie (aucune identite creee) ;
 *  4. `account_created` a l'inscription : la provenance survit Guest -> User ;
 *     rejeu de `Registered` = une ligne ; sans cookie = fait sans visiteur ;
 *  5. `email_verified` une fois par User ; `converted` seulement quand le
 *     contrat de la Journey (`conversion_goal = account`) est atteint —
 *     jamais pour une Journey `contact`, jamais sans Journey ; rejeu = rien ;
 *  6. le recorder : 12 evenements exacts, tenant fail-closed (faute de code),
 *     dimensions et metadata bornees, append-only (ni update ni delete).
 */
class TASK1449AcquisitionEventsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $superAdmin;

    private AcquisitionJourney $accountJourney;

    private AcquisitionJourney $contactJourney;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $policies = app(GuestShellPolicyService::class);
        foreach (['a', 'b'] as $key) {
            $org = Organization::factory()->create(['slug' => "org-{$key}-1449", 'name' => strtoupper($key).' Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
            $policies->update($org, ['enabled' => true, 'max_messages' => 5]);
            OrganizationAiSetting::create(['organization_id' => $org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
            $this->{$key} = $org;
        }
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->b->id, 'is_admin' => true]);

        $journeys = app(AcquisitionJourneyService::class);
        $this->accountJourney = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_ACCOUNT], $this->adminA);
        $journeys->publish($this->accountJourney, $this->adminA);
        $this->contactJourney = $journeys->createDraft($this->a, ['key' => 'newsletter', 'name' => 'Newsletter', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_CONTACT], $this->adminA);
        $journeys->publish($this->contactJourney, $this->adminA);

        OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'demo', 'destination' => 'organization_home', 'acquisition_journey_key' => 'rentree', 'campaign' => 'sept-2026', 'active' => true, 'created_by' => $this->superAdmin->id]);
        OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'news', 'destination' => 'organization_home', 'acquisition_journey_key' => 'newsletter', 'campaign' => null, 'active' => true, 'created_by' => $this->superAdmin->id]);
        OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'off', 'destination' => 'organization_home', 'acquisition_journey_key' => null, 'campaign' => null, 'active' => false, 'created_by' => $this->superAdmin->id]);
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    private function asBrowser(string $raw): static
    {
        return $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie($raw));
    }

    /** Le premier geste Guest, tel que le widget l'envoie (TASK-1447 : attribution dans le payload borne). */
    private function message(string $raw, array $attribution = ['shortcut' => 'demo', 'utm_source' => 'newsletter']): TestResponse
    {
        GuestShellAgent::fake([new TextResponse('Bienvenue.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->forgetAuth();

        return $this->asBrowser($raw)->postJson(route('organization.shell.message', ['organization' => $this->a->slug]), ['message' => 'Bonjour', 'attribution' => $attribution])->assertOk()->assertJsonPath('turn', 'answered');
    }

    private function visitorOf(string $raw): GuestVisitor
    {
        return GuestVisitor::forOrganization($this->a)->where('visitor_key_hash', GuestVisitorResolver::hash($raw))->firstOrFail();
    }

    /** Un nouveau navigateur : plus de session ni d'utilisateur connecte (l'inscription et le Shell sont des surfaces `guest`). */
    private function forgetAuth(): void
    {
        auth()->logout();
        $this->flushSession();
        // Les cookies poses par `withUnencryptedCookie` persistent d'une requete a l'autre dans un meme test : un nouveau navigateur n'en a aucun.
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    private function register(string $email, ?string $raw): User
    {
        $this->forgetAuth();
        $client = $raw === null ? $this : $this->asBrowser($raw);
        $client->post(route('organization.register', ['organization' => $this->a->slug]), [
            'name' => 'Nouvelle Membre', 'first_name' => 'Nouvelle', 'email' => $email, 'phone' => '+33600000000', 'country_code' => 'FR',
            'password' => 'password-solide-1449', 'password_confirmation' => 'password-solide-1449',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return User::where('email', $email)->firstOrFail();
    }

    private function verify(User $user, ?string $raw): void
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
        $client = $raw === null ? $this->actingAs($user) : $this->actingAs($user)->asBrowser($raw);
        $client->get($url)->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /** @return array<int, string> */
    private function eventNames(): array
    {
        return AcquisitionEvent::query()->orderBy('created_at')->orderBy('id')->pluck('event')->all();
    }

    // ── 1. shortcut_opened ─────────────────────────────────────────────────

    public function test_opening_a_shortcut_journals_one_fact_per_opening_before_the_redirect_without_any_identity(): void
    {
        $this->get(route('shortcut', ['code' => 'demo']).'?utm_source=linkedin&utm_medium=<b>post&utm_campaign=hack&journey=autre', ['HTTP_REFERER' => 'https://www.linkedin.com/feed/'])
            ->assertStatus(302)->assertCookieMissing(GuestVisitorResolver::COOKIE);

        $event = AcquisitionEvent::query()->sole();
        $this->assertSame(AcquisitionEvent::SHORTCUT_OPENED, $event->event);
        $this->assertSame($this->a->id, $event->organization_id);
        $this->assertSame($this->accountJourney->id, $event->acquisition_journey_id, 'la Journey en version EXACTE publiee a cet instant, resolue en base — jamais `?journey=`');
        $this->assertSame('demo', $event->shortcut);
        $this->assertSame('linkedin', $event->utm_source);
        $this->assertSame('bpost', $event->utm_medium, 'UTM nettoye (allowlist de caracteres)');
        $this->assertSame('sept-2026', $event->utm_campaign, 'la campagne canonique du Shortcut prime sur l\'UTM externe');
        $this->assertSame('https://www.linkedin.com/feed/', $event->referrer);
        $this->assertSame('fr', $event->locale);
        $this->assertNull($event->guest_visitor_id, 'aucun Guest a l\'ouverture');
        $this->assertNull($event->guest_conversation_id);
        $this->assertNull($event->user_id);
        $this->assertNull($event->dedupe_key, 'une ouverture est un fait REPETABLE');
        $this->assertSame(0, GuestVisitor::count(), 'aucune identite creee');
        $this->assertStringNotContainsString('127.0.0.1', json_encode($event->getAttributes()), 'aucune IP persistee');

        // Une seconde ouverture = une seconde ligne (on mesure les ouvertures).
        $this->get(route('shortcut', ['code' => 'demo']))->assertStatus(302);
        $this->assertSame(2, AcquisitionEvent::count());

        // Inconnu ou inactif : 404 et RIEN n'est journalise.
        $this->get(route('shortcut', ['code' => 'inconnu']))->assertNotFound();
        $this->get(route('shortcut', ['code' => 'off']))->assertNotFound();
        $this->assertSame(2, AcquisitionEvent::count());

        // Un Shortcut sans Journey : le fait existe, sans Journey.
        $this->get(route('shortcut', ['code' => 'news']))->assertStatus(302);
        $this->assertSame($this->contactJourney->id, AcquisitionEvent::query()->latest('created_at')->orderByDesc('id')->first()?->acquisition_journey_id);
    }

    public function test_a_broken_journal_never_breaks_the_redirect(): void
    {
        $this->instance(AcquisitionEventRecorder::class, new class extends AcquisitionEventRecorder
        {
            public function record(Organization $organization, string $event, array $dimensions = [], array $metadata = [], ?string $dedupeKey = null): ?AcquisitionEvent
            {
                throw new RuntimeException('journal down');
            }
        });

        $response = $this->get(route('shortcut', ['code' => 'demo']))->assertStatus(302);
        $this->assertStringStartsWith(route('organization.home', ['organization' => $this->a->slug]), (string) $response->headers->get('Location'));
        $this->assertSame(0, AcquisitionEvent::count());
    }

    // ── 2. guest_created / conversation_started ────────────────────────────

    public function test_the_first_guest_gesture_journals_guest_created_and_conversation_started_once_with_the_visitor_attribution(): void
    {
        $raw = Str::random(64);
        $this->message($raw);
        $visitor = $this->visitorOf($raw);
        $conversation = GuestConversation::query()->sole();

        $this->assertSame([AcquisitionEvent::GUEST_CREATED, AcquisitionEvent::CONVERSATION_STARTED], $this->eventNames());
        $created = AcquisitionEvent::query()->where('event', AcquisitionEvent::GUEST_CREATED)->sole();
        $this->assertSame($visitor->id, $created->guest_visitor_id);
        $this->assertSame($this->accountJourney->id, $created->acquisition_journey_id, 'l\'attribution du visiteur, relue en base (TASK-1447)');
        $this->assertSame('demo', $created->shortcut);
        $this->assertSame('newsletter', $created->utm_source);
        $this->assertSame('sept-2026', $created->utm_campaign);
        $this->assertSame(AcquisitionEvent::GUEST_CREATED.':visitor:'.$visitor->id, $created->dedupe_key);
        $this->assertNull($created->user_id);
        $started = AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERSATION_STARTED)->sole();
        $this->assertSame($conversation->id, $started->guest_conversation_id);
        $this->assertSame($visitor->id, $started->guest_visitor_id);
        $this->assertSame($this->accountJourney->id, $started->acquisition_journey_id);

        // Le second message du MEME visiteur : ni nouveau Guest ni nouvelle conversation, donc aucun fait.
        $this->message($raw, ['shortcut' => 'news']);
        $this->assertSame(2, AcquisitionEvent::count());
        $this->assertSame($this->accountJourney->id, $visitor->fresh()->acquisition_journey_id, 'first touch wins');

        // Un autre visiteur : ses deux faits a lui, dans la meme Organization.
        $this->message(Str::random(64), []);
        $this->assertSame(4, AcquisitionEvent::count());
        $this->assertSame(4, AcquisitionEvent::query()->where('organization_id', $this->a->id)->count());
    }

    // ── 3. signup_started ──────────────────────────────────────────────────

    public function test_showing_the_signup_form_journals_signup_started_once_per_visitor_without_creating_any_identity(): void
    {
        $raw = Str::random(64);
        $this->message($raw);
        $visitor = $this->visitorOf($raw);
        $before = AcquisitionEvent::count();

        $this->asBrowser($raw)->get(route('organization.register', ['organization' => $this->a->slug]))->assertOk();
        $event = AcquisitionEvent::query()->where('event', AcquisitionEvent::SIGNUP_STARTED)->sole();
        $this->assertSame($visitor->id, $event->guest_visitor_id);
        $this->assertSame($this->accountJourney->id, $event->acquisition_journey_id);
        $this->assertSame('demo', $event->shortcut);

        $this->asBrowser($raw)->get(route('organization.register', ['organization' => $this->a->slug]))->assertOk();
        $this->assertSame($before + 1, AcquisitionEvent::count(), 'un fait par visiteur, pas par affichage');

        // Sans cookie (un autre navigateur) : aucun visiteur, aucun fait, aucune identite creee.
        $this->forgetAuth();
        $this->get(route('organization.register', ['organization' => $this->a->slug]))->assertOk()->assertCookieMissing(GuestVisitorResolver::COOKIE);
        $this->assertSame($before + 1, AcquisitionEvent::count());
        $this->assertSame(1, GuestVisitor::count());
    }

    // ── 4. account_created ─────────────────────────────────────────────────

    public function test_registration_journals_account_created_with_the_guest_provenance_and_a_replay_writes_nothing(): void
    {
        $raw = Str::random(64);
        $this->message($raw);
        $visitor = $this->visitorOf($raw);

        $user = $this->register('nouvelle@example.test', $raw);

        $event = AcquisitionEvent::query()->where('event', AcquisitionEvent::ACCOUNT_CREATED)->sole();
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame($visitor->id, $event->guest_visitor_id, 'la provenance survit Guest -> User');
        $this->assertSame($this->accountJourney->id, $event->acquisition_journey_id);
        $this->assertSame('demo', $event->shortcut);
        $this->assertSame('newsletter', $event->utm_source);
        $this->assertSame($this->a->id, $event->organization_id);
        $this->assertSame(AcquisitionEvent::ACCOUNT_CREATED.':user:'.$user->id, $event->dedupe_key);
        $this->assertNull($visitor->fresh()->claimed_user_id, 'l\'inscription ne claim pas (SW-11 : au Verified seulement)');

        // Rejeu de l'evenement Laravel (double-submit, listener rejoue) : une seule ligne.
        event(new Registered($user));
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::ACCOUNT_CREATED)->count());

        // Sans cookie : le fait existe, sans visiteur ni provenance.
        $other = $this->register('sans-cookie@example.test', null);
        $bare = AcquisitionEvent::query()->where('event', AcquisitionEvent::ACCOUNT_CREATED)->where('user_id', $other->id)->sole();
        $this->assertNull($bare->guest_visitor_id);
        $this->assertNull($bare->acquisition_journey_id);
        $this->assertNull($bare->shortcut);
    }

    // ── 5. email_verified / converted ──────────────────────────────────────

    public function test_verification_journals_email_verified_once_and_converted_only_when_the_journey_contract_is_met(): void
    {
        // Visiteur venu par `demo` (Journey `rentree`, objectif ACCOUNT).
        $rawAccount = Str::random(64);
        $this->message($rawAccount);
        $visitorAccount = $this->visitorOf($rawAccount);
        $userAccount = $this->register('compte@example.test', $rawAccount);
        $this->verify($userAccount, $rawAccount);

        $this->assertSame($userAccount->id, $visitorAccount->fresh()->claimed_user_id, 'le claim SW-11 reste intact');
        $verified = AcquisitionEvent::query()->where('event', AcquisitionEvent::EMAIL_VERIFIED)->sole();
        $this->assertSame($userAccount->id, $verified->user_id);
        $this->assertSame($visitorAccount->id, $verified->guest_visitor_id);
        $this->assertSame($this->accountJourney->id, $verified->acquisition_journey_id);
        $converted = AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->sole();
        $this->assertSame($userAccount->id, $converted->user_id);
        $this->assertSame($visitorAccount->id, $converted->guest_visitor_id);
        $this->assertSame($this->accountJourney->id, $converted->acquisition_journey_id);
        $this->assertSame(['goal' => AcquisitionJourney::GOAL_ACCOUNT], $converted->metadata);
        $this->assertSame('demo', $converted->shortcut, 'la conversion porte sa provenance first touch');

        // Rejeu du Verified : rien de plus.
        event(new Verified($userAccount->fresh()));
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::EMAIL_VERIFIED)->count());
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->count());

        // Visiteur venu par `news` (Journey `newsletter`, objectif CONTACT) : verifie, mais PAS converti ici.
        $rawContact = Str::random(64);
        $this->message($rawContact, ['shortcut' => 'news']);
        $userContact = $this->register('contact@example.test', $rawContact);
        $this->verify($userContact, $rawContact);
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::EMAIL_VERIFIED)->where('user_id', $userContact->id)->count());
        $this->assertSame(0, AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->where('user_id', $userContact->id)->count(), 'converted = le contrat de la Journey, pas la verification');

        // Sans cookie : verifie, sans Journey, sans conversion.
        $userBare = $this->register('seul@example.test', null);
        $this->verify($userBare, null);
        $bare = AcquisitionEvent::query()->where('event', AcquisitionEvent::EMAIL_VERIFIED)->where('user_id', $userBare->id)->sole();
        $this->assertNull($bare->guest_visitor_id);
        $this->assertNull($bare->acquisition_journey_id);
        $this->assertSame(0, AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->where('user_id', $userBare->id)->count());
    }

    // ── 6. Le recorder ─────────────────────────────────────────────────────

    public function test_the_recorder_is_bounded_tenant_fail_closed_and_append_only(): void
    {
        $this->assertSame([
            'shortcut_opened', 'guest_created', 'conversation_started', 'cta_shown', 'workshop_viewed', 'session_selected',
            'signup_started', 'account_created', 'email_verified', 'participation_confirmed', 'crm_contact_linked', 'converted',
        ], AcquisitionEvent::EVENTS, 'les 12 evenements du CDC V3 §5, verbatim');

        $recorder = app(AcquisitionEventRecorder::class);

        try {
            $recorder->record($this->a, 'page_viewed');
            $this->fail('un evenement hors CDC est une faute de code');
        } catch (LogicException) {
        }

        $visitorB = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->b);
        try {
            $recorder->record($this->a, AcquisitionEvent::GUEST_CREATED, ['visitor' => $visitorB]);
            $this->fail('un visiteur d\'une autre Organization est une faute de code');
        } catch (LogicException) {
        }
        try {
            $recorder->record($this->a, AcquisitionEvent::ACCOUNT_CREATED, ['user' => $this->superAdmin]);
            $this->fail('un User d\'une autre Organization est une faute de code');
        } catch (LogicException) {
        }
        try {
            $recorder->record($this->b, AcquisitionEvent::SHORTCUT_OPENED, ['journey' => $this->accountJourney]);
            $this->fail('une Journey d\'une autre Organization est une faute de code');
        } catch (LogicException) {
        }
        $this->assertSame(0, AcquisitionEvent::count(), 'une faute n\'ecrit rien');

        // Bornes : referrer 500, UTM 100, shortcut 32, metadata 20 cles x 200 caracteres scalaires.
        $metadata = ['nested' => ['x' => 1], 'long' => str_repeat('v', 300)];
        foreach (range(1, 25) as $i) {
            $metadata["k{$i}"] = "v{$i}";
        }
        $event = $recorder->record($this->a, AcquisitionEvent::CTA_SHOWN, [
            'referrer' => str_repeat('r', 600), 'utm_source' => str_repeat('s', 150), 'shortcut' => str_repeat('c', 40), 'locale' => 'fr-FR-x',
        ], $metadata);
        $this->assertNotNull($event);
        $this->assertSame(500, mb_strlen($event->referrer));
        $this->assertSame(100, mb_strlen($event->utm_source));
        $this->assertSame(32, mb_strlen($event->shortcut));
        $this->assertSame('fr-FR', $event->locale);
        $this->assertArrayNotHasKey('nested', $event->metadata, 'scalaires seulement');
        $this->assertSame(200, mb_strlen($event->metadata['long']));
        $this->assertCount(AcquisitionEvent::METADATA_MAX_KEYS, $event->metadata);

        // Deduplication : la meme cle n'ecrit qu'une fois, et repond null la seconde.
        $first = $recorder->record($this->a, AcquisitionEvent::CTA_SHOWN, [], [], 'cta_shown:test:1');
        $second = $recorder->record($this->a, AcquisitionEvent::CTA_SHOWN, [], [], 'cta_shown:test:1');
        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(2, AcquisitionEvent::count());

        // Append-only : ni mise a jour ni suppression.
        try {
            $first->update(['event' => AcquisitionEvent::CONVERTED]);
            $this->fail('un fait ne se reecrit pas');
        } catch (LogicException) {
        }
        try {
            $first->delete();
            $this->fail('un fait ne se supprime pas');
        } catch (LogicException) {
        }
        $this->assertSame(AcquisitionEvent::CTA_SHOWN, $first->fresh()->event);
        $this->assertSame(2, AcquisitionEvent::count());
    }
}
