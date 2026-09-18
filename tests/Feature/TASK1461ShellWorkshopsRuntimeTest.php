<?php

namespace Tests\Feature;

use App\Ai\Agents\GuestShellAgent;
use App\Ai\CapabilityRegistry;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\GuestShell\GuestPublicContextBuilder;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1461 — Shell runtime Workshops (Growth V3 §17, audit OPUS §9) : le Shell
 * Welcome sait « ce qui est reellement possible ici, maintenant » — les ateliers
 * PUBLIES de l'Organization ayant une session PUBLIEE a venir (titre, prochaine
 * session locale, format, URL publique reelle), au plus N, derives des routes
 * reelles ; jamais un brouillon, un atelier retire, une session passee ou
 * annulee, un atelier d'une autre Organization ; aucun meeting_url, inscrit ni
 * capacite ; jamais une documentation bis. Source declaree par la capability.
 */
class TASK1461ShellWorkshopsRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');
        config(['ai.guest_shell.platform_monthly_ceiling_usd' => 5.0, 'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0]);
        $policies = app(GuestShellPolicyService::class);
        foreach (['a', 'b'] as $key) {
            $org = Organization::factory()->create(['slug' => "org-{$key}-1461", 'name' => strtoupper($key).' Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
            $policies->update($org, ['enabled' => true, 'max_messages' => 5]);
            OrganizationAiSetting::create(['organization_id' => $org->id, 'provider' => 'openrouter', 'model' => 'openai/gpt-4o-mini', 'api_key' => 'sk-test', 'is_enabled' => true]);
            $this->{$key} = $org;
        }
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
    }

    private function encryptedCookie(string $raw): string
    {
        return app('encrypter')->encrypt(CookieValuePrefix::create(GuestVisitorResolver::COOKIE, app('encrypter')->getKey()).$raw, false);
    }

    public function test_the_public_context_lists_only_published_workshops_with_an_upcoming_published_session_of_this_organization(): void
    {
        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $adminB = User::factory()->create(['organization_id' => $this->b->id]);
        $this->b->update(['admin_id' => $adminB->id]);

        $open = $workshops->create($this->a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr', 'promise' => 'Comprendre l\'IA en 90 minutes', 'description' => 'DESCRIPTION LONGUE réservée à la page, jamais au contexte.'], $this->adminA);
        $workshops->publish($open, $this->adminA);
        $sessions->publish($sessions->create($open, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris', 'location' => 'https://meet.example.test/secret-room', 'capacity' => 12], $this->adminA), $this->adminA);
        $sessions->create($open, ['starts_at' => '2026-09-20 18:30', 'timezone' => 'Europe/Paris'], $this->adminA); // brouillon : ignoree, la prochaine reste le 1er octobre
        $draft = $workshops->create($this->a, ['title' => 'Brouillon secret', 'slug' => 'brouillon', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $sessions->publish($sessions->create($draft, ['starts_at' => '2026-10-02 18:30', 'timezone' => 'Europe/Paris'], $this->adminA), $this->adminA);
        $past = $workshops->create($this->a, ['title' => 'Atelier passé', 'slug' => 'passe', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($past, $this->adminA);
        $sessions->publish($sessions->create($past, ['starts_at' => '2026-09-01 18:30', 'timezone' => 'Europe/Paris'], $this->adminA), $this->adminA);
        $noSession = $workshops->create($this->a, ['title' => 'Sans session', 'slug' => 'sans-session', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($noSession, $this->adminA);
        $foreign = $workshops->create($this->b, ['title' => 'Atelier de B', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $adminB);
        $workshops->publish($foreign, $adminB);
        $sessions->publish($sessions->create($foreign, ['starts_at' => '2026-10-03 18:30', 'timezone' => 'Europe/Paris'], $adminB), $adminB);

        $context = app(GuestPublicContextBuilder::class)->build($this->a);
        $this->assertTrue($context->hasSource(CapabilityRegistry::SOURCE_WORKSHOPS_RUNTIME));
        $block = collect($context->blocks)->firstWhere('source', CapabilityRegistry::SOURCE_WORKSHOPS_RUNTIME);
        $this->assertStringContainsString('Découvrir l\'IA', $block['text']);
        $this->assertStringContainsString('jeudi 1 octobre 2026 18:30 (Europe/Paris)', $block['text'], 'la prochaine session PUBLIEE, en heure locale');
        $this->assertStringContainsString(route('organization.workshop.show', ['organization' => $this->a->slug, 'workshop' => 'ia-90']), $block['text'], 'URL publique reelle');
        $this->assertStringContainsString('En ligne', $block['text']);
        $this->assertStringContainsString('Découvrir l\'IA — Comprendre l\'IA en 90 minutes — ', $block['text'], 'la promesse courte publique (MASTER #54)');
        foreach (['Brouillon secret', 'Atelier passé', 'Sans session', 'Atelier de B', 'secret-room', '12', '20 septembre', 'DESCRIPTION LONGUE'] as $never) {
            $this->assertStringNotContainsString($never, $block['text'], "jamais : {$never}");
        }
        $this->assertTrue(app(CapabilityRegistry::class)->get(CapabilityRegistry::GUEST_SHELL_WELCOME)->allowsSource(CapabilityRegistry::SOURCE_WORKSHOPS_RUNTIME));

        // L'Organization B ne voit que son atelier ; sans atelier ouvert, aucun bloc (et le reste se construit).
        $contextB = app(GuestPublicContextBuilder::class)->build($this->b);
        $this->assertStringContainsString('Atelier de B', collect($contextB->blocks)->firstWhere('source', CapabilityRegistry::SOURCE_WORKSHOPS_RUNTIME)['text']);
        $workshops->retire($foreign, $adminB);
        $contextB = app(GuestPublicContextBuilder::class)->build($this->b);
        $this->assertFalse($contextB->hasSource(CapabilityRegistry::SOURCE_WORKSHOPS_RUNTIME));
        $this->assertTrue($contextB->hasSource(CapabilityRegistry::SOURCE_ORGANIZATION_PUBLIC_IDENTITY));
    }

    public function test_the_runtime_block_is_bounded_and_reaches_the_model_prompt_only_for_this_organization(): void
    {
        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        for ($i = 1; $i <= GuestPublicContextBuilder::WORKSHOPS_RUNTIME_MAX + 2; $i++) {
            $w = $workshops->create($this->a, ['title' => "Atelier n{$i}", 'slug' => "atelier-{$i}", 'format' => 'online', 'locale' => 'fr', 'promise' => "Promesse n{$i}"], $this->adminA);
            $workshops->publish($w, $this->adminA);
            $sessions->publish($sessions->create($w, ['starts_at' => sprintf('2026-10-%02d 18:30', $i), 'timezone' => 'Europe/Paris'], $this->adminA), $this->adminA);
        }
        $block = collect(app(GuestPublicContextBuilder::class)->build($this->a)->blocks)->firstWhere('source', CapabilityRegistry::SOURCE_WORKSHOPS_RUNTIME);
        $this->assertSame(GuestPublicContextBuilder::WORKSHOPS_RUNTIME_MAX, substr_count($block['text'], "\n- "), 'au plus N ateliers, les plus proches d\'abord');
        $this->assertStringContainsString('Atelier n1', $block['text']);
        $this->assertStringNotContainsString('Atelier n'.(GuestPublicContextBuilder::WORKSHOPS_RUNTIME_MAX + 2), $block['text']);

        // Le bloc atteint le modele (prompt), et seulement celui de l'Organization interrogee.
        GuestShellAgent::fake([new TextResponse('Voici les ateliers ouverts.', new Usage(10, 5), new Meta('openrouter', 'openai/gpt-4o-mini'))]);
        $this->withCredentials()->withUnencryptedCookie(GuestVisitorResolver::COOKIE, $this->encryptedCookie(Str::random(64)))
            ->postJson(route('organization.shell.message', ['organization' => $this->a->slug]), ['message' => 'Quel atelier puis-je rejoindre ?'])
            ->assertOk()->assertJsonPath('turn', 'answered');
        GuestShellAgent::assertPrompted(function (AgentPrompt $p): bool {
            $instructions = (string) $p->agent->instructions();

            // Titre, promesse et URL d'un atelier reel de CETTE Organization atteignent le modele ; jamais un atelier d'ailleurs (MASTER #54).
            return str_contains($instructions, 'Atelier n1 — Promesse n1 — ')
                && str_contains($instructions, route('organization.workshop.show', ['organization' => $this->a->slug, 'workshop' => 'atelier-1']))
                && ! str_contains($instructions, 'Atelier de B');
        });
    }
}
