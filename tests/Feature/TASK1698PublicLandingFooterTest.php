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
 * ## La cause, et ce qu'elle a invalide d'un premier correctif
 *
 * Premiere mesure : quatre des six destinations exigeaient `auth` +
 * `EnsureOrganizationMember`, et j'en avais conclu que leur absence n'etait pas
 * un defaut — d'ou un pied a DEUX versions, servant un equivalent public quand
 * il en existait un.
 *
 * Cette lecture etait fausse, et la vraie cause est plus simple : le groupe
 * `/org/{organization}` est FERME PAR DEFAUT, et une route publique doit s'en
 * extraire par `withoutMiddleware` — ce que font `home` et `mentions-legales`.
 * `flowchart`, `mycelium` et `open-source` ont ete ecrites publiques,
 * DOCUMENTEES publiques (« arbitrage MASTER : la page reste PUBLIQUE »), munies
 * de leur garde propre… et sans cette ligne. Le groupe les a refermees en
 * silence. `git log -L` le prouve : ces lignes n'ont jamais ete touchees que par
 * leurs TASK creatrices — T1604, T1608, T1613 — **jamais par T1692**, arrivee
 * apres et qui a fige l'etat observe.
 *
 * L'opt-out pose, il n'y a plus de double version a servir : **UN** pied,
 * identique pour tout le monde, borne a l'Organization de l'URL. Les trois
 * tests de « version publique » de la premiere passe sont donc supprimes : leur
 * objet n'existe plus.
 *
 * Reste exactement un mur de connexion, et il est VOULU : « Un bug ? »
 * (`organization.bug-reports.index` est legitimement membre-only).
 *
 * Et deux defauts de la premiere mesure tenaient bon :
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

    /**
     * Les trois destinations rouvertes sont servies BORNEES a l'Organization de
     * l'URL, et atteignables par un anonyme. Les deux faits sont mesures
     * ensemble : une poignee qui mene a une redirection n'est pas un lien, et
     * une porte ouverte sans poignee n'est pas une entree.
     */
    public function test_les_trois_destinations_rouvertes_sont_bornees_et_ouvertes(): void
    {
        $html = $this->landing();

        foreach ([
            'mycelium' => 'organization.mycelium',
            'flowchart' => 'organization.flowchart',
            'open-source' => 'organization.open-source',
        ] as $chemin => $route) {
            $url = route($route, ['organization' => 'main']);

            $this->assertStringContainsString('href="'.$url.'"', $html, "[{$chemin}] n'est pas borne a l'Organization de l'URL.");

            app()->forgetInstance('current_organization');
            $this->get($url)->assertOk();
            app()->instance('current_organization', $this->organization);
        }
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
            'data-footer-mycelium' => 'Mycelium',
            'data-footer-flowchart' => 'Logigramme',
            'data-footer-opensource' => 'OpenSource',
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
     * Un SEUL mur de connexion dans le pied, et il est voulu : « Un bug ? ».
     *
     * Cette garde est la contre-epreuve du correctif : si une prochaine TASK
     * referme l'une des trois routes rouvertes — ou oublie son opt-out en
     * deplacant une ligne — le pied se remettrait a proposer des redirections,
     * et c'est ici que cela rougira.
     */
    public function test_un_seul_lien_du_pied_exige_encore_une_connexion(): void
    {
        $html = $this->landing();

        $bugs = route('organization.bug-reports.index', ['organization' => 'main']);
        $this->assertStringContainsString('href="'.$bugs.'"', $html);

        app()->forgetInstance('current_organization');
        $this->get($bugs)->assertRedirect(
            route('organization.login', ['organization' => 'main'])
        );
        app()->instance('current_organization', $this->organization);
    }

    // ── Membre connecte : rien ne doit avoir ete perdu ──────────────────────

    public function test_un_membre_voit_le_meme_pied_que_l_anonyme(): void
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

        // TASK-1698 — et le pied est le MEME que celui de l'anonyme : plus de
        // double version, donc aucun marqueur en `-public` a servir ici.
        $this->assertStringNotContainsString('-public', $html);
    }
}
