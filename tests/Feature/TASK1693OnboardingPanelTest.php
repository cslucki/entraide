<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AJ — le panneau d'accueil disparait quand il n'a plus rien a
 * demander.
 *
 * ## Le defaut
 *
 * « Votre progression dans la boucle » guide une INSTALLATION. Une fois les
 * etapes faites, il n'enseigne plus rien et occupe le haut du tableau de bord
 * a perpetuite — un bandeau « 3/3 » que personne ne peut faire taire.
 *
 * ## La forme de ces tests
 *
 * Le panneau est mesure DANS LES DEUX SENS : absent quand tout est fait,
 * present des qu'il reste un geste. Un test qui ne verifierait que l'absence
 * resterait vert si le panneau disparaissait pour tout le monde — y compris
 * pour un arrivant qui en a besoin.
 */
class TASK1693OnboardingPanelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693aj',
            'is_active' => true,
            'is_public' => true,
            'ai_profiles_enabled' => false,
            'subscriptions_enabled' => false,
        ]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    /** Un membre dont les trois etapes sont faites (IA eteinte : 3 etapes). */
    private function membre(bool $toutFait): User
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'bio' => $toutFait ? 'Ma presentation.' : null,
        ]);

        if ($toutFait) {
            ServiceRequest::factory()->forUser($membre)->create([
                'organization_id' => $this->organization->id,
            ]);
            Service::factory()->forUser($membre)->create([
                'organization_id' => $this->organization->id,
            ]);
        }

        return $membre;
    }

    private function page(User $membre): string
    {
        return $this->actingAs($membre)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    /** LE defaut signale : rien a demander, donc rien a afficher. */
    public function test_the_panel_disappears_once_every_step_is_done(): void
    {
        $html = $this->page($this->membre(toutFait: true));

        $this->assertStringNotContainsString('data-onboarding-total', $html);
        $this->assertStringNotContainsString(e(__('dashboard.onboarding_title')), $html);
    }

    /** Et il RESTE tant qu'il reste un geste : c'est l'autre moitie du test. */
    public function test_the_panel_stays_while_something_remains(): void
    {
        $html = $this->page($this->membre(toutFait: false));

        $this->assertStringContainsString('data-onboarding-total="3"', $html);
        $this->assertStringContainsString(e(__('dashboard.onboarding_title')), $html);
    }

    /**
     * Le tableau de bord ne doit pas perdre le RESTE avec le panneau.
     *
     * Une garde mal fermee emporterait la suite de la page — invisible a un
     * test qui ne mesurerait que l'absence du panneau.
     */
    public function test_the_rest_of_the_dashboard_survives(): void
    {
        $html = $this->page($this->membre(toutFait: true));

        $this->assertStringContainsString(e(__('dashboard.my_services')), $html);
        $this->assertStringContainsString(e(__('dashboard.my_requests')), $html);
    }

    /**
     * Une etape VERROUILLEE mais vendable n'est pas faite.
     *
     * Quand l'Organization vend des abonnements, l'etape « Creer mon agent IA »
     * reste affichee avec « Voir les abonnements » (bug AG) : il y a encore un
     * geste possible, donc le panneau reste. La condition porte sur CE QUI
     * RESTE, pas sur un compte fige.
     */
    public function test_a_locked_but_sellable_step_keeps_the_panel(): void
    {
        $this->organization->forceFill(['subscriptions_enabled' => true])->save();
        app()->instance('current_organization', $this->organization->fresh());

        $html = $this->page($this->membre(toutFait: true));

        $this->assertStringContainsString('data-onboarding-total="4"', $html);
        $this->assertStringContainsString(e(__('dashboard.ai_profile_disabled_subscription')), $html);
    }

    /** Avec l'IA allumee, la 4e etape revient — et le panneau avec elle. */
    public function test_the_fourth_step_brings_the_panel_back(): void
    {
        $this->organization->forceFill(['ai_profiles_enabled' => true])->save();
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );
        app()->forgetInstance(\App\Support\Ai\AiSurfaceGate::class);
        app()->instance('current_organization', $this->organization->fresh());

        $html = $this->page($this->membre(toutFait: true));

        $this->assertStringContainsString('data-onboarding-total="4"', $html);
    }
}
