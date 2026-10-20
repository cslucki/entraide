<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * TASK-1693, bug B — le SuperAdmin plateforme peut valider une adresse email.
 *
 * ## Ce qui manquait
 *
 * Avant cette tranche, TROIS chemins seulement posaient `email_verified_at` :
 * le parcours d'invitation (le jeton est arrive sur cette adresse exacte), les
 * Scenario Packs (donnees de demonstration), et le lien signe
 * `verification.verify`. Un compte cree a la main, ou dont l'email n'est jamais
 * arrive, restait donc bloque sans recours : `EnsureEmailIsVerified` le
 * detourne vers `/verify-email` sur toute surface prefixee, et
 * l'administration n'exposait ni l'etat, ni le moyen de chercher ces comptes,
 * ni le geste pour les debloquer.
 *
 * ## Le titulaire du geste
 *
 * Valider manuellement une adresse CONTOURNE une preuve de possession. Le
 * geste appartient donc au SuperAdmin PLATEFORME (`AdminMiddleware`), jamais a
 * un admin d'Organization — qui pourrait sinon valider une adresse qu'il ne
 * controle pas. C'est ce que mesure
 * `test_the_gesture_belongs_to_the_platform_superadmin_alone`.
 */
class TASK1693AdminEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693b',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function membre(array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'organization_id' => $this->organization->id,
            'email_verified_at' => null,
        ]);
    }

    public function test_the_superadmin_validates_an_address_and_the_account_is_unblocked(): void
    {
        $membre = $this->membre();

        // Prémisse : la garde bloque réellement ce compte. Sans elle, le test
        // ne mesurerait que l'écriture d'une colonne.
        $this->actingAs($membre)
            ->get(route('organization.dossiers.index', ['organization' => $this->organization->slug]))
            ->assertRedirect(route('verification.notice'));

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.verify-email', $membre))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($membre->fresh()->email_verified_at);

        // Et la garde laisse maintenant passer : c'est le FAIT attendu, pas la
        // colonne. `fresh()` modélise une vraie seconde requête.
        $this->actingAs($membre->fresh())
            ->get(route('organization.dossiers.index', ['organization' => $this->organization->slug]))
            ->assertOk();
    }

    /**
     * Le geste n'appartient qu'au SuperAdmin plateforme.
     *
     * On mesure les trois acteurs qui pourraient le croire a leur portee, et
     * on verifie qu'AUCUN n'ecrit quoi que ce soit.
     */
    public function test_the_gesture_belongs_to_the_platform_superadmin_alone(): void
    {
        $membre = $this->membre();
        $url = route('admin.users.verify-email', $membre);

        // Anonyme d'abord : `actingAs()` persiste pour le reste du test.
        $this->post($url)->assertRedirect(route('login'));
        $this->assertNull($membre->fresh()->email_verified_at);

        $orgAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
        $this->organization->update(['admin_id' => $orgAdmin->id]);

        foreach ([$orgAdmin, $this->membre()] as $refuse) {
            $this->actingAs($refuse)->post($url)->assertForbidden();
            $this->assertNull(
                $membre->fresh()->email_verified_at,
                'aucun acteur hors SuperAdmin plateforme n\'ecrit la verification',
            );
        }
    }

    /**
     * Idempotent, et jamais une revalidation a la baisse.
     *
     * Rejouer le geste sur un compte deja verifie ne doit ni echouer, ni
     * DEPLACER la date d'origine : celle-ci est une trace, pas un compteur.
     */
    public function test_replaying_the_gesture_keeps_the_original_verification_date(): void
    {
        $origine = now()->subMonth()->startOfSecond();
        $membre = $this->membre(['email_verified_at' => $origine]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.verify-email', $membre))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(
            $origine->equalTo($membre->fresh()->email_verified_at),
            'la date de verification d\'origine est conservee',
        );
    }

    /** L'ecran expose l'etat, et le bouton n'existe que la ou il a un effet. */
    public function test_the_screen_shows_the_state_and_offers_the_gesture_only_where_it_acts(): void
    {
        $bloque = $this->membre();
        $verifie = $this->membre(['email_verified_at' => now()]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-user-email-unverified="'.$bloque->id.'"', $html);
        $this->assertStringContainsString('data-user-verify-email="'.$bloque->id.'"', $html);

        $this->assertStringNotContainsString('data-user-email-unverified="'.$verifie->id.'"', $html);
        $this->assertStringNotContainsString('data-user-verify-email="'.$verifie->id.'"', $html,
            'un geste sans effet est un piege : pas de bouton sur un compte deja verifie');
    }

    /**
     * Le filtre rend ces comptes TROUVABLES.
     *
     * C'etait la moitie du probleme signale : « je ne vois pas cela » sur
     * `/admin/users`.
     */
    public function test_the_unverified_filter_finds_exactly_the_blocked_accounts(): void
    {
        $bloque = $this->membre();
        $verifie = $this->membre(['email_verified_at' => now()]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users', ['status' => 'unverified']))
            ->assertOk()
            ->getContent();

        // La mesure vise le CORPS du tableau : un `select` d'Organizations ou un
        // compteur pourraient contenir le nom d'un compte filtre.
        $corps = substr($html, strpos($html, '<tbody'), strpos($html, '</tbody>') - strpos($html, '<tbody'));

        $this->assertStringContainsString($bloque->email, $corps);
        $this->assertStringNotContainsString($verifie->email, $corps);
        $this->assertStringNotContainsString($this->superAdmin->email, $corps);
    }

    /**
     * Le geste n'emet AUCUN evenement d'acquisition, et c'est deliberé.
     *
     * `ClaimGuestVisitorOnVerification` reagit a `Verified` en lisant le cookie
     * `bp_guest` de la REQUETE COURANTE. Ici la requete est celle du
     * SuperAdmin : emettre l'evenement lierait le visiteur anonyme de
     * l'ADMINISTRATEUR au compte du membre, et consignerait les dimensions
     * d'acquisition de l'admin comme celles du membre — un fait FAUX ecrit sur
     * une personne, pire que l'absence de fait.
     *
     * Ce test fige la decision : si quelqu'un ajoutait `event(new Verified(...))`
     * dans l'action d'administration, il rougirait.
     */
    public function test_the_gesture_emits_no_acquisition_event(): void
    {
        Event::fake([Verified::class]);

        $membre = $this->membre();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.verify-email', $membre))
            ->assertRedirect();

        $this->assertNotNull($membre->fresh()->email_verified_at, 'la verification est bien posee');

        Event::assertNotDispatched(Verified::class);
    }
}
