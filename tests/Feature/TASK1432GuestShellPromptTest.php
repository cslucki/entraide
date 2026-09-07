<?php

namespace Tests\Feature;

use App\Models\AdminAiPrompt;
use App\Models\Organization;
use App\Models\User;
use App\Services\GuestShell\GuestShellPromptResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1432 — SW-2 : le prompt d'accueil du Shell Welcome vient de la BASE.
 *
 * - le seed pose `guest_shell_welcome` v1 actif (idempotent) ;
 * - le resolveur rend la version active la plus recente, tel quel ;
 * - sans version active : null, et aucun texte de secours nulle part ;
 * - le scenario est administrable dans /admin/ai-prompts (catalogue) ;
 * - aucune Organization n'a d'override (V1 plateforme seule).
 */
class TASK1432GuestShellPromptTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private GuestShellPromptResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-a-1432', 'name' => 'Alpha Corp', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->resolver = app(GuestShellPromptResolver::class);
    }

    public function test_the_seed_puts_a_platform_prompt_in_the_registry_once(): void
    {
        $seeded = AdminAiPrompt::byScenario(GuestShellPromptResolver::SCENARIO)->get();

        $this->assertCount(1, $seeded);
        $this->assertTrue($seeded->first()->is_active);
        $this->assertSame(1, $seeded->first()->version);
        // Generique : aucune variable maison, aucune promesse d'atelier (Phase B n'existe pas encore).
        $this->assertStringNotContainsString('{{', $seeded->first()->prompt_text);
        $this->assertDoesNotMatchRegularExpression('/atelier|workshop/i', $seeded->first()->prompt_text);
        $this->assertMatchesRegularExpression('/CONTEXTE PUBLIC/', $seeded->first()->prompt_text);
        $this->assertMatchesRegularExpression('/creer un compte/i', $seeded->first()->prompt_text);
        // Le prompt interdit explicitement le prive et l'invention.
        $this->assertMatchesRegularExpression('/priv/i', $seeded->first()->prompt_text);
        $this->assertMatchesRegularExpression('/invente/i', $seeded->first()->prompt_text);

        // Idempotence MESUREE : rejouer la migration ne cree ni doublon ni erreur.
        $migration = require base_path('database/migrations/2026_09_07_231000_seed_guest_shell_welcome_prompt.php');
        $migration->up();
        $this->assertSame(1, AdminAiPrompt::byScenario(GuestShellPromptResolver::SCENARIO)->count());
        $this->assertSame($seeded->first()->id, AdminAiPrompt::byScenario(GuestShellPromptResolver::SCENARIO)->first()->id);
    }

    public function test_the_resolver_returns_the_active_prompt_verbatim_with_its_provenance(): void
    {
        $prompt = $this->resolver->resolve();

        $this->assertNotNull($prompt);
        $this->assertSame(GuestShellPromptResolver::SCENARIO, $prompt->scenarioId);
        $this->assertSame(1, $prompt->version);
        $this->assertSame(AdminAiPrompt::byScenario(GuestShellPromptResolver::SCENARIO)->first()->prompt_text, $prompt->text, 'tel quel : aucun templating maison');
        $this->assertSame(AdminAiPrompt::byScenario(GuestShellPromptResolver::SCENARIO)->first()->getKey(), $prompt->promptId);
    }

    public function test_a_newer_active_version_wins_and_an_inactive_one_is_ignored(): void
    {
        AdminAiPrompt::create(['scenario_id' => GuestShellPromptResolver::SCENARIO, 'name' => 'v2', 'prompt_text' => 'Version deux, generique.', 'version' => 2, 'is_active' => true]);
        AdminAiPrompt::create(['scenario_id' => GuestShellPromptResolver::SCENARIO, 'name' => 'v3 brouillon', 'prompt_text' => 'Version trois inactive', 'version' => 3, 'is_active' => false]);

        $prompt = $this->resolver->resolve();

        $this->assertSame(2, $prompt->version);
        $this->assertSame('Version deux, generique.', $prompt->text);
    }

    public function test_without_an_active_version_the_resolver_fails_closed_with_no_fallback_text(): void
    {
        AdminAiPrompt::byScenario(GuestShellPromptResolver::SCENARIO)->update(['is_active' => false]);

        $this->assertNull($this->resolver->active());
        $this->assertNull($this->resolver->resolve());

        // Scenario totalement absent : null aussi.
        AdminAiPrompt::byScenario(GuestShellPromptResolver::SCENARIO)->delete();
        $this->assertNull($this->resolver->resolve());

        // Aucun texte de prompt de secours dans le code applicatif du Shell Welcome.
        foreach (['app/Services/GuestShell/GuestShellPromptResolver.php', 'app/Support/GuestShell/GuestShellPrompt.php', 'config/ai.php'] as $file) {
            $this->assertStringNotContainsString("Tu es l'assistant IA public", file_get_contents(base_path($file)), $file);
        }
    }

    public function test_the_scenario_is_administrable_in_the_prompt_registry_ui(): void
    {
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.ai-prompts', ['scenario_id' => GuestShellPromptResolver::SCENARIO]))->assertOk()
            ->assertSee('Shell Welcome — Accueil public v1');
        $this->actingAs($admin)->get(route('admin.ai-prompts.create'))->assertOk()
            ->assertSee(GuestShellPromptResolver::SCENARIO);

        // Une nouvelle version depuis l'UI est acceptee pour ce scenario (catalogue).
        $this->actingAs($admin)->post(route('admin.ai-prompts.store'), [
            'scenario_id' => GuestShellPromptResolver::SCENARIO,
            'name' => 'Shell Welcome — v2 test',
            'prompt_text' => 'Bonjour, version deux generique.',
        ])->assertRedirect();
        $this->assertSame(2, AdminAiPrompt::byScenario(GuestShellPromptResolver::SCENARIO)->max('version'));
    }
}
