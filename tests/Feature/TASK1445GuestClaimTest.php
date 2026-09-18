<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Listeners\ClaimGuestVisitorOnVerification;
use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\GuestShell\GuestClaimService;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1445 — SW-11 : claim Guest → User (Shell Welcome V3 §22). Jamais
 * d'auto-creation de User ; inscription normale ; claim APRES email verifie,
 * pour le visiteur de la MEME Organization ; conversation + attribution
 * conservees ; cookie d'un autre tenant = fail-closed sans un mot ; apres le
 * claim, l'ancienne cle ne resout plus rien.
 */
class TASK1445GuestClaimTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $policies = app(GuestShellPolicyService::class);
        foreach (['a', 'b'] as $key) {
            $org = Organization::factory()->create(['slug' => "org-{$key}-14xx", 'name' => strtoupper($key).' Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
            $policies->update($org, ['enabled' => true, 'max_messages' => 5]);
            OrganizationAiSetting::create(['organization_id' => $org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
            $this->{$key} = $org;
        }
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    private function asBrowser(string $raw): static
    {
        return $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie($raw));
    }

    /** Un visiteur qui a parle (avec attribution), tel que SW-8a le produit — depuis TASK-1447 l'attribution voyage dans le payload borne du premier geste (allowlist UTM), plus dans la query du POST. */
    private function talkingVisitor(Organization $organization, string $raw): GuestVisitor
    {
        GuestShellAgent::fake([new TextResponse('Bienvenue.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->asBrowser($raw)->postJson(route('organization.shell.message', ['organization' => $organization->slug]), ['message' => 'Bonjour, je cherche un freelance', 'attribution' => ['utm_source' => 'newsletter', 'utm_campaign' => 'sept']])->assertOk()->assertJsonPath('turn', 'answered');

        return GuestVisitor::forOrganization($organization)->where('visitor_key_hash', GuestVisitorResolver::hash($raw))->firstOrFail();
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]);
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

    // ── 1. Le parcours nominal ─────────────────────────────────────────────

    public function test_registration_never_claims_and_the_verified_email_claims_the_visitor_of_the_same_organization(): void
    {
        $raw = Str::random(64);
        $visitor = $this->talkingVisitor($this->a, $raw);
        $conversation = GuestConversation::firstOrFail();
        $this->assertSame('newsletter', $visitor->utm_source);

        // Inscription normale, avec le cookie : aucune auto-creation, aucun claim a ce stade.
        $users = User::count();
        $this->asBrowser($raw)->post(route('organization.register', ['organization' => $this->a->slug]), [
            'name' => 'Nouvelle Membre', 'first_name' => 'Nouvelle', 'email' => 'nouvelle@example.test', 'phone' => '+33600000000', 'country_code' => 'FR',
            'password' => 'password-solide-14xx', 'password_confirmation' => 'password-solide-14xx',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $user = User::where('email', 'nouvelle@example.test')->firstOrFail();
        $this->assertSame($users + 1, User::count(), 'un seul compte, cree par l\'inscription');
        $this->assertSame($this->a->id, $user->organization_id);
        $this->assertNull($visitor->fresh()->claimed_user_id, 'pas de claim avant l\'email verifie');

        // Clic sur le lien de verification, meme navigateur (cookie present).
        $response = $this->actingAs($user)->asBrowser($raw)->get($this->verificationUrl($user))->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at);

        $claimed = $visitor->fresh();
        $this->assertSame($user->id, $claimed->claimed_user_id);
        $this->assertNotNull($claimed->claimed_at);
        $this->assertSame($user->id, $conversation->fresh()->claimed_user_id, 'la conversation suit');
        $this->assertNotNull($conversation->fresh()->claimed_at);
        $this->assertSame(2, GuestMessage::count(), 'les messages sont conserves');
        $this->assertSame('newsletter', $claimed->utm_source, 'l\'attribution est conservee');
        $this->assertSame('sept', $claimed->utm_campaign);
        $this->assertSame(1, GuestVisitor::count(), 'aucune ligne creee, aucune supprimee');

        // L'ancienne cle ne resout plus rien, meme capturee ; le navigateur recoit une cle neuve, vierge.
        $this->assertNotSame(GuestVisitorResolver::hash($raw), $claimed->visitor_key_hash, 'hash brule');
        $fresh = $this->rawGuestCookieOf($response);
        $this->assertNotNull($fresh, 'rotation du cookie');
        $this->assertNotSame($raw, $fresh);
        $this->assertNull($this->asBrowser($raw)->getJson(route('organization.shell.show', ['organization' => $this->a->slug]))->assertOk()->json('conversation'), 'ancienne cle : rien');
        $this->assertNull($this->asBrowser($fresh)->getJson(route('organization.shell.show', ['organization' => $this->a->slug]))->assertOk()->json('conversation'), 'nouvelle cle : vierge');
        $this->assertSame(1, GuestVisitor::count());
    }

    // ── 2. Fail-closed ─────────────────────────────────────────────────────

    public function test_a_cookie_of_another_organization_claims_nothing_and_reveals_nothing(): void
    {
        $raw = Str::random(64);
        $visitor = $this->talkingVisitor($this->b, $raw);
        $user = User::factory()->unverified()->create(['organization_id' => $this->a->id]);

        $with = $this->actingAs($user)->asBrowser($raw)->get($this->verificationUrl($user))->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at, 'la verification reste normale');
        $this->assertNull($visitor->fresh()->claimed_user_id, 'le visiteur de B reste intact');
        $this->assertSame(GuestVisitorResolver::hash($raw), $visitor->fresh()->visitor_key_hash, 'ni brule');
        $this->assertNull($this->rawGuestCookieOf($with), 'aucune rotation : rien ne s\'est passe, rien n\'est revele');
        $this->assertSame(1, GuestVisitor::count());
    }

    public function test_an_already_claimed_visitor_a_missing_cookie_or_a_user_without_organization_claim_nothing(): void
    {
        // Defense en profondeur : meme appele directement avec un cookie valide, le service exige un email VERIFIE.
        $rawFresh = Str::random(64);
        $unclaimed = $this->talkingVisitor($this->a, $rawFresh);
        $unverified = User::factory()->unverified()->create(['organization_id' => $this->a->id]);
        $request = Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $rawFresh]);
        $this->assertNull(app(GuestClaimService::class)->claim($unverified->fresh(), $request), 'email non verifie : rien');
        $this->assertNull($unclaimed->fresh()->claimed_user_id);

        $raw = Str::random(64);
        $visitor = $this->talkingVisitor($this->a, $raw);
        $first = User::factory()->create(['organization_id' => $this->a->id]);
        $visitor->forceFill(['claimed_user_id' => $first->id, 'claimed_at' => now()])->save();

        $second = User::factory()->unverified()->create(['organization_id' => $this->a->id]);
        $this->actingAs($second)->asBrowser($raw)->get($this->verificationUrl($second))->assertRedirect();
        $this->assertSame($first->id, $visitor->fresh()->claimed_user_id, 'un visiteur claime ne change pas de main');

        $third = User::factory()->unverified()->create(['organization_id' => $this->a->id]);
        $this->actingAs($third)->get($this->verificationUrl($third))->assertRedirect();
        $this->assertNotNull($third->fresh()->email_verified_at);
        $this->assertSame(2, GuestVisitor::count(), 'sans cookie : rien (les deux visiteurs du test restent seuls)');

        $orphan = User::factory()->unverified()->create(['organization_id' => null]);
        $this->assertNull(app(GuestClaimService::class)->claim($orphan->fresh(), request()), 'sans Organization : rien');
    }

    public function test_a_failed_claim_transaction_never_burns_nor_rotates_the_cookie(): void
    {
        $raw = Str::random(64);
        $visitor = $this->talkingVisitor($this->a, $raw);
        $user = User::factory()->create(['organization_id' => $this->a->id]);
        $request = Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $raw]);

        // La transaction echoue au moment d'ecrire le visiteur : rien ne doit avoir tourne.
        GuestVisitor::saving(function (GuestVisitor $saving) use ($visitor): void {
            if ($saving->is($visitor) && $saving->isDirty('claimed_user_id')) {
                throw new \RuntimeException('claim storage failure');
            }
        });

        try {
            app(GuestClaimService::class)->claim($user, $request);
            $this->fail('la transaction devait echouer');
        } catch (\RuntimeException $e) {
            $this->assertSame('claim storage failure', $e->getMessage());
        }

        $queued = collect(Cookie::getQueuedCookies())->map(fn ($c) => $c->getName())->all();
        $this->assertNotContains(GuestVisitorResolver::COOKIE, $queued, 'aucune rotation quand le claim echoue');
        $fresh = $visitor->fresh();
        $this->assertNull($fresh->claimed_user_id);
        $this->assertSame(GuestVisitorResolver::hash($raw), $fresh->visitor_key_hash, 'l\'ancienne cle reste resolvable');
        $this->assertNull(GuestConversation::firstOrFail()->claimed_user_id);
    }

    public function test_the_claim_is_a_listener_on_verified_that_never_breaks_the_verification_and_never_creates_a_user(): void
    {
        // Le listener est DECOUVERT (app/Listeners), jamais Event::listen — mesure sur le vrai dispatcher.
        $listeners = collect(app('events')->getRawListeners()[Verified::class] ?? [])
            ->map(fn ($listener) => is_string($listener) ? $listener : (is_object($listener) ? get_class($listener) : ''))
            ->filter(fn (string $listener) => str_starts_with($listener, ClaimGuestVisitorOnVerification::class));
        $this->assertCount(1, $listeners, 'ClaimGuestVisitorOnVerification ecoute Verified, une seule fois');

        // Un claim qui explose ne casse pas la verification (rescue()).
        $this->mock(GuestClaimService::class)->shouldReceive('claim')->andThrow(new \RuntimeException('boom'));
        $other = User::factory()->unverified()->create(['organization_id' => $this->a->id]);
        $this->actingAs($other)->get($this->verificationUrl($other))->assertRedirect();
        $this->assertNotNull($other->fresh()->email_verified_at, 'verification intacte malgre l\'echec du claim');

        $this->assertSame(0, GuestVisitor::whereNotNull('claimed_user_id')->count());
    }

    // ── 3. Authentifie = experience membre uniquement ──────────────────────

    public function test_an_authenticated_user_never_gets_the_guest_shell_neither_rendered_nor_served(): void
    {
        $member = User::factory()->create(['organization_id' => $this->a->id]);
        $raw = Str::random(64);
        $this->talkingVisitor($this->a, $raw);

        $html = $this->actingAs($member)->asBrowser($raw)->get(route('organization.home', ['organization' => $this->a->slug]))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="bp-guest-shell"', $html, 'aucun widget Guest pour un membre connecte');

        $json = $this->actingAs($member)->asBrowser($raw)->getJson(route('organization.shell.show', ['organization' => $this->a->slug]))->assertOk()->json();
        $this->assertFalse($json['display']['visible']);
        $this->assertSame('authenticated', $json['display']['reason']);
        $this->assertFalse($json['display']['degraded']);
        $this->assertNull($json['conversation'], 'meme avec le cookie, rien n\'est relu');

        $this->actingAs($member)->postJson(route('organization.shell.message', ['organization' => $this->a->slug]), ['message' => 'Bonjour'])->assertOk()
            ->assertJsonPath('turn', 'unavailable')->assertJsonPath('reason', 'authenticated')->assertCookieMissing(GuestVisitorResolver::COOKIE);
        $this->assertSame(1, GuestVisitor::count(), 'aucune identite Guest creee pour un membre');
        $this->assertSame(1, GuestConversation::count());
        // La fausse IA garde les appels precedents : la mesure est le ledger et les messages, inchanges depuis talkingVisitor().
        $this->assertSame(1, AiProviderInvocation::count(), 'aucune invocation Guest pour un membre');
        $this->assertSame(2, GuestMessage::count());
    }
}
