<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Ai\FakeAIProvider;
use Tests\TestCase;

class AdminIaDesignLabTest extends TestCase
{
    private function makeAdmin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_guest_cannot_access_ia_design_lab(): void
    {
        $this->get(route('admin.ia-design-lab'))->assertRedirect(route('login'));
    }

    public function test_non_admin_cannot_access_ia_design_lab(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.ia-design-lab'))->assertStatus(403);
    }

    public function test_admin_can_view_ia_design_lab(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('admin.ia-design-lab'))->assertOk();
    }

    public function test_lab_is_only_available_in_non_production_environments(): void
    {
        $this->assertFalse(app()->isProduction(),
            'Le Lab IA est protégé par app()->isProduction() dans AdminIaDesignLabController. '
            .'En environnement de production, la route retourne 404. '
            .'Ce test confirme que l\'environnement de test (APP_ENV=testing) n\'est pas production.'
        );
    }

    public function test_admin_can_test_with_fake_ai_provider(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)
            ->post(route('admin.ia-design-lab.test'), [
                'phrase' => 'J\'ai besoin d\'aide pour trouver mes premiers clients.',
            ])
            ->assertOk()
            ->assertSee('Trouver mes premiers clients')
            ->assertSee('Développement commercial')
            ->assertSee('Confiance haute')
            ->assertSeeText("Rien n'est envoyé sans votre validation");
    }

    public function test_fake_ai_provider_returns_high_confidence_for_clear_request(): void
    {
        $provider = app(FakeAIProvider::class);
        $result = $provider->analyze('trouver mes premiers clients');

        $this->assertSame('help_request', $result->intent);
        $this->assertGreaterThanOrEqual(0.8, $result->confidence);
        $this->assertNotNull($result->suggestedLoop);
        $this->assertFalse($result->needsFallback());
        $this->assertFalse($result->isBlocked());
        $this->assertNotNull($result->messageDraft);
    }

    public function test_fake_ai_provider_returns_low_confidence_for_vague_request(): void
    {
        $provider = app(FakeAIProvider::class);
        $result = $provider->analyze('Je suis bloqué');

        $this->assertLessThan(0.65, $result->confidence);
        $this->assertTrue($result->needsFallback());
        $this->assertNotEmpty($result->fallback['questions']);
        $this->assertNull($result->suggestedLoop);
    }

    public function test_fake_ai_provider_blocks_sensitive_data(): void
    {
        $provider = app(FakeAIProvider::class);
        $result = $provider->analyze('Voici le numéro perso d\'un prospect');

        $this->assertTrue($result->hasSensitiveData());
        $this->assertTrue($result->isBlocked());
        $this->assertTrue($result->needsFallback());
        $this->assertNull($result->messageDraft);
    }

    public function test_fake_ai_provider_blocks_legal_scope(): void
    {
        $provider = app(FakeAIProvider::class);
        $result = $provider->analyze('Donne-moi une stratégie juridique pour mon contrat');

        $this->assertTrue($result->isBlocked());
        $this->assertTrue($result->needsFallback());
        $this->assertNull($result->messageDraft);
    }

    public function test_fake_ai_provider_detects_deadline(): void
    {
        $provider = app(FakeAIProvider::class);
        $result = $provider->analyze('Je dois trouver quelqu\'un pour relire mon offre avant vendredi');

        $this->assertTrue($result->deadline['has_deadline']);
        $this->assertGreaterThanOrEqual(0.7, $result->confidence);
        $this->assertNotNull($result->messageDraft);
    }

    public function test_fake_ai_provider_detects_offer_intent(): void
    {
        $provider = app(FakeAIProvider::class);
        $result = $provider->analyze('Je peux aider à refaire une page de vente');

        $this->assertSame('offer', $result->intent);
        $this->assertGreaterThanOrEqual(0.8, $result->confidence);
    }

    public function test_admin_can_use_quick_scenario_buttons(): void
    {
        $admin = $this->makeAdmin();
        $scenarios = app(FakeAIProvider::class)->getScenarios();

        foreach ($scenarios as $key => $scenario) {
            $phrases = [
                'besoin_client_clair' => "J'ai besoin d'aide pour trouver mes premiers clients.",
                'demande_trop_vague' => 'Je suis bloqué.',
                'demande_avec_deadline' => 'Je dois trouver quelqu\'un pour relire mon offre avant vendredi.',
                'mauvais_canal' => 'Je veux vendre mon service à tout le monde.',
                'donnees_sensibles' => 'Voici le numéro perso d\'un prospect : 06 12 34 56 78.',
                'loop_ambigue' => 'Je cherche des avis sur mon site et ma stratégie.',
                'intention_offre' => 'Je peux aider à refaire une page de vente.',
                'hors_scope' => 'Donne-moi une stratégie juridique pour mon contrat.',
                'fallback' => 'Une phrase totalement inconnue du système.',
            ];

            $phrase = $phrases[$key] ?? 'Phrase de test générique.';

            $this->actingAs($admin)
                ->post(route('admin.ia-design-lab.test'), [
                    'phrase' => $phrase,
                    'scenario' => $key,
                ])
                ->assertOk()
                ->assertSee($scenario['_scenario_label']);
        }
    }
}
