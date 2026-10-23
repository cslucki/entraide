<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1694, bug C — la vérification d'email redevient obligatoire.
 *
 * ## L'histoire, parce qu'elle explique la forme
 *
 * `verified` gardait 149 routes jusqu'au bug E de TASK-1693, où Cyril l'a fait
 * retirer. Il revient sur cette décision : « j'avais fait une erreur ».
 *
 * ## Où la garde est posée, et pourquoi pas ailleurs
 *
 * Dans le groupe `web`, sur le critère « cette route est-elle gardée par
 * `auth` ? ». Reposer `verified` sur les deux déclarations d'avant aurait
 * laissé le tableau de bord, l'Explorer et la Messagerie ouverts —
 * « obligatoire » n'aurait rien voulu dire. Et `organization.member` ne suffit
 * pas : `organization.dashboard` ne la porte pas.
 *
 * ## Ce que ces tests mesurent dans les DEUX sens
 *
 * Qu'un compte non vérifié est arrêté, **et** que les quatre portes qui
 * doivent rester ouvertes le restent. Une garde trop large enfermerait
 * quelqu'un dans une session qu'il ne peut ni quitter ni réparer.
 */
class TASK1694MandatoryEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $nonVerifie;

    private User $verifie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694c',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->nonVerifie = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => null,
        ]);

        $this->verifie = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function urlOrg(string $route): string
    {
        return route($route, ['organization' => $this->organization->slug]);
    }

    /** LE geste demandé : une surface de membre est fermée tant que l'adresse n'est pas confirmée. */
    public function test_an_unverified_account_is_stopped_on_member_surfaces(): void
    {
        foreach (['organization.dashboard', 'organization.explorer', 'organization.messages.index'] as $route) {
            $this->actingAs($this->nonVerifie)
                ->get($this->urlOrg($route))
                ->assertRedirect(route('verification.notice'));
        }
    }

    /** Et un compte vérifié passe — c'est ce qui prouve que le refus vient de LÀ. */
    public function test_a_verified_account_passes(): void
    {
        foreach (['organization.dashboard', 'organization.explorer'] as $route) {
            $this->actingAs($this->verifie)->get($this->urlOrg($route))->assertOk();
        }
    }

    /**
     * Le PROFIL reste ouvert : c'est la porte de réparation.
     *
     * Une adresse mal saisie rendrait sinon le compte définitivement
     * inutilisable — le cas le plus fréquent, et le plus coûteux.
     */
    public function test_the_profile_stays_reachable_to_fix_a_typo(): void
    {
        $this->actingAs($this->nonVerifie)->get(route('profile.edit'))->assertOk();
    }

    /** On peut toujours SE DÉCONNECTER. */
    public function test_logging_out_stays_possible(): void
    {
        $this->actingAs($this->nonVerifie)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    /** Et demander un nouvel email, sinon la garde empêcherait de la lever. */
    public function test_the_verification_flow_stays_open(): void
    {
        $this->actingAs($this->nonVerifie)->get(route('verification.notice'))->assertOk();
        $this->actingAs($this->nonVerifie)->post(route('verification.send'))->assertRedirect();
    }

    /**
     * L'ADMINISTRATION reste ouverte — c'est là que vit « Valider l'email ».
     *
     * Un SuperAdmin non vérifié qui ne pourrait pas y accéder ne pourrait
     * débloquer personne, lui compris.
     */
    public function test_the_administration_stays_reachable(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => null,
        ]);

        $this->actingAs($admin)->get(route('admin.users'))->assertOk();
    }

    /**
     * Une page PUBLIQUE reste publique.
     *
     * On ne ferme pas les mentions légales à cause d'une adresse non
     * confirmée : la garde ne s'applique qu'aux routes portant `auth`.
     */
    public function test_a_public_page_stays_open(): void
    {
        $this->actingAs($this->nonVerifie)->get(route('mentions-legales'))->assertOk();
    }

    /** La durée du lien est de 24 heures, pas l'heure par défaut de Laravel. */
    public function test_the_link_lasts_a_day(): void
    {
        $this->assertSame(1440, (int) config('auth.verification.expire'));

        // Et la réinitialisation de mot de passe garde SA valeur courte : les
        // deux ne répondent pas au même risque.
        $this->assertSame(60, (int) config('auth.passwords.users.expire'));
    }
}
