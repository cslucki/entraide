<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushSubscriptionStore;
use App\Support\Push\SuperAdminPushAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TASK-1821 — « Appareils Push » : la console GLOBALE, décidée en arbitrage
 * MASTER après que « Mes appareils Push » (T1820) s'est révélé trop étroit
 * en recette (un abonnement créé sous un AUTRE compte, via un tunnel
 * Cloudflare, était invisible — comportement correct, mais pas le besoin
 * réel).
 *
 * ## Un seul écran, pas deux
 *
 * Décision produit (14/11, en session) : l'URL et le nom de route de T1820
 * sont CONSERVÉS — `admin.outils.my-push-devices` devient la console
 * globale, plutôt qu'un second écran voisin. Ce fichier couvre donc ce que
 * T1821 ajoute (garde durcie, liste tous comptes, propriétaire affiché,
 * recherche, suppression de la ligne d'autrui, journalisation) ;
 * `TASK1820MyPushDevicesTest` couvre ce qui n'a pas bougé et a été mis à
 * jour là où T1821 remplace délibérément son comportement.
 */
class TASK1821AllPushDevicesConsoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config(['webpush.vapid.public_key' => 'clef-vapid-de-test-task-1821-console']);

        $this->organization = Organization::factory()->create(['is_active' => true]);
    }

    private function superadminReel(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email' => 'superadmin-reel-'.bin2hex(random_bytes(4)).'@exemple.com',
        ]);
    }

    private function adminDemo(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email' => 'admin-demo-'.bin2hex(random_bytes(4)).'@bouclepro.test',
        ]);
    }

    private function membre(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);
    }

    private function appareil(User $proprietaire, ?string $libelle = 'Android'): PushSubscription
    {
        return app(PushSubscriptionStore::class)->revendiquer(
            $proprietaire,
            'https://fcm.googleapis.com/fcm/send/t1821-console-'.bin2hex(random_bytes(8)),
            str_repeat('A', 87),
            str_repeat('B', 22),
            $libelle,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. SuperAdminPushAccessPolicy — pure, sans HTTP
    // ═══════════════════════════════════════════════════════════════════

    public function test_la_politique_refuse_un_compte_sans_is_admin(): void
    {
        $policy = new SuperAdminPushAccessPolicy;

        $this->assertFalse($policy->estAutorise($this->membre()));
    }

    public function test_la_politique_refuse_un_compte_de_demonstration_meme_admin(): void
    {
        $policy = new SuperAdminPushAccessPolicy;

        $this->assertFalse($policy->estAutorise($this->adminDemo()));
    }

    public function test_la_politique_refuse_null(): void
    {
        $policy = new SuperAdminPushAccessPolicy;

        $this->assertFalse($policy->estAutorise(null));
    }

    public function test_la_politique_autorise_un_superadmin_reel(): void
    {
        $policy = new SuperAdminPushAccessPolicy;

        $this->assertTrue($policy->estAutorise($this->superadminReel()));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. Autorisation HTTP — le scénario exact de la garde fail-closed
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_superadmin_reel_peut_ouvrir_la_console(): void
    {
        $this->actingAs($this->superadminReel())
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk();
    }

    public function test_un_membre_ordinaire_est_refuse(): void
    {
        $this->actingAs($this->membre())
            ->get(route('admin.outils.my-push-devices'))
            ->assertForbidden();
    }

    public function test_un_compte_admin_de_demonstration_est_refuse(): void
    {
        $this->actingAs($this->adminDemo())
            ->get(route('admin.outils.my-push-devices'))
            ->assertForbidden();
    }

    public function test_un_compte_admin_de_demonstration_ne_peut_pas_supprimer(): void
    {
        $victime = $this->superadminReel();
        $appareilVictime = $this->appareil($victime);

        $this->actingAs($this->adminDemo())
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareilVictime->id]))
            ->assertForbidden();

        $this->assertNotNull(PushSubscription::query()->find($appareilVictime->id));
    }

    public function test_un_visiteur_non_connecte_est_redirige(): void
    {
        $this->get(route('admin.outils.my-push-devices'))->assertRedirect();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. La console montre VRAIMENT tout le monde (le besoin de l'arbitrage)
    // ═══════════════════════════════════════════════════════════════════

    public function test_la_console_montre_les_appareils_de_plusieurs_comptes_distincts(): void
    {
        $superadmin = $this->superadminReel();
        $membreA = $this->membre();
        $membreB = $this->membre();

        $appareilA = $this->appareil($membreA, 'Appareil de A');
        $appareilB = $this->appareil($membreB, 'Appareil de B');

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(mb_substr($appareilA->endpoint_hash, 0, 8), $html);
        $this->assertStringContainsString(mb_substr($appareilB->endpoint_hash, 0, 8), $html);
        $this->assertStringContainsString($membreA->email, $html);
        $this->assertStringContainsString($membreB->email, $html);
    }

    public function test_le_total_compte_tous_les_comptes(): void
    {
        $superadmin = $this->superadminReel();
        $this->appareil($this->membre());
        $this->appareil($this->membre());
        $this->appareil($this->membre());

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->getContent();

        $this->assertStringContainsString('3', $html);
    }

    public function test_la_recherche_filtre_par_nom_ou_email_du_proprietaire(): void
    {
        $superadmin = $this->superadminReel();
        $cherche = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alpha Zebra']);
        $autre = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Beta Yak']);

        $appareilCherche = $this->appareil($cherche);
        $appareilAutre = $this->appareil($autre);

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices', ['q' => 'Alpha Zebra']))
            ->getContent();

        $this->assertStringContainsString(mb_substr($appareilCherche->endpoint_hash, 0, 8), $html);
        $this->assertStringNotContainsString(mb_substr($appareilAutre->endpoint_hash, 0, 8), $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. Suppression ciblée + journalisation (MASTER section 2/4)
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_superadmin_reel_peut_retirer_l_abonnement_d_un_autre_compte(): void
    {
        $superadmin = $this->superadminReel();
        $proprietaire = $this->membre();
        $appareil = $this->appareil($proprietaire);
        $autreAppareilDuMemeProprietaire = $this->appareil($proprietaire, 'Autre appareil');

        $this->actingAs($superadmin)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]))
            ->assertRedirect();

        $this->assertNull(PushSubscription::query()->find($appareil->id));
        $this->assertNotNull(
            PushSubscription::query()->find($autreAppareilDuMemeProprietaire->id),
            'La suppression doit être strictement ciblée : ne jamais toucher une autre ligne.'
        );
    }

    public function test_supprimer_un_identifiant_deja_absent_n_est_pas_une_erreur(): void
    {
        $superadmin = $this->superadminReel();
        $appareil = $this->appareil($this->membre());
        $appareil->delete();

        $this->actingAs($superadmin)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]))
            ->assertRedirect();
    }

    public function test_la_suppression_est_journalisee_sans_endpoint_ni_clef(): void
    {
        Log::spy();

        $superadmin = $this->superadminReel();
        $proprietaire = $this->membre();
        $appareil = $this->appareil($proprietaire);

        $this->actingAs($superadmin)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]));

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $contexte) use ($superadmin, $proprietaire, $appareil) {
                $contenu = json_encode($contexte);

                return str_contains($message, 'TASK-1821')
                    && $contexte['par'] === (string) $superadmin->id
                    && $contexte['proprietaire'] === $proprietaire->id
                    && $contexte['abonnement'] === $appareil->id
                    && $contexte['retire'] === true
                    && ! str_contains($contenu, 'fcm.googleapis.com')
                    && ! str_contains($contenu, str_repeat('A', 87));
            })
            ->once();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5. Aucune clef ni endpoint complet dans le HTML
    // ═══════════════════════════════════════════════════════════════════

    public function test_aucune_cle_ni_endpoint_complet_n_apparait_dans_le_html(): void
    {
        $superadmin = $this->superadminReel();
        $appareil = $this->appareil($this->membre());

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->getContent();

        $this->assertStringNotContainsString($appareil->endpoint, $html);
        $this->assertStringNotContainsString($appareil->p256dh, $html);
        $this->assertStringNotContainsString($appareil->auth, $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6. Le lien de navigation n'apparaît que pour qui est réellement autorisé
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_lien_de_navigation_est_absent_pour_un_compte_de_demonstration(): void
    {
        // Lu depuis un AUTRE ecran d'administration : la page elle-meme
        // rendrait 403 a ce compte, et son contenu ne dirait rien du rail.
        $html = $this->actingAs($this->adminDemo())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('admin.outils.my-push-devices'), $html);
    }

    public function test_le_lien_de_navigation_est_present_pour_le_superadmin_reel(): void
    {
        $html = $this->actingAs($this->superadminReel())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.outils.my-push-devices'), $html);
    }
}
