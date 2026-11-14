<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * FICHIER TEMPORAIRE D'AUDIT — a supprimer apres mesure. Non suivi par git.
 */
class ZzTempAuditPasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function org(bool $approvalRequired = false): Organization
    {
        return Organization::factory()->create([
            'is_default' => true,
            'is_active' => true,
            'requires_account_approval' => $approvalRequired,
        ]);
    }

    public function test_D_mauvais_mot_de_passe_actuel(): void
    {
        $org = $this->org();
        $user = User::factory()->complete()->create([
            'organization_id' => $org->id,
            'password' => Hash::make('ancien-secret-1234'),
        ]);

        $reponse = $this->actingAs($user)
            ->from("/org/{$org->slug}/profile/edit")
            ->put('/password', [
                'current_password' => 'ce-n-est-pas-le-bon',
                'password' => 'nouveau-secret-9876',
                'password_confirmation' => 'nouveau-secret-9876',
            ]);

        $erreurs = session('errors');
        $sacs = is_object($erreurs) && method_exists($erreurs, 'getBags')
            ? array_keys($erreurs->getBags())
            : 'type='.get_debug_type($erreurs);

        dump('D statut='.$reponse->getStatusCode());
        dump('D redirection='.($reponse->headers->get('Location') ?? 'aucune'));
        dump('D sacs='.json_encode($sacs));
        dump('D hash_change='.var_export(Hash::check('nouveau-secret-9876', $user->fresh()->password), true));
        $this->assertTrue(true);
    }

    /** Effet de bord : que laisse `redirect()->guest()` en session pour un PUT ? */
    public function test_G_intended_pose_par_la_garde(): void
    {
        $org = $this->org();
        $user = User::factory()->complete()->unverified()->create([
            'organization_id' => $org->id,
            'password' => Hash::make('ancien-secret-1234'),
        ]);

        $this->actingAs($user)
            ->from("/org/{$org->slug}/profile/edit")
            ->put('/password', [
                'current_password' => 'ancien-secret-1234',
                'password' => 'nouveau-secret-9876',
                'password_confirmation' => 'nouveau-secret-9876',
            ]);

        dump('G url.intended='.var_export(session('url.intended'), true));
        $this->assertTrue(true);
    }

    /** Le systeme de reset est-il atteignable depuis une session authentifiee ? */
    public function test_H_reset_depuis_session_authentifiee(): void
    {
        $org = $this->org();
        $user = User::factory()->complete()->create(['organization_id' => $org->id]);

        $forgotGlobal = $this->actingAs($user)->get('/forgot-password');
        dump('H1 GET /forgot-password statut='.$forgotGlobal->getStatusCode());
        dump('H1 redirection='.($forgotGlobal->headers->get('Location') ?? 'aucune'));

        $forgotOrg = $this->actingAs($user)->get("/org/{$org->slug}/forgot-password");
        dump('H2 GET /org/{slug}/forgot-password statut='.$forgotOrg->getStatusCode());
        dump('H2 redirection='.($forgotOrg->headers->get('Location') ?? 'aucune'));

        $postEmail = $this->actingAs($user)->post('/forgot-password', ['email' => $user->email]);
        dump('H3 POST /forgot-password statut='.$postEmail->getStatusCode());
        dump('H3 redirection='.($postEmail->headers->get('Location') ?? 'aucune'));

        $resetGet = $this->actingAs($user)->get('/reset-password/'.str_repeat('a', 64));
        dump('H4 GET /reset-password/{token} statut='.$resetGet->getStatusCode());
        dump('H4 redirection='.($resetGet->headers->get('Location') ?? 'aucune'));

        $this->assertTrue(true);
    }

    /** La page de profil est-elle atteignable pour les comptes B et C ? */
    public function test_I_page_profil_pour_comptes_bloques(): void
    {
        $orgV = $this->org();
        $nonVerifie = User::factory()->complete()->unverified()->create(['organization_id' => $orgV->id]);
        $r1 = $this->actingAs($nonVerifie)->get("/org/{$orgV->slug}/profile/edit");
        dump('I1 non-verifie GET profile/edit statut='.$r1->getStatusCode());
        dump('I1 redirection='.($r1->headers->get('Location') ?? 'aucune'));
        dump('I1 formulaire_mdp_rendu='.var_export($r1->getStatusCode() === 200 && str_contains($r1->getContent(), 'name="current_password"'), true));

        $orgA = Organization::factory()->create([
            'is_active' => true,
            'requires_account_approval' => true,
        ]);
        $nonApprouve = User::factory()->complete()->pendingApproval()->create(['organization_id' => $orgA->id]);
        $r2 = $this->actingAs($nonApprouve)->get("/org/{$orgA->slug}/profile/edit");
        dump('I2 non-approuve GET profile/edit statut='.$r2->getStatusCode());
        dump('I2 redirection='.($r2->headers->get('Location') ?? 'aucune'));

        $this->assertTrue(true);
    }
}
