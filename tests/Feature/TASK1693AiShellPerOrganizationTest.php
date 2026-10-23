<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiSurfaceGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AU — l'assistant « BouclePro IA » s'éteint PAR Organization.
 *
 * ## Le manque
 *
 * Il n'avait qu'un interrupteur PLATEFORME (`AI_SHELL_ENABLED`, une variable
 * d'environnement) : impossible de le couper pour UNE Organization sans le
 * couper pour toutes.
 *
 * ## Ce que ce réglage N'EST PAS
 *
 * Ce n'est **pas** l'interrupteur IA de l'Organization, et c'est toute sa
 * raison d'être : disposer de l'IA — reformulation, synthèse, agents — **sans**
 * imposer l'assistant flottant par-dessus chaque page. Les tests le mesurent
 * explicitement : IA allumée, assistant coupé, les autres surfaces restent.
 *
 * Et ce n'est pas le « Shell Welcome », qui s'adresse aux VISITEURS.
 */
class TASK1693AiShellPerOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693au',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );

        app()->instance('current_organization', $this->organization);
        app()->forgetInstance(AiSurfaceGate::class);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function tableauDeBord(): string
    {
        return $this->actingAs($this->membre)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    private function eteindreLAssistant(): void
    {
        $this->organization->forceFill(['ai_shell_enabled' => false])->save();
        app()->instance('current_organization', $this->organization->fresh());
    }

    /** Par defaut, rien ne change : l'assistant est la. */
    public function test_the_assistant_is_there_by_default(): void
    {
        $this->assertStringContainsString('data-ai-shell', $this->tableauDeBord());
    }

    /** LE manque comble : on peut l'eteindre pour CETTE Organization. */
    public function test_the_assistant_can_be_turned_off_for_one_organization(): void
    {
        $this->eteindreLAssistant();

        $this->assertStringNotContainsString('data-ai-shell', $this->tableauDeBord());
    }

    /**
     * Et l'IA, elle, RESTE disponible.
     *
     * C'est toute la raison d'etre de ce reglage : disposer de l'IA sans
     * imposer l'assistant flottant. Sans cette mesure, le correctif pourrait
     * eteindre l'IA entiere sans qu'aucun test ne rougisse.
     */
    public function test_the_rest_of_the_ai_is_untouched(): void
    {
        $this->eteindreLAssistant();

        $this->assertTrue(
            app(AiSurfaceGate::class)->availableFor($this->organization->id),
            'l\'IA de l\'Organization ne doit pas tomber avec l\'assistant',
        );

        $html = $this->actingAs($this->membre)
            ->get(route('organization.services.create', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ai-formulate-block', $html,
            'l\'aide a reformuler ne depend pas de l\'assistant');
    }

    /** Une AUTRE Organization n'est pas touchee. */
    public function test_another_organization_keeps_its_assistant(): void
    {
        $this->eteindreLAssistant();

        $autre = Organization::factory()->create(['slug' => 'org-1693au-bis', 'is_active' => true]);
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $autre->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );
        $membreAilleurs = User::factory()->complete()->create([
            'organization_id' => $autre->id,
            'email_verified_at' => now(),
        ]);

        app()->instance('current_organization', $autre);
        app()->forgetInstance(AiSurfaceGate::class);

        $html = $this->actingAs($membreAilleurs)
            ->get(route('organization.dashboard', ['organization' => $autre->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ai-shell', $html);
    }

    // ── L'ecran de reglage ───────────────────────────────────────────────────

    public function test_the_checkbox_is_on_the_platform_ai_config(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ai-shell-form="'.$this->organization->slug.'"', $html);
        $this->assertStringContainsString('data-ai-shell-enabled="1"', $html);
    }

    /** La case dit l'etat REEL, pas le repli. */
    public function test_the_checkbox_reflects_the_stored_state(): void
    {
        $this->eteindreLAssistant();

        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ai-shell-enabled="0"', $html);
    }

    public function test_the_superadmin_can_write_it(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.ai-config.shell'), [
                'organization_id' => $this->organization->id,
                'ai_shell_enabled' => '0',
            ])
            ->assertRedirect(route('admin.ai-config'))
            ->assertSessionHas('success');

        $this->assertFalse((bool) $this->organization->fresh()->ai_shell_enabled);
    }

    /** L'ecran reste l'administration PLATEFORME. */
    public function test_a_simple_member_cannot_write_it(): void
    {
        $this->actingAs($this->membre)
            ->post(route('admin.ai-config.shell'), [
                'organization_id' => $this->organization->id,
                'ai_shell_enabled' => '0',
            ])
            ->assertForbidden();

        $this->assertTrue((bool) $this->organization->fresh()->ai_shell_enabled);
    }
}
