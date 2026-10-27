<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1714 volet 1 — choisir son Organization depuis l'entree generique.
 *
 * ## Les deux moities, et pourquoi aucune ne suffit
 *
 * Une liste vide passerait tous les tests de NON-exposition. Une liste
 * exhaustive passerait tous les tests de presence. Les deux sont donc
 * mesurees, et le volet « ce qui ne doit PAS apparaitre » est le plus
 * important : c'est lui qui protege les Organizations privees.
 *
 * ## Mesurer la REGION, jamais la page
 *
 * Le nom d'une Organization apparait a plusieurs endroits d'une page
 * d'inscription — titre, sous-titre, en-tete de marque. `assertSee($nom)`
 * serait donc VRAI meme sans aucun selecteur, et `assertDontSee($nom)` serait
 * FAUX pour l'Organization courante alors que tout va bien. Les gardes
 * decoupent donc le bloc `data-marqueur="selecteur-organisation"` et ne
 * regardent que lui (lecon T1697).
 */
class TASK1714OrganizationSelectorTest extends TestCase
{
    use RefreshDatabase;

    private function defaut(): Organization
    {
        return Organization::factory()->create([
            'slug' => 'defaut-1714', 'name' => 'Organisation Par Defaut',
            'is_active' => true, 'is_public' => true, 'is_default' => true,
        ]);
    }

    /** Le bloc du selecteur, ou `null` s'il n'est pas rendu. */
    private function regionSelecteur(string $url): ?string
    {
        $html = $this->get($url)->assertOk()->getContent();
        $debut = strpos($html, 'data-marqueur="selecteur-organisation"');

        if ($debut === false) {
            return null;
        }

        $fin = strpos($html, 'data-marqueur="fermer-choix-organisation"', $debut);

        return substr($html, $debut, ($fin === false ? strlen($html) : $fin) - $debut);
    }

    // ── La liste parait la ou il faut ───────────────────────────────────────

    public function test_le_selecteur_parait_sur_l_entree_generique(): void
    {
        $defaut = $this->defaut();
        $autre = Organization::factory()->create(['slug' => 'autre-1714', 'name' => 'Autre Organisation', 'is_active' => true, 'is_public' => true]);

        $region = $this->regionSelecteur('/register');

        $this->assertNotNull($region, 'Le selecteur est absent de l\'entree generique.');
        $this->assertStringContainsString('data-marqueur="organisation-'.$defaut->slug.'"', $region);
        $this->assertStringContainsString('data-marqueur="organisation-'.$autre->slug.'"', $region);
    }

    public function test_le_selecteur_parait_sur_l_organisation_par_defaut(): void
    {
        $defaut = $this->defaut();

        $region = $this->regionSelecteur('/org/'.$defaut->slug.'/register');

        $this->assertNotNull($region, 'Le selecteur est absent de /org/<defaut>/register.');
    }

    /**
     * Sur l'entree d'une Organization PRECISE, aucun selecteur.
     *
     * Quelqu'un arrive la par une invitation ou un lien de campagne : lui
     * proposer de partir ailleurs le detournerait de ce qu'il cherchait.
     */
    public function test_le_selecteur_est_absent_de_l_entree_d_une_organisation_precise(): void
    {
        $this->defaut();
        $autre = Organization::factory()->create(['slug' => 'precise-1714', 'name' => 'Organisation Precise', 'is_active' => true, 'is_public' => true]);

        $this->assertNull(
            $this->regionSelecteur('/org/'.$autre->slug.'/register'),
            'Le selecteur parait sur l\'entree d\'une Organization precise.',
        );
    }

    // ── Ce qui ne doit JAMAIS apparaitre ────────────────────────────────────

    public static function organisationsNonExposables(): array
    {
        return [
            'privee' => [['is_active' => true, 'is_public' => false]],
            'inactive' => [['is_active' => false, 'is_public' => true]],
            'inactive et privee' => [['is_active' => false, 'is_public' => false]],
        ];
    }

    /**
     * @param array<string, mixed> $etat
     *
     */
    #[DataProvider('organisationsNonExposables')]
    public function test_une_organisation_fermee_n_est_pas_listee(array $etat): void
    {
        $this->defaut();
        $cachee = Organization::factory()->create($etat + ['slug' => 'cachee-1714', 'name' => 'Organisation Cachee']);

        $region = $this->regionSelecteur('/register');

        $this->assertNotNull($region);
        $this->assertStringNotContainsString('data-marqueur="organisation-'.$cachee->slug.'"', $region);
    }

    /**
     * Une sandbox de scenario n'est pas listee, et ce n'est pas un detail.
     *
     * `RegisteredUserController::store()` refuse deja l'inscription dans un
     * monde de demonstration (TASK-1650). La lister ici enverrait les gens
     * dans un mur quelques lignes plus bas.
     */
    public function test_une_sandbox_de_scenario_n_est_pas_listee(): void
    {
        $this->defaut();
        $sandbox = Organization::factory()->create([
            'slug' => 'sandbox-1714', 'name' => 'Monde De Demonstration',
            'is_active' => true, 'is_public' => true,
            'scenario_sandbox_created_at' => now(),
        ]);

        $region = $this->regionSelecteur('/register');

        $this->assertNotNull($region);
        $this->assertStringNotContainsString('data-marqueur="organisation-'.$sandbox->slug.'"', $region);
    }

    // ── Les liens menent a l'INSCRIPTION, pas a l'accueil ───────────────────

    public function test_chaque_lien_pointe_la_route_d_inscription_de_son_organisation(): void
    {
        $this->defaut();
        $cible = Organization::factory()->create(['slug' => 'cible-1714', 'name' => 'Organisation Cible', 'is_active' => true, 'is_public' => true]);

        $region = $this->regionSelecteur('/register');

        $this->assertNotNull($region);
        $this->assertStringContainsString(
            'href="'.route('organization.register', ['organization' => $cible->slug]).'"',
            $region,
        );
    }

    /**
     * Le lien mene a une page qui fonctionne.
     *
     * Une garde qui verifie l'URL sans la SUIVRE laisserait passer un lien
     * bien forme vers un 404 (lecon T1665/T1666).
     */
    public function test_le_lien_mene_a_une_page_d_inscription_reellement_servie(): void
    {
        $this->defaut();
        $cible = Organization::factory()->create(['slug' => 'suivi-1714', 'name' => 'Organisation Suivie', 'is_active' => true, 'is_public' => true]);

        $this->get(route('organization.register', ['organization' => $cible->slug]))->assertOk();
    }

    // ── Le volet 1 n'a RIEN retire a T1712 ni a T1713 ───────────────────────

    /**
     * L'inscription reste gardee par la regle de nom de TASK-1712.
     *
     * Le volet 1 touche `create()` ; `store()` ne doit pas avoir bouge. La
     * mesure vaut mieux que l'affirmation : un `use` ajoute au mauvais endroit
     * ou une signature modifiee casserait la chaine sans bruit.
     */
    public function test_l_inscription_refuse_toujours_un_nom_publicitaire(): void
    {
        $defaut = $this->defaut();
        app()->instance('current_organization', $defaut);

        $reponse = $this->post('/register', [
            'name' => '+2.84567197 BTC. GET ->>>  graph.org/promo',
            'first_name' => 'Kimberly',
            'email' => 'spam-1714@example.test',
            'phone' => '0600000000',
            'country_code' => 'FR',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
        ]);

        $reponse->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('users', ['email' => 'spam-1714@example.test']);
    }
}
