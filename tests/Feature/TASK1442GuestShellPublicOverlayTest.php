<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestMessage;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\GuestShell\GuestConversationService;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestShellSurface;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Support\GuestShell\GuestShellDisplay;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1442 — SW-8a : le runtime public du Shell Welcome + l'OVERLAY (V3 §19,
 * MASTER Q70). Simple visite = rien ; premier geste = identite + cookie +
 * conversation + reponse + ledger ; Shell indisponible = aucune identite ;
 * refus = honnete, jamais une reponse fabriquee ; Shell membre intact.
 */
class TASK1442GuestShellPublicOverlayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $ready;

    private Organization $disabled;

    private Organization $degraded;

    private Organization $private;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
            'ai.guest_shell.visitor_monthly_max_messages' => 30,
            'ai.guest_shell.rate_limit_per_minute' => 6,
        ]);
        $policies = app(GuestShellPolicyService::class);

        $this->ready = Organization::factory()->create(['slug' => 'org-ready-14xx', 'name' => 'Ready Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'hero_title' => 'Entraide entre freelances']);
        $policies->update($this->ready, ['enabled' => true, 'max_messages' => 5, 'display_mode' => 'overlay']);
        OrganizationAiSetting::create(['organization_id' => $this->ready->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-not-a-real-key', 'is_enabled' => true]);

        $this->disabled = Organization::factory()->create(['slug' => 'org-off-14xx', 'name' => 'Off Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);

        $this->degraded = Organization::factory()->create(['slug' => 'org-degraded-14xx', 'name' => 'Degraded Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $policies->update($this->degraded, ['enabled' => true, 'max_messages' => 5]);

        $this->private = Organization::factory()->create(['slug' => 'org-private-14xx', 'name' => 'Private Guild', 'is_active' => true, 'is_public' => false, 'locale' => 'fr']);
        $policies->update($this->private, ['enabled' => true]);
    }

    private function home(Organization $organization): string
    {
        return route('organization.home', ['organization' => $organization->slug]);
    }

    private function endpoint(Organization $organization): string
    {
        return route('organization.shell.message', ['organization' => $organization->slug]);
    }

    /** La valeur CHIFFREE du cookie bp_guest posee par une reponse — rejouee telle quelle, comme un navigateur. */
    private function guestCookie(TestResponse $response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === GuestVisitorResolver::COOKIE) {
                return (string) $cookie->getValue();
            }
        }

        return null;
    }

    /** La cle BRUTE derriere une valeur chiffree (EncryptCookies + CookieValuePrefix). */
    private function rawKey(string $encrypted): string
    {
        return CookieValuePrefix::remove(app('encrypter')->decrypt($encrypted, false));
    }

    /** Ce qu'un navigateur enverrait pour une cle brute connue. */
    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    /** Les requetes JSON de la suite de tests n'envoient les cookies qu'avec withCredentials(). */
    private function asBrowser(string $encryptedCookie): static
    {
        return $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $encryptedCookie);
    }

    // NB : les attributs data-guest-shell-* figurent aussi dans le script inline du widget ; les assertions visent l'ELEMENT rendu (`attr>`).
    private function assertNothingCreated(string $why): void
    {
        $this->assertSame(0, GuestVisitor::count(), $why);
        $this->assertSame(0, GuestConversation::count(), $why);
        $this->assertSame(0, GuestMessage::count(), $why);
        $this->assertSame(0, AiProviderInvocation::count(), $why);
    }

    // ── 1. La simple visite ────────────────────────────────────────────────

    public function test_a_simple_visit_creates_nothing_and_mounts_the_overlay_only_when_the_shell_is_available(): void
    {
        GuestShellAgent::fake([]);

        $html = $this->get($this->home($this->ready))->assertOk()->assertCookieMissing(GuestVisitorResolver::COOKIE)->getContent();
        $this->assertStringContainsString('data-guest-shell-state="live"', $html);
        $this->assertStringContainsString('data-guest-shell-mode="overlay"', $html);
        $this->assertStringContainsString('data-guest-shell-endpoint="'.$this->endpoint($this->ready).'"', $html);
        $this->assertStringContainsString('data-guest-shell-cta', $html);
        $this->assertStringContainsString(route('organization.register', ['organization' => $this->ready->slug]), $html, 'CTA compte via publicCta');
        $this->assertStringContainsString('data-guest-shell-welcome>', $html);
        $this->assertStringContainsString('<livewire:ai-shell', file_get_contents(resource_path('views/layouts/app.blade.php')), 'le Shell membre est intact');

        $off = $this->get($this->home($this->disabled))->assertOk()->assertCookieMissing(GuestVisitorResolver::COOKIE)->getContent();
        $this->assertStringNotContainsString('data-guest-shell', $off, 'enabled=false : rien du tout');

        $degraded = $this->get($this->home($this->degraded))->assertOk()->assertCookieMissing(GuestVisitorResolver::COOKIE)->getContent();
        $this->assertStringContainsString('data-guest-shell-state="degraded"', $degraded);
        $this->assertStringContainsString('data-guest-shell-reason="'.GuestShellDisplay::REASON_POLICY_NOT_READY.'"', $degraded);
        $this->assertStringContainsString('data-guest-shell-degraded>', $degraded, 'accueil non-IA honnete');
        $this->assertStringNotContainsString('data-guest-shell-welcome>', $degraded, 'aucune promesse d\'echange');
        $this->assertMatchesRegularExpression('/<textarea[^>]*data-guest-shell-input[^>]*disabled/', $degraded, 'saisie desactivee');
        $this->assertStringContainsString('data-guest-shell-cta', $degraded);

        // Les deux autres templates d'accueil montent le meme partial.
        $this->ready->update(['homepage_template' => 'bouclepro_hero_v2']);
        $this->assertStringContainsString('data-guest-shell-state="live"', $this->get($this->home($this->ready))->assertOk()->getContent());
        $this->ready->update(['homepage_template' => 'artscilab_hero']);
        $this->assertStringContainsString('data-guest-shell-state="live"', $this->get($this->home($this->ready))->assertOk()->getContent());

        $this->assertNothingCreated('une simple visite ne cree rien');
        GuestShellAgent::assertNeverPrompted();
    }

    // ── 2. La lecture ──────────────────────────────────────────────────────

    public function test_the_read_endpoint_never_creates_and_ignores_a_cookie_of_another_organization(): void
    {
        $url = route('organization.shell.show', ['organization' => $this->ready->slug]);
        $this->assertSame('/org/org-ready-14xx/shell', parse_url($url, PHP_URL_PATH));
        $json = $this->getJson($url)->assertOk()->assertCookieMissing(GuestVisitorResolver::COOKIE)->json();
        $this->assertTrue($json['display']['visible']);
        $this->assertSame('overlay', $json['display']['mode']);
        $this->assertNull($json['conversation']);
        $this->assertSame(route('organization.register', ['organization' => $this->ready->slug]), $json['cta']['url']);
        $this->assertNothingCreated('un GET ne cree rien');

        // Un visiteur d'une AUTRE Organization, avec sa conversation.
        $key = Str::random(64);
        $other = Organization::factory()->create(['slug' => 'org-other-14xx', 'is_active' => true, 'is_public' => true]);
        $visitor = app()->make(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => $key]), $other);
        app(GuestConversationService::class)->start($visitor);

        $json = $this->asBrowser($this->encryptedCookie($key))->getJson($url)->assertOk()->json();
        $this->assertNull($json['conversation'], 'le cookie d\'un autre tenant ne resout rien ici');
        $this->assertSame(1, GuestVisitor::count(), 'et n\'en cree pas un nouveau');

        $this->getJson(route('organization.shell.show', ['organization' => $this->private->slug]))->assertNotFound();
        $this->getJson(route('organization.shell.show', ['organization' => $this->disabled->slug]))->assertOk()->assertJsonPath('display.visible', false)->assertJsonPath('display.reason', GuestShellDisplay::REASON_DISABLED);
    }

    // ── 3. Le premier geste ────────────────────────────────────────────────

    public function test_the_first_message_creates_the_identity_answers_ledgers_once_and_the_second_message_resumes(): void
    {
        GuestShellAgent::fake([
            new TextResponse('Bienvenue chez Ready Guild !', new Usage(120, 40), new Meta('openrouter', 'openai/gpt-4o-mini')),
            new TextResponse('Avec plaisir.', new Usage(100, 20), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);

        $first = $this->postJson($this->endpoint($this->ready), ['message' => 'Bonjour, que faites-vous ?'])->assertOk();
        $first->assertCookie(GuestVisitorResolver::COOKIE);
        $first->assertJsonPath('turn', 'answered')->assertJsonPath('assistant.body', 'Bienvenue chez Ready Guild !')->assertJsonPath('user.body', 'Bonjour, que faites-vous ?')->assertJsonPath('conversation.message_count', 1)->assertJsonPath('conversation.remaining', 4);
        $this->assertSame(1, GuestVisitor::count());
        $this->assertSame(1, GuestConversation::count());
        $this->assertSame(2, GuestMessage::count());
        $this->assertSame(1, AiProviderInvocation::count(), 'un tour = une invocation');
        $this->assertSame($this->ready->id, GuestVisitor::firstOrFail()->organization_id);

        $cookie = $this->guestCookie($first);
        $this->assertNotNull($cookie);
        $this->assertSame(64, strlen($this->rawKey($cookie)), 'cle opaque de 64 caracteres, seul le sha256 est stocke');

        $second = $this->asBrowser($cookie)->postJson($this->endpoint($this->ready), ['message' => 'Merci !'])->assertOk();
        $second->assertJsonPath('turn', 'answered')->assertJsonPath('conversation.message_count', 2);
        $this->assertSame(1, GuestVisitor::count(), 'meme visiteur');
        $this->assertSame(1, GuestConversation::count(), 'meme conversation');
        $this->assertSame(2, AiProviderInvocation::count());
        $this->assertCount(4, $second->json('conversation.messages'));

        // Le GET de reprise relit la conversation avec le cookie, sans rien creer.
        $read = $this->asBrowser($cookie)->getJson(route('organization.shell.show', ['organization' => $this->ready->slug]))->assertOk()->json();
        $this->assertSame(2, $read['conversation']['message_count']);
        $this->assertSame(1, GuestVisitor::count());

        $html = $this->asBrowser($cookie)->get($this->home($this->ready))->assertOk()->getContent();
        $this->assertStringContainsString('data-guest-shell-message="user">', $html, 'le transcript Guest est rendu a la reprise');
        $this->assertStringContainsString('Bienvenue chez Ready Guild !', $html);
        $this->assertStringNotContainsString('data-guest-shell-welcome>', $html);
    }

    // ── 4. Shell indisponible : aucune identite ────────────────────────────

    public function test_a_post_to_an_unavailable_shell_creates_no_identity_and_calls_nothing(): void
    {
        GuestShellAgent::fake([]);

        $this->postJson($this->endpoint($this->degraded), ['message' => 'Bonjour'])->assertOk()
            ->assertCookieMissing(GuestVisitorResolver::COOKIE)
            ->assertJsonPath('turn', GuestShellSurface::TURN_UNAVAILABLE)
            ->assertJsonPath('reason', GuestShellDisplay::REASON_POLICY_NOT_READY)
            ->assertJsonPath('assistant', null);
        $this->postJson($this->endpoint($this->disabled), ['message' => 'Bonjour'])->assertOk()
            ->assertCookieMissing(GuestVisitorResolver::COOKIE)
            ->assertJsonPath('turn', GuestShellSurface::TURN_UNAVAILABLE)
            ->assertJsonPath('reason', GuestShellDisplay::REASON_DISABLED);
        $this->postJson($this->endpoint($this->private), ['message' => 'Bonjour'])->assertNotFound();

        $this->assertNothingCreated('un Shell indisponible ne cree jamais une identite');
        GuestShellAgent::assertNeverPrompted();
    }

    // ── 5. Refus et echec, honnetes ────────────────────────────────────────

    public function test_a_gate_refusal_is_honest_and_never_fabricates_an_answer(): void
    {
        app(GuestShellPolicyService::class)->update($this->ready, ['max_messages' => 1]);
        GuestShellAgent::fake([new TextResponse('Une seule reponse.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);

        $first = $this->postJson($this->endpoint($this->ready), ['message' => 'Premier'])->assertOk()->assertJsonPath('turn', 'answered');
        $cookie = $this->guestCookie($first);

        $second = $this->asBrowser($cookie)->postJson($this->endpoint($this->ready), ['message' => 'Deuxieme'])->assertOk();
        $second->assertJsonPath('turn', 'refused')->assertJsonPath('reason', 'max_messages_reached')->assertJsonPath('assistant', null)->assertJsonPath('user', null);
        $this->assertSame(1, AiProviderInvocation::count(), 'un refus = zero appel');
        $this->assertSame(2, GuestMessage::count(), 'le message refuse n\'est pas accepte');
        $this->assertSame(GuestConversation::STATUS_LIMIT_REACHED, GuestConversation::firstOrFail()->status);

        $html = $this->asBrowser($cookie)->get($this->home($this->ready))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<textarea[^>]*data-guest-shell-input[^>]*disabled/', $html, 'limite atteinte : saisie fermee');
        $this->assertStringContainsString('data-guest-shell-note>', $html);
    }

    public function test_a_provider_failure_is_reported_honestly_and_ledgered_as_failed(): void
    {
        GuestShellAgent::fake(fn () => throw new RuntimeException('upstream 502'));

        $response = $this->postJson($this->endpoint($this->ready), ['message' => 'Bonjour'])->assertOk();
        $response->assertJsonPath('turn', 'failed')->assertJsonPath('assistant.body', __('guest_shell.fallback_unavailable'));
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, AiProviderInvocation::firstOrFail()->status);
        $this->assertStringNotContainsString('upstream 502', $response->getContent(), 'aucun detail technique au visiteur');
    }

    // ── 6. Bornes et hygiene HTTP ──────────────────────────────────────────

    public function test_the_message_is_validated_and_the_routes_carry_the_public_guards(): void
    {
        GuestShellAgent::fake([]);
        $this->postJson($this->endpoint($this->ready), ['message' => ''])->assertUnprocessable();
        $this->postJson($this->endpoint($this->ready), ['message' => str_repeat('a', GuestMessage::maxUserBodyLength() + 1)])->assertUnprocessable();
        $this->assertNothingCreated('un message invalide ne cree rien');
        GuestShellAgent::assertNeverPrompted();

        foreach (['organization.shell.show', 'organization.shell.message'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware, $name);
            $this->assertContains('organization', $middleware, $name);
            $this->assertNotEmpty(array_filter($middleware, fn ($m) => str_starts_with((string) $m, 'throttle:')), "$name : throttle");
            $this->assertNotContains('auth', $middleware, "$name est public");
        }
    }
}
