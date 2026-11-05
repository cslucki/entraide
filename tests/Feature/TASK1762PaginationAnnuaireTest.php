<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 11.2 — la pagination de l'annuaire.
 *
 * La phrase anglaise du gabarit Laravel disparait, la page passe a 36, et la
 * suite se charge au defilement — **sans** defilement infini : le bloc reste un
 * vrai lien, qui fonctionne sans JavaScript et au clavier.
 */
class TASK1762PaginationAnnuaireTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-pagination', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->org);

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function membres(int $combien): void
    {
        User::factory()->count($combien)->create([
            'organization_id' => $this->org->id,
            'approved_at' => now(),
        ]);
    }

    private function page(int $numero = 1): string
    {
        $url = route('organization.members.index', $this->org);

        if ($numero > 1) {
            $url .= '?page='.$numero;
        }

        return $this->actingAs($this->visiteur)->get($url)->assertOk()->getContent();
    }

    /** 🔴 La phrase anglaise du gabarit par defaut a disparu. */
    public function test_la_mention_anglaise_a_disparu(): void
    {
        $this->membres(50);

        $html = $this->page();

        $this->assertStringNotContainsString('Showing', $html);
        $this->assertStringNotContainsString('results', $html);
    }

    /**
     * 🔴 36 par page.
     *
     * Mesure du 05/11 : les Organizations du parc comptent 6, 9 et 35 membres.
     * A 36, aucune ne pagine — c'est tout l'objet du changement.
     */
    public function test_trente_six_membres_tiennent_sur_une_page(): void
    {
        // 35 membres + le visiteur = 36 exactement.
        $this->membres(35);

        $html = $this->page();

        $this->assertStringNotContainsString('data-directory-more', $html);
    }

    /** Au-dela, le bloc apparait. */
    public function test_au_dela_le_bloc_de_chargement_apparait(): void
    {
        $this->membres(50);

        $html = $this->page();

        $this->assertStringContainsString('data-directory-more', $html);
        $this->assertStringContainsString('data-directory-grid', $html);
    }

    /**
     * 🔴 Le bloc est un VRAI LIEN vers la page suivante.
     *
     * C'est ce qui le distingue d'un defilement infini : sans JavaScript, au
     * clavier, ou avec « ouvrir dans un onglet », il mene quelque part.
     */
    public function test_le_bloc_est_un_vrai_lien_qui_mene_a_la_page_suivante(): void
    {
        $this->membres(50);

        $html = $this->page();

        $this->assertMatchesRegularExpression('/<a href="[^"]*page=2[^"]*"[^>]*data-directory-more-link/', $html);

        // Et cette page-la repond vraiment.
        $this->actingAs($this->visiteur)
            ->get(route('organization.members.index', $this->org).'?page=2')
            ->assertOk();
    }

    /** Le libelle annonce combien il reste. */
    public function test_le_libelle_annonce_ce_qui_reste(): void
    {
        // 50 + le visiteur = 51 ; 36 sur la premiere page, 15 restants.
        $this->membres(50);

        $html = $this->page();

        $this->assertStringContainsString(trans_choice('directory.load_more', 15, ['count' => 15]), $html);
    }

    /** 🔴 Sur la derniere page, plus de bloc. */
    public function test_la_derniere_page_ne_porte_plus_de_bloc(): void
    {
        $this->membres(50);

        $this->assertStringNotContainsString('data-directory-more', $this->page(2));
    }

    /**
     * 🔴 Le lien conserve les filtres.
     *
     * Sans `withQueryString()`, la page 2 d'une recherche rendrait le parc
     * entier — un defaut qui ne se voit qu'au second ecran.
     */
    public function test_le_lien_conserve_les_filtres(): void
    {
        User::factory()->count(50)->create([
            'organization_id' => $this->org->id, 'approved_at' => now(), 'city' => 'Marseille',
        ]);

        $html = $this->actingAs($this->visiteur)
            ->get(route('organization.members.index', $this->org).'?city=Marseille')
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-next-url="[^"]*city=Marseille[^"]*"/', $html);
    }
}
