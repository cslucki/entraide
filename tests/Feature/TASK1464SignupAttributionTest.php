<?php

namespace Tests\Feature;

use App\Models\AcquisitionEvent;
use App\Models\AcquisitionJourney;
use App\Models\CrmContact;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationShortcut;
use App\Models\User;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\GuestShell\GuestIdentityThrottle;
use App\Services\GuestShell\GuestVisitorResolver;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-1464 — audit OPUS final P1-2 (Growth V3 §5) : la campagne « lien court →
 * inscription » a un porteur. Au POST d'inscription SEULEMENT (afficher le
 * formulaire ne cree rien) : sans cookie Guest de cette Organization et avec une
 * attribution valide, l'identite Guest nait apres la garde de creation
 * pre-identite (F1), attribution RELUE EN BASE (jamais crue sur parole) ; puis
 * `signup_started`, `account_created` avec provenance, le claim SW-11, `converted`
 * (objectif `account`) et le Contact CRM (source honnete) suivent sans autre
 * changement. Sans attribution : aucune identite. Une identite existante n'est
 * jamais doublee. Un throttle sature ou une panne ne cassent jamais l'inscription.
 */
class TASK1464SignupAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private User $adminA;

    private User $superAdmin;

    private AcquisitionJourney $journey;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.identity_rate_limit_per_minute' => 30]);
        $this->a = Organization::factory()->create(['slug' => 'org-a-1464', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $b = Organization::factory()->create(['slug' => 'org-b-1464', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $b->id, 'is_admin' => true]);

        $journeys = app(AcquisitionJourneyService::class);
        $this->journey = $journeys->createDraft($this->a, ['key' => 'inscription', 'name' => 'Inscription directe', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_ACCOUNT, 'campaign' => 'sept-2026'], $this->adminA);
        $journeys->publish($this->journey, $this->adminA);
        OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'inscrire', 'destination' => 'signup', 'acquisition_journey_key' => 'inscription', 'campaign' => 'sept-2026', 'active' => true, 'created_by' => $this->superAdmin->id]);
        RateLimiter::clear(GuestIdentityThrottle::key($this->a));
    }

    private function forgetAuth(): void
    {
        auth()->logout();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        Cookie::unqueue(GuestVisitorResolver::COOKIE);
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    /** @param  array<string, string>  $attribution */
    private function signup(string $email, array $attribution = [], ?string $raw = null): TestResponse
    {
        $this->forgetAuth();
        $client = $raw === null ? $this->withCredentials() : $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie($raw));
        $payload = ['name' => 'Nouvelle', 'first_name' => 'Membre', 'email' => $email, 'phone' => '+33600000000', 'country_code' => 'FR', 'password' => 'password-solide-1464', 'password_confirmation' => 'password-solide-1464'];
        if ($attribution !== []) {
            $payload['attribution'] = $attribution;
        }

        return $client->post(route('organization.register', ['organization' => $this->a->slug]), $payload);
    }

    /** La cle BRUTE du cookie Guest pose par une reponse (le middleware l'a chiffree ; T1445). */
    private function guestCookie(TestResponse $response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestVisitorResolver::COOKIE) {
                return CookieValuePrefix::remove(app('encrypter')->decrypt((string) $cookie->getValue(), false));
            }
        }

        return null;
    }

    public function test_a_short_link_to_signup_gives_the_account_a_first_touch_at_the_post_only_and_everything_downstream_follows(): void
    {
        // /s/go → 302 vers le formulaire d'inscription avec l'attribution dans l'URL ; l'AFFICHAGE ne cree rien.
        $this->forgetAuth();
        $landing = $this->withCredentials()->get('/s/inscrire')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(route('organization.register', ['organization' => $this->a->slug]), $landing);
        $form = $this->withCredentials()->get($landing.'&journey=falsifiee&campaign=falsifiee&utm_source=newsletter')->assertOk();
        $this->assertNull($this->guestCookie($form), 'afficher le formulaire ne cree aucune identite');
        $this->assertSame(0, GuestVisitor::query()->count());
        $this->assertStringContainsString('name="attribution[shortcut]" value="inscrire"', $form->getContent());
        $this->assertStringContainsString('name="attribution[utm_source]" value="newsletter"', $form->getContent());
        $this->assertStringNotContainsString('attribution[journey]', $form->getContent(), 'le formulaire ne transporte jamais une Journey');

        // Le POST : l'identite nait, attribuee par le Shortcut RELU EN BASE (la Journey de l'URL est ignoree), le cookie est pose.
        $response = $this->signup('go@example.test', ['shortcut' => 'inscrire', 'utm_source' => 'newsletter', 'utm_campaign' => 'externe'])->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', 'go@example.test')->firstOrFail();
        $visitor = GuestVisitor::forOrganization($this->a)->sole();
        $this->assertSame([$this->journey->id, 'sept-2026', 'inscrire', 'newsletter'], [$visitor->acquisition_journey_id, $visitor->utm_campaign, $visitor->shortcut, $visitor->utm_source], 'Journey exacte + campagne canonique du Shortcut, jamais l\'URL');
        $raw = $this->guestCookie($response);
        $this->assertNotNull($raw, 'le cookie Guest est pose au POST');
        $this->assertSame($visitor->id, app(GuestVisitorResolver::class)->find(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $raw]), $this->a)?->id);

        // Le journal : guest_created (surface signup), signup_started, account_created — tous avec la provenance.
        $events = AcquisitionEvent::query()->where('guest_visitor_id', $visitor->id)->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame([AcquisitionEvent::GUEST_CREATED, AcquisitionEvent::SIGNUP_STARTED, AcquisitionEvent::ACCOUNT_CREATED], $events->pluck('event')->all());
        $this->assertSame([$this->journey->id, $user->id], [$events->last()->acquisition_journey_id, $events->last()->user_id]);
        $this->assertSame(0, CrmContact::query()->count(), 'aucun Contact au simple signup');

        // La verification dans le MEME navigateur : claim, converted (objectif account, une fois par Journey + User), Contact CRM source honnete.
        $verifyUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
        $this->actingAs($user)->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie($raw))->get($verifyUrl)->assertRedirect();
        $this->assertSame($user->id, $visitor->fresh()->claimed_user_id, 'claim SW-11');
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::CONVERTED)->where('user_id', $user->id)->where('acquisition_journey_id', $this->journey->id)->count(), 'converted : le contrat de la Journey (account) est atteint');
        $contact = CrmContact::query()->sole();
        $this->assertSame([$user->id, CrmContact::SOURCE_SIGNUP, $visitor->id], [$contact->user_id, $contact->source, $contact->source_ref], 'la source dit la verite : ne au signup, jamais au Shell');
    }

    public function test_no_attribution_means_no_identity_an_existing_guest_is_never_doubled_and_the_browser_is_never_the_authority(): void
    {
        // Inscription directe (aucune attribution) : aucune identite, aucun cookie, journal sans provenance — inchange.
        $bare = $this->signup('seul@example.test')->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull($this->guestCookie($bare));
        $this->assertSame(0, GuestVisitor::query()->count());
        $this->assertSame(1, AcquisitionEvent::query()->where('event', AcquisitionEvent::ACCOUNT_CREATED)->whereNull('guest_visitor_id')->count());

        // Champs vides ou code invalide / inconnu / d'une autre Organization : aucune identite.
        $this->signup('vide@example.test', ['shortcut' => '', 'utm_source' => ''])->assertSessionHasNoErrors();
        $this->signup('inconnu@example.test', ['shortcut' => 'nexiste-pas'])->assertSessionHasNoErrors();
        $this->assertSame(0, GuestVisitor::query()->count(), 'un code qui ne se relit pas en base ne fabrique rien');

        // Un visiteur EXISTANT (cookie) n'est jamais double, meme avec une attribution differente : first touch wins.
        $raw = Str::random(64);
        $existing = app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $raw]), $this->a, ['utm_campaign' => 'premier']);
        $this->signup('existant@example.test', ['shortcut' => 'inscrire'], $raw)->assertSessionHasNoErrors();
        $this->assertSame(1, GuestVisitor::query()->count());
        $this->assertSame('premier', $existing->fresh()->utm_campaign, 'le first touch n\'est jamais reecrit');
        $this->assertSame($existing->id, AcquisitionEvent::query()->where('event', AcquisitionEvent::ACCOUNT_CREATED)->where('user_id', User::where('email', 'existant@example.test')->value('id'))->value('guest_visitor_id'));
    }

    public function test_a_saturated_creation_throttle_or_a_broken_identity_never_breaks_the_signup(): void
    {
        config(['ai.guest_shell.identity_rate_limit_per_minute' => 1]);
        RateLimiter::clear(GuestIdentityThrottle::key($this->a));
        // Un visiteur EXISTANT qui s'inscrit ne consomme pas la garde de CREATION (ce n'est pas une creation) : la seule place de la minute reste libre.
        $raw = Str::random(64);
        app(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $raw]), $this->a, ['utm_campaign' => 'premier']);
        $this->signup('existant@example.test', ['shortcut' => 'inscrire'], $raw)->assertSessionHasNoErrors();
        $this->assertSame(1, GuestVisitor::query()->count());
        $this->signup('un@example.test', ['shortcut' => 'inscrire'])->assertSessionHasNoErrors();
        $this->assertSame(2, GuestVisitor::query()->count(), 'la place de la minute etait encore libre : l\'identite nait');
        // La 2e CREATION de la minute est refusee : l'inscription passe quand meme, sans identite (F1 = garde de creation, jamais un refus d'inscription).
        $this->signup('deux@example.test', ['shortcut' => 'inscrire'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, GuestVisitor::query()->count());
        $this->assertNotNull(User::where('email', 'deux@example.test')->first());
        $this->assertSame(3, User::query()->whereIn('email', ['existant@example.test', 'un@example.test', 'deux@example.test'])->count());

        // Configuration absente = fail-closed pour l'identite, jamais pour l'inscription.
        config(['ai.guest_shell.identity_rate_limit_per_minute' => null]);
        RateLimiter::clear(GuestIdentityThrottle::key($this->a));
        $this->signup('trois@example.test', ['shortcut' => 'inscrire'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(2, GuestVisitor::query()->count());
    }
}
