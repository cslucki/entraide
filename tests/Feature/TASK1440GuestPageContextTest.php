<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Ai\CapabilityRegistry;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\GuestShell\GuestConversationService;
use App\Services\GuestShell\GuestPageContextResolver;
use App\Services\GuestShell\GuestPublicContextBuilder;
use App\Services\GuestShell\GuestShellGate;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestShellPromptResolver;
use App\Services\GuestShell\GuestShellResponder;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Support\GuestShell\GuestPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use LogicException;
use Tests\TestCase;

/**
 * TASK-1440 — Guest PageContext V1 (Shell Welcome V3 §10, MASTER Q68) :
 * « ou se trouve exactement le visiteur ? » — DTO immuable + resolver par
 * whitelist de routes + 5e source `page_context`, apres la UsageReference,
 * avant les Constitutions. Route inconnue = NULL ; autre tenant = NULL ;
 * aucune lecture privee.
 */
class TASK1440GuestPageContextTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_TABLES = [
        'loops', 'loop_messages', 'messages', 'dossiers', 'dossier_chunks', 'dossier_documents',
        'member_ai_profiles', 'member_ai_profile_interactions', 'ai_shell_messages', 'ai_shell_memories',
        'crm_contacts', 'crm_contact_events', 'organization_ai_doctrines', 'organization_ai_settings',
        'guest_visitors', 'guest_conversations', 'guest_messages', 'users',
    ];

    private Organization $org;

    private Organization $other;

    private GuestPageContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.locale' => 'fr']);
        $this->org = Organization::factory()->create(['slug' => 'org-a-14xx', 'name' => 'Alpha Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'hero_title' => 'Entraide entre freelances']);
        $this->other = Organization::factory()->create(['slug' => 'org-b-14xx', 'name' => 'Beta Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->resolver = app(GuestPageContextResolver::class);
    }

    /** La route REELLE, telle que le routeur la matche pour cette URL (parametres lies). */
    private function matched(string $path): RoutingRoute
    {
        return Route::getRoutes()->match(Request::create($path, 'GET'));
    }

    // ── 1. Le resolver par whitelist de routes ─────────────────────────────

    public function test_the_organization_home_route_gives_the_home_kind_with_an_internal_signup_cta(): void
    {
        $page = $this->resolver->fromRoute($this->org, $this->matched('/org/org-a-14xx'));

        $this->assertNotNull($page);
        $this->assertSame(GuestPageContext::KIND_ORGANIZATION_HOME, $page->kind);
        $this->assertSame((string) $this->org->id, $page->organizationId);
        $this->assertNull($page->publicId);
        $this->assertSame('Alpha Guild', $page->publicLabel);
        $this->assertSame('organization.home', $page->routeName);
        $this->assertSame(route('organization.register', ['organization' => 'org-a-14xx']), $page->publicCta['url'], 'CTA interne genere cote serveur');
        $this->assertSame(['label', 'url'], array_keys($page->publicCta));
        $this->assertTrue(GuestPageContext::isInternalUrl($page->publicCta['url']));

        // Meme reponse depuis une Request (ce que SW-8 utilisera).
        $request = Request::create('/org/org-a-14xx', 'GET');
        $request->setRouteResolver(fn () => $this->matched('/org/org-a-14xx'));
        $this->assertSame(GuestPageContext::KIND_ORGANIZATION_HOME, $this->resolver->fromRequest($this->org, $request)?->kind);
    }

    public function test_the_signup_route_gives_the_signup_kind_without_cta(): void
    {
        $page = $this->resolver->fromRoute($this->org, $this->matched('/org/org-a-14xx/register'));

        $this->assertNotNull($page);
        $this->assertSame(GuestPageContext::KIND_SIGNUP, $page->kind);
        $this->assertSame('organization.register', $page->routeName);
        $this->assertNull($page->publicCta, 'deja sur l\'inscription : pas de CTA');
        $this->assertStringContainsString('Alpha Guild', $page->publicLabel);
    }

    public function test_an_unknown_or_non_whitelisted_route_is_null_and_workshop_kinds_have_no_route_yet(): void
    {
        $this->assertNull($this->resolver->fromRoute($this->org, $this->matched('/org/org-a-14xx/about')), 'route publique mais hors whitelist');
        $this->assertNull($this->resolver->fromRoute($this->org, $this->matched('/mycelium')), 'route plateforme sans Organization');
        $this->assertNull($this->resolver->fromRoute($this->org, null));

        // Les kinds Workshop existent dans l'enum canonique (V3 §10) mais aucune route ne les produit : pas de faux contexte.
        $this->assertContains(GuestPageContext::KIND_WORKSHOP_PAGE, GuestPageContext::KINDS);
        $this->assertContains(GuestPageContext::KIND_WORKSHOP_SESSION, GuestPageContext::KINDS);
        $fake = (new RoutingRoute(['GET'], '/org/{organization}/workshops/{workshop}', fn () => null))->name('organization.workshops.show');
        $fake->bind(Request::create('/org/org-a-14xx/workshops/w1', 'GET'));
        $this->assertNull($this->resolver->fromRoute($this->org, $fake), 'parsing generique interdit : une route non whitelistee reste NULL meme avec un slug valide');
    }

    public function test_a_route_of_another_organization_or_a_closed_organization_is_null(): void
    {
        $this->assertNull($this->resolver->fromRoute($this->org, $this->matched('/org/org-b-14xx')), 'la route porte B, l\'Organization determinee est A : fail-closed');
        $this->assertNotNull($this->resolver->fromRoute($this->other, $this->matched('/org/org-b-14xx')));

        $this->org->update(['is_public' => false]);
        $this->assertNull($this->resolver->fromRoute($this->org->fresh(), $this->matched('/org/org-a-14xx')));
        $this->org->update(['is_public' => true, 'is_active' => false]);
        $this->assertNull($this->resolver->fromRoute($this->org->fresh(), $this->matched('/org/org-a-14xx')));
    }

    // ── 2. Le DTO ──────────────────────────────────────────────────────────

    public function test_the_dto_is_immutable_and_refuses_unknown_kinds_and_external_ctas(): void
    {
        $page = new GuestPageContext((string) $this->org->id, GuestPageContext::KIND_ORGANIZATION_HOME, null, 'Alpha', ['label' => 'Go', 'url' => url('/org/org-a-14xx/register')], 'organization.home');
        try {
            $page->kind = GuestPageContext::KIND_SIGNUP;
            $this->fail('readonly');
        } catch (\Error) {
        }
        $this->assertSame(GuestPageContext::KIND_ORGANIZATION_HOME, $page->kind, 'immuable');

        $refused = 0;
        foreach ([
            ['kind' => 'loop_private'],
            ['cta' => ['label' => 'Go', 'url' => 'https://evil.example/phish']],
            ['cta' => ['label' => 'Go', 'url' => 'javascript:alert(1)']],
            ['cta' => ['label' => '', 'url' => url('/x')]],
            ['cta' => ['label' => 'Go', 'url' => url('/x'), 'onclick' => 'x']],
            ['label' => '   '],
        ] as $case) {
            try {
                new GuestPageContext((string) $this->org->id, $case['kind'] ?? GuestPageContext::KIND_ORGANIZATION_HOME, null, $case['label'] ?? 'Alpha', $case['cta'] ?? null, 'organization.home');
                $this->fail('refuse : '.json_encode($case));
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }
        $this->assertSame(6, $refused, 'kind inconnu, CTA externe, javascript:, libelle vide, cle en trop, libelle blanc');
    }

    // ── 3. Le contexte Guest ───────────────────────────────────────────────

    public function test_the_page_block_enters_after_the_usage_reference_and_before_the_constitutions(): void
    {
        $definition = app(CapabilityRegistry::class)->get(CapabilityRegistry::GUEST_SHELL_WELCOME);
        $this->assertTrue($definition->allowsSource(CapabilityRegistry::SOURCE_PAGE_CONTEXT));
        $this->assertSame('page_context', CapabilityRegistry::SOURCE_PAGE_CONTEXT);

        $page = $this->resolver->fromRoute($this->org, $this->matched('/org/org-a-14xx'));
        $context = app(GuestPublicContextBuilder::class)->build($this->org->fresh(), 'shell_welcome', $page);

        $this->assertSame([
            CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY,
            CapabilityRegistry::SOURCE_USAGE_REFERENCE,
            CapabilityRegistry::SOURCE_PAGE_CONTEXT,
            CapabilityRegistry::SOURCE_PLATFORM_CONSTITUTION,
        ], $context->sources);
        $block = $context->blocks[2];
        $this->assertStringContainsString('Alpha Guild', $block['text']);
        $this->assertStringContainsString(route('organization.register', ['organization' => 'org-a-14xx']), $block['text']);
        $this->assertStringContainsString('organization.home', $block['text'], 'provenance de route');

        $without = app(GuestPublicContextBuilder::class)->build($this->org->fresh(), 'shell_welcome');
        $this->assertFalse($without->hasSource(CapabilityRegistry::SOURCE_PAGE_CONTEXT), 'sans page, pas de bloc — et le reste se construit');
        $this->assertCount(3, $without->blocks);
    }

    public function test_a_page_context_of_another_organization_is_a_code_fault(): void
    {
        $page = $this->resolver->fromRoute($this->other, $this->matched('/org/org-b-14xx'));
        $this->expectException(LogicException::class);
        app(GuestPublicContextBuilder::class)->build($this->org->fresh(), 'shell_welcome', $page);
    }

    public function test_the_page_block_is_whole_or_absent_under_the_budget(): void
    {
        $page = $this->resolver->fromRoute($this->org, $this->matched('/org/org-a-14xx'));
        $full = app(GuestPublicContextBuilder::class)->build($this->org->fresh(), 'shell_welcome', $page);
        $used = mb_strlen($full->blocks[0]['text']) + mb_strlen($full->blocks[1]['text']);

        config(['ai.guest_shell.max_context_chars' => $used + 5]);
        app()->forgetInstance(CapabilityRegistry::class);
        app()->forgetInstance(GuestPublicContextBuilder::class);
        $context = app(GuestPublicContextBuilder::class)->build($this->org->fresh(), 'shell_welcome', $page);

        $this->assertSame([CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY, CapabilityRegistry::SOURCE_USAGE_REFERENCE], $context->sources);
        $this->assertStringNotContainsString('organization.home', $context->text(), 'aucun fragment du bloc page');
    }

    public function test_resolving_and_composing_never_read_a_private_table(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $page = $this->resolver->fromRoute($this->org, $this->matched('/org/org-a-14xx'));
        $context = app(GuestPublicContextBuilder::class)->build($this->org->fresh(), 'shell_welcome', $page);
        $this->assertTrue($context->hasSource(CapabilityRegistry::SOURCE_PAGE_CONTEXT));

        foreach ($queries as $sql) {
            foreach (self::FORBIDDEN_TABLES as $table) {
                $this->assertDoesNotMatchRegularExpression('/[`"]'.preg_quote($table, '/').'[`"]/', $sql, "table privee touchee : {$table} — {$sql}");
            }
        }
    }

    // ── 4. Le responder transmet la page au prompt — jamais au prompt DB ──

    public function test_the_responder_passes_the_page_to_the_provider_prompt_and_the_db_prompt_stays_untouched(): void
    {
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        app(GuestShellPolicyService::class)->update($this->org, ['enabled' => true, 'max_messages' => 3]);
        OrganizationAiSetting::create(['organization_id' => $this->org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test-not-a-real-key', 'is_enabled' => true]);
        $visitor = app()->make(GuestVisitorResolver::class)->ensure(Request::create('/', 'GET', cookies: [GuestVisitorResolver::COOKIE => Str::random(64)]), $this->org, ['locale' => 'fr']);
        $conversation = app(GuestConversationService::class)->start($visitor);
        RateLimiter::clear(GuestShellGate::rateKey($this->org, $visitor));
        GuestShellAgent::fake([new TextResponse('Bienvenue !', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);

        $page = $this->resolver->fromRoute($this->org, $this->matched('/org/org-a-14xx'));
        $turn = app(GuestShellResponder::class)->respond($this->org->fresh(), $visitor->fresh(), $conversation->fresh(), 'Bonjour', $page);
        $this->assertTrue($turn->isAnswered(), (string) $turn->reason);

        $register = route('organization.register', ['organization' => 'org-a-14xx']);
        GuestShellAgent::assertPrompted(function (AgentPrompt $prompt) use ($register) {
            // SW-7 : prompt DB verbatim, PUIS le contexte public (dont le bloc page), PUIS la langue — dans les instructions de l'agent.
            $instructions = (string) $prompt->agent->instructions();
            $this->assertStringContainsString($register, $instructions, 'le CTA interne est dans le contexte transmis');
            $this->assertStringContainsString('organization.home', $instructions);
            $this->assertStringNotContainsString('organization.home', $prompt->prompt, 'le tour utilisateur ne porte pas le contexte');

            return true;
        });
        $this->assertStringNotContainsString('organization.home', (string) app(GuestShellPromptResolver::class)->resolve()?->text, 'le prompt DB ne porte jamais le PageContext');
    }
}
