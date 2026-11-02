<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * TASK-1742 — choisir l'Organization depuis une page de connexion.
 *
 * ## Le defaut, et pourquoi la suite de T1728 ne le voyait pas
 *
 * Le selecteur existe depuis T1728. Sur `test.laravel` il n'apparaissait
 * pourtant JAMAIS, et la cause n'etait ni dans le selecteur ni dans le
 * controleur : `AppServiceProvider` porte un `View::composer('*')` qui, faute
 * d'Organization liee a la requete, partage l'organisation PAR DEFAUT pour
 * l'habillage de la page.
 *
 * La vue lisait `$isOrg = ! is_null($currentOrganization)` et en deduisait
 * qu'elle etait sur la porte d'une Organization. `/login` se comportait donc
 * silencieusement comme la page de connexion de Main : pas de selecteur, le
 * sous-titre de Main, et un lien « essayer la connexion globale » alors qu'on
 * y etait deja.
 *
 * La suite de T1728 ne pouvait pas l'attraper : son jeu de donnees ne cree
 * AUCUNE organisation `is_default`, donc `$currentOrganization` y restait nul
 * et le selecteur s'affichait. Les tests de cette classe posent donc
 * explicitement une organisation par defaut — c'est tout l'ecart entre le banc
 * et le site reel.
 */
class TASK1742LoginOrganizationChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaut;

    private Organization $autre;

    private Organization $privee;

    protected function setUp(): void
    {
        parent::setUp();

        // L'organisation PAR DEFAUT, celle que le compositeur d'habillage
        // partage quand la requete n'en designe aucune. C'est elle qui
        // masquait le selecteur.
        $this->defaut = Organization::factory()->create([
            'slug' => 'main-defaut', 'name' => 'Principale',
            'is_active' => true, 'is_public' => true, 'is_default' => true,
        ]);

        $this->autre = Organization::factory()->create([
            'slug' => 'autre-pub', 'name' => 'Autre Publique',
            'is_active' => true, 'is_public' => true, 'is_default' => false,
        ]);

        $this->privee = Organization::factory()->create([
            'slug' => 'privee', 'name' => 'Privee',
            'is_active' => true, 'is_public' => false, 'is_default' => false,
        ]);
    }

    // ── Le defaut corrige ───────────────────────────────────────────────────

    public function test_le_selecteur_apparait_sur_login_MALGRE_une_organisation_par_defaut(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('data-login-organization-selector', $html);
        $this->assertStringContainsString('value="'.$this->defaut->slug.'"', $html);
        $this->assertStringContainsString('value="'.$this->autre->slug.'"', $html);
    }

    public function test_une_organisation_PRIVEE_n_est_pas_proposee(): void
    {
        // La page est publique : y lister une Organization privee reviendrait
        // a annoncer son existence a qui n'en est pas membre.
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('value="'.$this->privee->slug.'"', $html);
    }

    public function test_login_ne_se_presente_PAS_comme_la_porte_de_l_organisation_par_defaut(): void
    {
        // Le lien « essayer la connexion globale » n'a de sens que sur la
        // porte d'une Organization. Sur `/login`, il invitait a aller la ou
        // l'on etait deja.
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-login-global-fallback', $html);
    }

    // ── Ce que T1728 a decide, et qui ne doit pas bouger ────────────────────

    public function test_la_porte_d_une_organisation_ne_porte_toujours_pas_de_selecteur_poste(): void
    {
        // Sur `/org/{slug}/login`, l'Organization vient de la ROUTE et le
        // serveur refuse qu'un champ la contredise. Un champ poste y serait au
        // mieux inutile, au pire une invitation a mentir.
        $html = $this->get(route('organization.login', $this->autre))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-login-organization-selector', $html);
    }

    public function test_la_porte_d_une_organisation_propose_d_ALLER_vers_une_autre(): void
    {
        // Ce que MASTER demande — pouvoir changer d'Organization depuis
        // n'importe quelle page de connexion — sans contredire la regle
        // ci-dessus : on NAVIGUE vers l'autre porte, on ne poste pas un champ.
        $html = $this->get(route('organization.login', $this->autre))->assertOk()->getContent();

        $this->assertStringContainsString('data-login-organization-switch', $html);
        $this->assertStringContainsString(route('organization.login', $this->defaut), $html);
        $this->assertStringNotContainsString(route('organization.login', $this->privee), $html);

        // Et pas de lien vers sa propre porte : on y est.
        $this->assertStringNotContainsString(
            'href="'.route('organization.login', $this->autre).'"',
            $html,
        );
    }

    // ── La recette de MASTER, de bout en bout ───────────────────────────────

    public function test_depuis_login_on_choisit_une_organisation_et_on_arrive_chez_elle(): void
    {
        $membre = User::factory()->create([
            'organization_id' => $this->autre->id,
            'email' => 'membre@autre.test',
            'password' => Hash::make('mot-de-passe-de-recette'),
            'email_verified_at' => now(),
            'approved_at' => now(),
        ]);

        $this->post(route('login'), [
            'email' => 'membre@autre.test',
            'password' => 'mot-de-passe-de-recette',
            'organization' => $this->autre->slug,
        ])->assertRedirect();

        $this->assertAuthenticatedAs($membre->fresh());
        $this->assertSame($this->autre->id, auth()->user()->organization_id);
    }

    public function test_une_adresse_AMBIGUE_se_resout_quand_on_choisit_l_organisation(): void
    {
        // C'est le cas qui bloquait MASTER : la meme adresse dans deux
        // Organizations etait refusee sans recours (T1728, et c'est juste).
        // Le selecteur est precisement le moyen de LEVER l'ambiguite.
        foreach ([$this->defaut, $this->autre] as $org) {
            User::factory()->create([
                'organization_id' => $org->id,
                'email' => 'ambigu@exemple.test',
                'password' => Hash::make('mot-de-passe-de-recette'),
                'email_verified_at' => now(),
                'approved_at' => now(),
            ]);
        }

        // Sans choix : refus, et AUCUN mot de passe teste.
        $this->post(route('login'), [
            'email' => 'ambigu@exemple.test',
            'password' => 'mot-de-passe-de-recette',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Avec choix : la bonne porte s'ouvre.
        $this->post(route('login'), [
            'email' => 'ambigu@exemple.test',
            'password' => 'mot-de-passe-de-recette',
            'organization' => $this->autre->slug,
        ])->assertRedirect();

        $this->assertAuthenticated();
        $this->assertSame($this->autre->id, auth()->user()->organization_id);
    }

    public function test_l_organisation_choisie_SURVIT_a_un_echec(): void
    {
        // MASTER : « Conserver l'organisation selectionnee en cas d'echec. »
        // Sans cela, chaque faute de frappe sur le mot de passe reposerait la
        // question du choix.
        $this->post(route('login'), [
            'email' => 'inconnu@exemple.test',
            'password' => 'mauvais',
            'organization' => $this->autre->slug,
        ])->assertSessionHasErrors('email');

        $html = $this->followingRedirects()
            ->get(route('login'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'value="'.$this->autre->slug.'" selected',
            $html,
            "l'organisation choisie n'est pas conservee apres un echec",
        );
    }
}
