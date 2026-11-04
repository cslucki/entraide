<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1749 (demande Cyril, 04/11) — DIX pages portent la meme barre du haut.
 *
 * ## Ce que ce fichier garde
 *
 * Chacune de ces pages portait SON en-tete : un `<h1>` de 24 ou 30px dans le
 * conteneur, parfois surmonte d'un lien texte, parfois rien du tout. Le reste
 * du produit — `services/show`, `requests/show`, l'annuaire — porte une barre
 * COLLANTE en pleine largeur, fleche ronde et titre sur la meme ligne.
 *
 * Le balisage vit desormais en UN exemplaire, `x-page-topbar`. Recopier quinze
 * lignes dix fois aurait garanti qu'elles divergent — c'est precisement ce que
 * ce correctif repare.
 *
 * ## La destination, et le piege qu'elle porte
 *
 * Toutes reviennent au tableau de bord, SAUF le tableau de bord lui-meme, qui
 * revient a la homepage (demande explicite de Cyril) : une fleche qui ramene a
 * la page ou l'on est ne menerait nulle part.
 *
 * Et toutes doivent viser la forme SCOPEE sur l'Organization. La forme nue
 * ferait sortir du locataire — defaut que TASK-1697 a corrige neuf fois sur la
 * seule fiche profil.
 */
class TASK1749PageTopbarTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-barre-1749',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Les noms sont VERIFIES contre `route:list` : `--name=organization.favorites`
     * fait une correspondance PARTIELLE et m'avait rendu un faux positif —
     * la route s'appelle `organization.favorites.index`, et viser le nom court
     * levait « Route not defined » dans le banc.
     *
     * @return array<string, array{0: string, 1: string}>
     *                                                     route -> route de retour attendue
     */
    public static function lesPages(): array
    {
        return [
            'tableau de bord' => ['dashboard', 'home'],
            'mes demandes' => ['dashboard.requests', 'dashboard'],
            'mes propositions' => ['dashboard.services', 'dashboard'],
            'creer une demande' => ['requests.create', 'dashboard'],
            'creer une proposition' => ['services.create', 'dashboard'],
            'favoris' => ['favorites.index', 'dashboard'],
            'invitations' => ['invitations.index', 'dashboard'],
            'points' => ['points.index', 'dashboard'],
            'profil' => ['profile.edit', 'dashboard'],
            'bugs' => ['bug-reports.index', 'dashboard'],
        ];
    }

    #[DataProvider('lesPages')]
    public function test_la_page_porte_la_barre_du_haut(string $route, string $retour): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('organization.'.$route, ['organization' => $this->organization]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-page-topbar', $html,
            "La page « {$route} » ne porte pas la barre du haut.");
    }

    /**
     * LA garde qui compte : la destination, et qu'elle reste dans le locataire.
     *
     * L'assertion est bornee a la BALISE de la fleche. La page porte d'autres
     * liens vers le tableau de bord — le rail de navigation en premier — et une
     * assertion posee sur la PAGE passerait grace a eux sans rien mesurer.
     */
    #[DataProvider('lesPages')]
    public function test_le_retour_vise_la_forme_scopee_sur_l_organization(string $route, string $retour): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('organization.'.$route, ['organization' => $this->organization]))
            ->assertOk()
            ->getContent();

        $position = strpos($html, 'data-page-topbar-back');
        $this->assertNotFalse($position, "La fleche de retour est absente de « {$route} ».");

        $balise = substr($html, max(0, $position - 400), 400);

        $this->assertStringContainsString(
            e(route('organization.'.$retour, ['organization' => $this->organization])),
            $balise,
            "Le retour de « {$route} » ne vise pas `organization.{$retour}`.",
        );

        $this->assertStringNotContainsString('href="'.e(route($retour)).'"', $balise,
            "Le retour de « {$route} » emprunte la forme NUE et sort du locataire.");
    }

    /**
     * Le tableau de bord ne revient PAS sur lui-meme.
     *
     * Une garde a part : c'est la seule exception de la liste, et une
     * regression y serait invisible — la fleche marcherait, elle ne ferait
     * simplement rien.
     */
    public function test_le_tableau_de_bord_revient_a_la_homepage_et_non_sur_lui_meme(): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('organization.dashboard', ['organization' => $this->organization]))
            ->assertOk()
            ->getContent();

        $position = strpos($html, 'data-page-topbar-back');
        $balise = substr($html, max(0, $position - 400), 400);

        $this->assertStringContainsString(
            e(route('organization.home', ['organization' => $this->organization])),
            $balise,
        );
        $this->assertStringNotContainsString(
            e(route('organization.dashboard', ['organization' => $this->organization])).'"',
            $balise,
        );
    }

    /**
     * `x-page` sert QUATRE vues ; Cyril n'en a nomme que deux. `back` est donc
     * facultatif, et les deux autres gardent leur en-tete d'origine.
     *
     * Sans cette garde, un futur passage de `back` en obligatoire changerait
     * deux pages que personne n'a demandees.
     */
    public function test_les_vues_qui_ne_passent_PAS_back_gardent_leur_en_tete(): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('organization.loops.index', ['organization' => $this->organization]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-page-topbar', $html);
    }
}
