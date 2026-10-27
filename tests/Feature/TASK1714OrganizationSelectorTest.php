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

        $fin = strpos($html, '</select>', $debut);

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

    public function test_le_menu_parait_sur_l_organisation_par_defaut(): void
    {
        $defaut = $this->defaut();
        // Deux Organizations ouvertes au minimum : avec une seule, le menu ne
        // parait pas — un choix sans alternative n'est pas un choix.
        Organization::factory()->create(['slug' => 'seconde-def-1714', 'name' => 'Seconde', 'is_active' => true, 'is_public' => true]);

        $region = $this->regionSelecteur('/org/'.$defaut->slug.'/register');

        $this->assertNotNull($region, 'Le menu est absent de /org/<defaut>/register.');
    }

    /**
     * Sur une organisation PRECISE, aucun menu (arbitrage Cyril, 27/10).
     *
     * Le menu est un RATTRAPAGE : « les personnes ne connaissent pas forcement
     * l'adresse pour s'inscrire dans leur organisation, et c'est pour ca qu'on
     * donne cette possibilite de passer par celle de BouclePro par defaut ».
     *
     * Quelqu'un qui arrive sur `/org/launchpals/register` connait son adresse.
     * Lui proposer de choisir ne l'aiderait pas, et brouillerait le message de
     * la page.
     */
    public function test_aucun_menu_sur_une_organisation_precise(): void
    {
        $this->defaut();
        Organization::factory()->create(['slug' => 'seconde-p-1714', 'name' => 'Seconde', 'is_active' => true, 'is_public' => true]);
        $autre = Organization::factory()->create(['slug' => 'precise-1714', 'name' => 'Organisation Precise', 'is_active' => true, 'is_public' => true]);

        $this->assertNull(
            $this->regionSelecteur('/org/'.$autre->slug.'/register'),
            'Le menu parait sur l\'entree d\'une organisation precise.',
        );
    }

    /**
     * Le menu porte des SLUGS, et aucune URL.
     *
     * Il ne navigue plus : il designe. Une URL dans le formulaire serait une
     * URL a valider, c'est-a-dire une surface d'attaque la ou il n'y en a pas.
     */
    public function test_le_menu_porte_des_slugs_et_aucune_url(): void
    {
        $this->defaut();
        $cible = Organization::factory()->create(['slug' => 'seconde-1714', 'name' => 'Seconde', 'is_active' => true, 'is_public' => true]);

        $region = $this->regionSelecteur('/register');
        $this->assertNotNull($region);

        $this->assertStringContainsString('name="organisation_confirmee"', $region);
        $this->assertStringContainsString('value="'.$cible->slug.'"', $region);
        $this->assertStringNotContainsString('http', $region);
    }

    /**
     * Une seule Organization ouverte : aucun menu.
     *
     * Un choix sans alternative n'est pas un choix — c'est un champ de plus a
     * lire sur un formulaire d'inscription deja long.
     */
    public function test_aucun_menu_quand_une_seule_organisation_est_ouverte(): void
    {
        $this->defaut();

        $this->assertNull($this->regionSelecteur('/register'), 'Un menu parait pour une seule Organization.');
    }

    /**
     * AUCUNE organisation n'est pre-selectionnee (arbitrage Cyril, 27/10).
     *
     * Une valeur pre-selectionnee se laisse traverser sans etre lue : la
     * personne heriterait de l'organisation ou le lien l'a menee au lieu de la
     * designer. Le premier choix est donc un placeholder vide, et c'est LUI qui
     * porte `selected`.
     */
    public function test_aucune_organisation_n_est_preselectionnee(): void
    {
        $defaut = $this->defaut();
        Organization::factory()->create(['slug' => 'autre-sel-1714', 'name' => 'Autre', 'is_active' => true, 'is_public' => true]);

        $region = $this->regionSelecteur('/org/'.$defaut->slug.'/register');
        $this->assertNotNull($region);

        // Le champ est `required` : rien de pre-selectionne SANS cela, c'est un
        // formulaire qu'on peut soumettre sans avoir choisi. Le refus serveur
        // existe (`test_sans_confirmation_l_inscription_est_refusee`), mais il
        // arrive apres un aller-retour et la perte du focus.
        $this->assertMatchesRegularExpression('/<select[^>]*\brequired\b/', $region, 'Le menu n\'est pas obligatoire.');

        // Le placeholder porte `selected`, et sa valeur est VIDE : le menu ne
        // peut donc pas naviguer tant que rien n'est choisi.
        $this->assertMatchesRegularExpression(
            '/<option value=""[^>]*data-marqueur="organisation-aucune"[^>]*selected/',
            $region,
            'Le choix vide n\'est pas celui qui est selectionne.',
        );

        // Et AUCUNE organisation reelle ne porte `selected`, pas meme la
        // courante — c'est precisement ce qui a ete corrige.
        $apresPlaceholder = substr($region, (int) strpos($region, 'data-marqueur="organisation-aucune"'));
        $apresPlaceholder = substr($apresPlaceholder, (int) strpos($apresPlaceholder, '</option>'));
        $this->assertStringNotContainsString('selected', $apresPlaceholder);
    }

    /**
     * Le menu precede le champ « prenom » (arbitrage Cyril, 27/10).
     *
     * Ce n'est pas qu'une preference de mise en page : le menu NAVIGUE. Place
     * apres le prenom, il coupait la saisie d'identite en deux et pouvait
     * faire perdre ce qui venait d'etre tape. On choisit OU l'on s'inscrit
     * avant de dire QUI l'on est.
     *
     * La garde compare des POSITIONS dans le document, pas une fenetre de
     * texte : c'est l'ORDRE qui est le sujet.
     */
    public function test_le_menu_precede_le_champ_prenom(): void
    {
        $this->defaut();
        Organization::factory()->create(['slug' => 'ordre-1714', 'name' => 'Seconde', 'is_active' => true, 'is_public' => true]);

        $html = $this->get('/register')->assertOk()->getContent();

        $menu = strpos($html, 'data-marqueur="selecteur-organisation"');
        $prenom = strpos($html, 'id="first_name"');

        $this->assertNotFalse($menu, 'Le menu est absent.');
        $this->assertNotFalse($prenom, 'Le champ prenom est absent.');
        $this->assertLessThan($prenom, $menu, 'Le menu est pose APRES le champ prenom.');
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
        // Une SECONDE Organization ouverte, sinon le menu ne parait pas du tout
        // et la garde d'absence serait vacante.
        Organization::factory()->create(['slug' => 'visible-1714', 'name' => 'Organisation Visible', 'is_active' => true, 'is_public' => true]);
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
        Organization::factory()->create(['slug' => 'visible-sb-1714', 'name' => 'Organisation Visible', 'is_active' => true, 'is_public' => true]);
        $sandbox = Organization::factory()->create([
            'slug' => 'sandbox-1714', 'name' => 'Monde De Demonstration',
            'is_active' => true, 'is_public' => true,
            'scenario_sandbox_created_at' => now(),
        ]);

        $region = $this->regionSelecteur('/register');

        $this->assertNotNull($region);
        $this->assertStringNotContainsString('data-marqueur="organisation-'.$sandbox->slug.'"', $region);
    }


    // ── Le menu CONFIRME, il ne selectionne pas ─────────────────────────────

    /** @param array<string, mixed> $surcharges */
    private function inscription(array $surcharges = []): array
    {
        return array_merge([
            'first_name' => 'Camille', 'name' => 'Dubois',
            'email' => 'confirm-1714@example.test', 'phone' => '0601020304',
            'country_code' => 'FR',
            'password' => 'MotDePasse!2026', 'password_confirmation' => 'MotDePasse!2026',
        ], $surcharges);
    }

    /** Deux Organizations ouvertes : le menu est alors rendu, donc exige. */
    private function deuxOuvertes(): array
    {
        $defaut = $this->defaut();
        $autre = Organization::factory()->create([
            'slug' => 'autre-conf-1714', 'name' => 'Autre', 'is_active' => true, 'is_public' => true,
        ]);

        return [$defaut, $autre];
    }

    public function test_sans_confirmation_l_inscription_est_refusee(): void
    {
        [$defaut] = $this->deuxOuvertes();
        app()->instance('current_organization', $defaut);

        $this->post('/org/'.$defaut->slug.'/register', $this->inscription())
            ->assertSessionHasErrors('organisation_confirmee');

        $this->assertDatabaseMissing('users', ['email' => 'confirm-1714@example.test']);
    }

    public function test_la_confirmation_du_slug_courant_laisse_passer(): void
    {
        [$defaut] = $this->deuxOuvertes();
        app()->instance('current_organization', $defaut);

        $this->post('/org/'.$defaut->slug.'/register', $this->inscription([
            'organisation_confirmee' => $defaut->slug,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'confirm-1714@example.test',
            'organization_id' => $defaut->id,
        ]);
    }

    /**
     * Le choix DESIGNE : le compte nait dans l'organisation choisie.
     *
     * C'est la fonctionnalite meme — quelqu'un qui ne connait pas l'adresse de
     * son organisation passe par BouclePro et se route lui-meme.
     */
    public function test_le_choix_cree_le_compte_dans_l_organisation_designee(): void
    {
        [$defaut, $autre] = $this->deuxOuvertes();
        app()->instance('current_organization', $defaut);

        $this->post('/org/'.$defaut->slug.'/register', $this->inscription([
            'organisation_confirmee' => $autre->slug,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', [
            'email' => 'confirm-1714@example.test',
            'organization_id' => $autre->id,
        ]);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function organisationsNonChoisissables(): array
    {
        return [
            'privee' => [['is_active' => true, 'is_public' => false]],
            'inactive' => [['is_active' => false, 'is_public' => true]],
            'sandbox de scenario' => [['is_active' => true, 'is_public' => true, 'scenario_sandbox_created_at' => '2026-10-01 00:00:00']],
        ];
    }

    /**
     * Une organisation FERMEE ne peut pas etre choisie, meme en postant son slug.
     *
     * C'est la frontiere de securite, et elle a change de forme avec la
     * conception : le champ DESIGNE desormais l'organisation, donc ce n'est
     * plus l'URL qui protege. Ce qui protege, c'est que les valeurs acceptees
     * sont EXACTEMENT les organisations ouvertes — celles dont la page
     * d'inscription est de toute facon publiquement joignable. Poster leur
     * slug n'ouvre rien que naviguer n'ouvrirait ; poster celui d'une
     * organisation fermee ne doit rien ouvrir du tout.
     *
     * @param array<string, mixed> $etat
     */
    #[DataProvider('organisationsNonChoisissables')]
    public function test_une_organisation_fermee_ne_peut_pas_etre_choisie(array $etat): void
    {
        [$defaut] = $this->deuxOuvertes();
        app()->instance('current_organization', $defaut);

        $fermee = Organization::factory()->create($etat + ['slug' => 'fermee-1714', 'name' => 'Fermee']);

        $this->post('/org/'.$defaut->slug.'/register', $this->inscription([
            'organisation_confirmee' => $fermee->slug,
        ]))->assertSessionHasErrors('organisation_confirmee');

        $this->assertDatabaseMissing('users', ['email' => 'confirm-1714@example.test']);
    }

    /**
     * Sur une organisation PRECISE, le champ est purement IGNORE.
     *
     * Sans cela, il suffirait de le poster a la main depuis n'importe quelle
     * page pour se faire router ailleurs — le menu n'y est pas rendu, mais un
     * champ absent de la page reste postable.
     */
    public function test_sur_une_organisation_precise_le_champ_est_ignore(): void
    {
        $defaut = $this->defaut();
        $autre = Organization::factory()->create(['slug' => 'autre-conf-1714', 'name' => 'Autre', 'is_active' => true, 'is_public' => true]);
        $precise = Organization::factory()->create(['slug' => 'precise-ign-1714', 'name' => 'Precise', 'is_active' => true, 'is_public' => true]);
        app()->instance('current_organization', $precise);

        $this->post('/org/'.$precise->slug.'/register', $this->inscription([
            'organisation_confirmee' => $autre->slug,
        ]))->assertSessionHasNoErrors();

        // Le compte nait dans l'organisation de l'URL, pas dans celle postee.
        $this->assertDatabaseHas('users', [
            'email' => 'confirm-1714@example.test',
            'organization_id' => $precise->id,
        ]);
    }

    /**
     * Une seule Organization ouverte : aucun menu, donc aucune exigence.
     *
     * Exiger la confirmation d'un champ qui n'est pas rendu bloquerait toute
     * inscription sur une installation mono-Organization.
     */
    public function test_sans_menu_la_confirmation_n_est_pas_exigee(): void
    {
        $defaut = $this->defaut();
        app()->instance('current_organization', $defaut);

        $this->post('/org/'.$defaut->slug.'/register', $this->inscription())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'confirm-1714@example.test']);
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
