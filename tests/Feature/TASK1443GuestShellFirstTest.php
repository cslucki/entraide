<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Models\AiProviderInvocation;
use App\Models\GuestConversation;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Support\GuestShell\GuestShellDisplayMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1443 — SW-8b : SHELL_FIRST (MASTER Q70). Le MEME runtime, le MEME
 * partial, le MEME endpoint que SW-8a : seul le PLACEMENT change — le Shell
 * en premier, le contenu public classique entier juste en dessous. Une seule
 * instance par page ; overlay inchange.
 */
class TASK1443GuestShellFirstTest extends TestCase
{
    use RefreshDatabase;

    private Organization $first;

    private Organization $overlay;

    private Organization $degradedFirst;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $policies = app(GuestShellPolicyService::class);

        $this->first = Organization::factory()->create(['slug' => 'org-first-14xx', 'name' => 'First Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'hero_title' => 'ACCROCHE-CLASSIQUE-FIRST']);
        $policies->update($this->first, ['enabled' => true, 'max_messages' => 5, 'display_mode' => GuestShellDisplayMode::SHELL_FIRST]);
        OrganizationAiSetting::create(['organization_id' => $this->first->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);

        $this->overlay = Organization::factory()->create(['slug' => 'org-overlay-14xx', 'name' => 'Overlay Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'hero_title' => 'ACCROCHE-CLASSIQUE-OVERLAY']);
        $policies->update($this->overlay, ['enabled' => true, 'max_messages' => 5, 'display_mode' => GuestShellDisplayMode::OVERLAY]);
        OrganizationAiSetting::create(['organization_id' => $this->overlay->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);

        $this->degradedFirst = Organization::factory()->create(['slug' => 'org-degraded-first-14xx', 'name' => 'Degraded First', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $policies->update($this->degradedFirst, ['enabled' => true, 'display_mode' => GuestShellDisplayMode::SHELL_FIRST]);
    }

    private function home(Organization $organization): string
    {
        return route('organization.home', ['organization' => $organization->slug]);
    }

    private function assertShellFirstBefore(string $html, string $classicMarker): void
    {
        $this->assertSame(1, substr_count($html, 'id="bp-guest-shell"'), 'une seule instance par page');
        $this->assertStringContainsString('data-guest-shell-layout="shell_first"', $html);
        $this->assertStringContainsString('class="bpgs-first"', $html);
        $this->assertStringNotContainsString('data-guest-shell-toggle aria-expanded', $html, 'pas de widget flottant');
        $this->assertDoesNotMatchRegularExpression('/class="bpgs-panel"\s+hidden/', $html, 'le panneau est ouvert d\'emblee');
        $this->assertStringContainsString('data-guest-shell-after>', $html, 'le contenu classique reste accessible');
        $shell = strpos($html, 'id="bp-guest-shell"');
        $classic = strpos($html, $classicMarker);
        $this->assertNotFalse($classic, 'le contenu public classique est toujours la');
        $this->assertLessThan($classic, $shell, 'le Shell vient EN PREMIER');
    }

    // ── 1. Placement ───────────────────────────────────────────────────────

    public function test_shell_first_puts_the_shell_before_the_classic_content_on_the_three_templates(): void
    {
        GuestShellAgent::fake([]);

        $html = $this->get($this->home($this->first))->assertOk()->assertCookieMissing(GuestVisitorResolver::COOKIE)->getContent();
        // Le template `home` ne rend pas hero_title, et le layout porte deja un lien de connexion : le marqueur est l'en-tete PROPRE a la page.
        $this->assertShellFirstBefore($html, 'class="flex items-center gap-4 mb-8"');
        $this->assertStringContainsString('data-guest-shell-state="live"', $html);
        $this->assertStringContainsString('data-guest-shell-welcome>', $html);

        $this->first->update(['homepage_template' => 'bouclepro_hero_v2']);
        $this->assertShellFirstBefore($this->get($this->home($this->first))->assertOk()->getContent(), '<h1');

        $this->first->update(['homepage_template' => 'artscilab_hero']);
        $this->assertShellFirstBefore($this->get($this->home($this->first))->assertOk()->getContent(), '<h1');

        $this->assertSame(0, GuestVisitor::count());
        GuestShellAgent::assertNeverPrompted();
    }

    public function test_overlay_is_unchanged_and_rendered_once_at_the_bottom(): void
    {
        $html = $this->get($this->home($this->overlay))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'id="bp-guest-shell"'));
        $this->assertStringContainsString('data-guest-shell-layout="overlay"', $html);
        $this->assertStringContainsString('data-guest-shell-toggle aria-expanded="false"', $html);
        $this->assertMatchesRegularExpression('/class="bpgs-panel"\s+hidden/', $html, 'le panneau flottant reste cache');
        $this->assertStringNotContainsString('class="bpgs-first"', $html);
        $this->assertGreaterThan(strpos($html, 'class="flex items-center gap-4 mb-8"'), strpos($html, 'id="bp-guest-shell"'), 'l\'overlay reste en bas');
    }

    public function test_a_degraded_policy_preferring_shell_first_shows_the_honest_block_first_without_input(): void
    {
        $html = $this->get($this->home($this->degradedFirst))->assertOk()->assertCookieMissing(GuestVisitorResolver::COOKIE)->getContent();
        $this->assertSame(1, substr_count($html, 'id="bp-guest-shell"'));
        $this->assertStringContainsString('data-guest-shell-layout="shell_first"', $html);
        $this->assertStringContainsString('data-guest-shell-state="degraded"', $html);
        $this->assertStringContainsString('data-guest-shell-degraded>', $html);
        $this->assertStringNotContainsString('data-guest-shell-welcome>', $html);
        $this->assertMatchesRegularExpression('/<textarea[^>]*data-guest-shell-input[^>]*disabled/', $html);
    }

    // ── 2. Le meme runtime ─────────────────────────────────────────────────

    public function test_shell_first_uses_exactly_the_same_endpoint_visitor_conversation_and_ledger(): void
    {
        GuestShellAgent::fake([new TextResponse('Bienvenue chez First Guild !', new Usage(50, 20), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $html = $this->get($this->home($this->first))->assertOk()->getContent();
        $endpoint = route('organization.shell.message', ['organization' => $this->first->slug]);
        $this->assertStringContainsString('data-guest-shell-endpoint="'.$endpoint.'"', $html, 'le meme endpoint que l\'overlay');

        $response = $this->postJson($endpoint, ['message' => 'Bonjour'])->assertOk()->assertCookie(GuestVisitorResolver::COOKIE);
        $response->assertJsonPath('turn', 'answered')->assertJsonPath('assistant.body', 'Bienvenue chez First Guild !');
        $this->assertSame(1, GuestVisitor::count());
        $this->assertSame(1, GuestConversation::count());
        $this->assertSame(1, AiProviderInvocation::count());
        $this->assertSame($this->first->id, GuestVisitor::firstOrFail()->organization_id);
    }
}
