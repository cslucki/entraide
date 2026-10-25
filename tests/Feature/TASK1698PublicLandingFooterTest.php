<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1698 — le pied de page de la landing, vu par un visiteur ANONYME.
 *
 * Cyril : « sur /org/main en mode deconnecte, on ne voit plus Mycelium,
 * Logigramme, Mentions legales, OpenSource, Un bug ?, la version. Et on a un
 * lien BouclePro qui ne sert a rien ! »
 *
 * ## Ce que la mesure a corrige dans la question
 *
 * Les deux captures comparees n'etaient pas comparables : la production
 * montrait « Tableau de bord » en haut a droite (donc **connecte**), le site de
 * test « Connexion » (**deconnecte**). Une partie de l'ecart s'explique par
 * l'etat d'authentification, pas par une regression.
 *
 * Mesure des six destinations du pied :
 *
 * | lien | `auth` | membre | equivalent PUBLIC |
 * |---|---|---|---|
 * | Mycelium | OUI | OUI | **`mycelium`** |
 * | Logigramme | OUI | OUI | aucun |
 * | Mentions legales | non | non | — |
 * | OpenSource | OUI | OUI | **`open-source.page`** |
 * | Un bug ? | OUI | OUI | aucun |
 * | icone GitHub | **non** | **non** | — |
 *
 * **Quatre sur six exigent d'etre connecte ET membre.** Les montrer a un
 * visiteur anonyme l'enverrait sur une page de connexion : leur absence n'etait
 * donc PAS un defaut.
 *
 * Deux choses l'etaient :
 *
 * 1. le creneau de gauche rendait un lien vers la page COURANTE — le
 *    « BouclePro qui ne sert a rien » ;
 * 2. l'icone GitHub, qui n'exige RIEN, etait enfermee dans la garde interne.
 *
 * ## Le precedent, dans le code lui-meme
 *
 * `OrganizationLandingController` documente deja la meme sur-application de
 * cette garde : « la borner a `$showInternalHomepageContent` etait une
 * REGRESSION PRODUIT, pas un gain de confidentialite » — a propos des ateliers
 * publics. Le pied de page avait garde la sienne.
 */
class TASK1698PublicLandingFooterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_public' => true,
            'is_default' => true,
            'homepage_template' => 'bouclepro_hero_v2',
        ]);
        app()->instance('current_organization', $this->organization);
    }

    private function landing(?User $qui = null): string
    {
        $requete = $qui ? $this->actingAs($qui) : $this;

        return $requete->get(route('organization.home', ['organization' => 'main']))
            ->assertOk()
            ->getContent();
    }

    // ── Visiteur anonyme ────────────────────────────────────────────────────

    public function test_le_lien_vers_la_page_courante_a_disparu(): void
    {
        $html = $this->landing();

        $this->assertStringNotContainsString(
            'href="'.route('organization.home', ['organization' => 'main']).'"',
            $html,
            'Le pied rend encore un lien vers la page COURANTE.'
        );
    }

    public function test_mycelium_est_servi_dans_sa_version_publique(): void
    {
        $html = $this->landing();

        $this->assertStringContainsString('data-footer-mycelium-public', $html);
        $this->assertStringContainsString('href="'.route('mycelium').'"', $html);
    }

    public function test_opensource_est_servi_dans_sa_version_publique(): void
    {
        $html = $this->landing();

        $this->assertStringContainsString('data-footer-opensource-public', $html);
        $this->assertStringContainsString('href="'.route('open-source.page').'"', $html);
    }

    /** L'icone n'exige rien : elle n'avait aucune raison d'etre masquee. */
    public function test_l_icone_github_est_visible_sans_connexion(): void
    {
        $html = $this->landing();

        $this->assertStringContainsString('data-footer-github', $html);
        $this->assertStringContainsString('href="'.route('open-source.github').'"', $html);
    }

    public function test_les_mentions_legales_et_la_version_restent_la(): void
    {
        $html = $this->landing();

        $this->assertStringContainsString(
            'href="'.route('organization.mentions-legales', ['organization' => 'main']).'"',
            $html
        );
        $this->assertStringContainsString(config('app.version'), $html);
    }

    /**
     * Les SIX entrees sont servies hors connexion — decision de Cyril, capture
     * a l'appui. Ce qui varie est la CIBLE, pas la presence.
     */
    public function test_les_six_entrees_sont_servies_hors_connexion(): void
    {
        $html = $this->landing();

        foreach ([
            'data-footer-mycelium-public' => 'Mycelium (public)',
            'data-footer-flowchart' => 'Logigramme',
            'data-footer-opensource-public' => 'OpenSource (public)',
            'data-footer-bug' => 'Un bug ?',
            'data-footer-github' => 'icone GitHub',
        ] as $marqueur => $quoi) {
            $this->assertStringContainsString($marqueur, $html, "[{$quoi}] manque au pied.");
        }

        $this->assertStringContainsString(
            'href="'.route('organization.mentions-legales', ['organization' => 'main']).'"',
            $html
        );
    }

    /**
     * Mycelium et OpenSource ont une version PUBLIQUE : hors connexion, c'est
     * elle qu'on sert — meme libelle, sans mur de connexion.
     *
     * Logigramme et « Un bug ? » n'en ont AUCUNE : leurs routes exigent `auth`
     * + `EnsureOrganizationMember`. Un anonyme qui clique atterrit sur la page
     * de connexion. Cyril a tranche pour la parite visuelle ; cette garde fige
     * la distinction pour que personne ne croie plus tard qu'il s'agit d'un
     * oubli.
     */
    public function test_les_versions_publiques_sont_preferees_quand_elles_existent(): void
    {
        $html = $this->landing();

        // servies en PUBLIC
        $this->assertStringContainsString('href="'.route('mycelium').'"', $html);
        $this->assertStringContainsString('href="'.route('open-source.page').'"', $html);
        $this->assertStringNotContainsString(
            'href="'.route('organization.mycelium', ['organization' => 'main']).'"', $html);
        $this->assertStringNotContainsString(
            'href="'.route('organization.open-source', ['organization' => 'main']).'"', $html);

        // servies BORNEES, faute d'equivalent public : mur de connexion assume
        $this->assertStringContainsString(
            'href="'.route('organization.flowchart', ['organization' => 'main']).'"', $html);
        $this->assertStringContainsString(
            'href="'.route('organization.bug-reports.index', ['organization' => 'main']).'"', $html);
    }

    // ── Membre connecte : rien ne doit avoir ete perdu ──────────────────────

    public function test_un_membre_garde_les_six_liens(): void
    {
        $membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $html = $this->landing($membre);

        foreach ([
            'data-footer-mycelium',
            'data-footer-flowchart',
            'data-footer-opensource',
            'data-footer-bug',
            'data-footer-github',
        ] as $marqueur) {
            $this->assertStringContainsString($marqueur, $html, "[{$marqueur}] a disparu pour un membre.");
        }

        // et pas les versions publiques, qui n'ont de sens que hors connexion
        $this->assertStringNotContainsString('data-footer-mycelium-public', $html);
        $this->assertStringNotContainsString('data-footer-opensource-public', $html);
        $this->assertStringContainsString('data-footer-flowchart', $html);
        $this->assertStringContainsString('data-footer-bug', $html);
    }
}
