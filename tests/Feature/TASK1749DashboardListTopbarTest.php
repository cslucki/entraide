<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1749 (demande Cyril, 04/11) — « Mes demandes d'aide » et « Mes
 * propositions d'aide » portent la barre du haut STANDARD.
 *
 * ## Ce que cela corrige
 *
 * Ces deux pages portaient un en-tete a elles : un lien texte
 * « ← Retour au tableau de bord » surmontant un titre de 24px, A L'INTERIEUR du
 * conteneur. Le reste du produit — `services/show`, `requests/show`,
 * l'annuaire — porte une barre COLLANTE en pleine largeur, fleche ronde et
 * titre sur la meme ligne.
 *
 * Deux grammaires pour le meme geste, et c'est celle de ces deux pages qui
 * etait l'exception.
 *
 * ## Ce que ce test ne mesure pas, et pourquoi
 *
 * Ni le style, ni la position a l'ecran : `assertSee()` ne mesure pas la
 * visibilite (regle du depot). Il mesure la STRUCTURE — le marqueur de la
 * barre, la destination de la fleche, la presence du titre et du geste — qui
 * est ce qui peut regresser silencieusement.
 */
class TASK1749DashboardListTopbarTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-topbar-1749',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function lesDeuxPages(): array
    {
        return [
            'mes demandes' => ['dashboard.requests', 'requests', 'dashboard.my_requests_page_title'],
            'mes propositions' => ['dashboard.services', 'services', 'dashboard.my_services_page_title'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lesDeuxPages')]
    public function test_la_barre_standard_remplace_l_ancien_en_tete(string $route, string $marqueur, string $titre): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('organization.'.$route, ['organization' => $this->organization]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-dashboard-topbar="'.$marqueur.'"', $html);
        // `e()` et non la chaine brute : les deux libelles portent une
        // APOSTROPHE (« Mes demandes d'aide »), rendue `&#039;` dans la page.
        // La premiere version comparait au texte nu et rougissait sur les deux
        // pages — le titre etait pourtant bien la.
        $this->assertStringContainsString(e(__($titre)), $html);

        // L'ancien en-tete texte ne doit pas cohabiter avec la barre : deux
        // retours vers le meme endroit, dont un seul dans la grammaire du
        // produit.
        $this->assertStringNotContainsString('&larr; '.__('dashboard.back_to_dashboard'), $html);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lesDeuxPages')]
    public function test_la_fleche_revient_au_tableau_de_bord_DE_L_ORGANIZATION(string $route, string $marqueur, string $titre): void
    {
        $html = $this->actingAs($this->membre)
            ->get(route('organization.'.$route, ['organization' => $this->organization]))
            ->assertOk()
            ->getContent();

        // Borne a la balise de la fleche : la page porte d'autres liens vers le
        // tableau de bord (rail de navigation), et une assertion posee sur la
        // PAGE passerait grace a eux.
        // Le marqueur vient de `x-page-topbar`, le composant partage : la
        // barre a quitte ces deux gabarits pour vivre en UN exemplaire, et
        // c'est lui qui porte desormais la fleche.
        $debut = strpos($html, 'data-page-topbar-back');
        $this->assertNotFalse($debut, 'La fleche de retour est absente de la barre.');

        $balise = substr($html, max(0, $debut - 400), 400);

        $this->assertStringContainsString(
            e(route('organization.dashboard', ['organization' => $this->organization])),
            $balise,
        );

        // Et jamais la forme NUE : elle ferait sortir du locataire, defaut que
        // TASK-1697 a corrige neuf fois sur la fiche profil.
        $this->assertStringNotContainsString('href="'.e(route('dashboard')).'"', $balise);
    }
}
