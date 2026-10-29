<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $referrer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'testorg']);
        $this->referrer = User::factory()->create([
            'organization_id' => $this->org->id,
            'referral_code' => 'testref',
        ]);

        app()->instance('current_organization', $this->org);
    }

    /**
     * L'IA devient utilisable : active, avec fournisseur, modele et credential.
     *
     * Meme decor que `TASK1693AiSurfacesFollowTheSwitchTest::allumerLIA()`. Le
     * `forgetInstance()` n'est pas optionnel : `AiSurfaceGate` memorise sa
     * reponse par Organization, donc une instance deja resolue rendrait la
     * reponse d'AVANT ce reglage.
     */
    private function allumerLIA(): void
    {
        $this->org->forceFill(['ai_profiles_enabled' => true])->save();

        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->org->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );

        app()->forgetInstance(\App\Support\Ai\AiSurfaceGate::class);
    }

    public function test_register_with_valid_ref_creates_referral(): void
    {
        $response = $this->post("/org/{$this->org->slug}/register", [
            'name' => 'Member',
            'first_name' => 'New',
            'email' => 'new@example.com',
            'phone' => '+33612345678',
            'country_code' => 'FR',
            'password' => 'password',
            'password_confirmation' => 'password',
            'ref' => 'testref',
        ]);

        $response->assertRedirect();

        $referred = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($referred);

        $referral = Referral::where('referrer_user_id', $this->referrer->id)
            ->where('referred_user_id', $referred->id)
            ->first();

        $this->assertNotNull($referral);
        $this->assertEquals('pending', $referral->status);
        $this->assertEquals($this->org->id, $referral->organization_id);
    }

    public function test_register_with_invalid_ref_still_succeeds(): void
    {
        $response = $this->post("/org/{$this->org->slug}/register", [
            'name' => 'Member',
            'first_name' => 'New',
            'email' => 'new@example.com',
            'phone' => '+33612345678',
            'country_code' => 'FR',
            'password' => 'password',
            'password_confirmation' => 'password',
            'ref' => 'nonexistent',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_dashboard_shows_user_navigation_shortcuts(): void
    {
        // TASK-1725 — « Agent IA » ne parait plus que si l'IA de l'Organization
        // est REELLEMENT disponible (demande Cyril du 29/10, bug 5). La garde
        // lisait `ai_profiles_enabled` seul et ignorait l'interrupteur maitre :
        // l'entree menait a un assistant que le serveur refuse.
        //
        // Ce test mesure la presence des RACCOURCIS de navigation, pas la
        // disponibilite de l'IA : il doit donc allumer l'IA pour que le raccourci
        // qu'il asserte existe. Sans ce decor, il mesurerait la nouvelle garde au
        // lieu de son objet.
        $this->allumerLIA();

        $user = User::factory()->create([
            'organization_id' => $this->org->id,
            'referral_code' => 'mylink',
        ]);

        $response = $this->actingAs($user)->get("/org/{$this->org->slug}/dashboard");

        $response->assertStatus(200);
        $response->assertSee('Profil public');
        $response->assertSee('Agent IA');
        $response->assertSee('Invitations');
        $response->assertSee('Mes points');
        $response->assertSee(route('organization.invitations.index', ['organization' => $this->org->slug]));
        $response->assertDontSee('Mes invitations');
        $response->assertDontSee(__('dashboard.metrics.balance'));
    }
}
