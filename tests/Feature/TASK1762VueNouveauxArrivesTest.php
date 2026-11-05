<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 11.4 — la vue « Nouveaux arrivés ».
 *
 * ## Ce que ce point est, et n'est pas
 *
 * L'annuaire etait DEJA trie du plus recent au plus ancien : un bouton de TRI
 * n'aurait rien change a l'ecran. Arbitrage de Cyril : le bouton change de
 * PRESENTATION — « une présentation où l'on voit bien la photo, l'intégralité
 * du texte de présentation, et pas sous forme de card. Mais plutôt une sorte
 * de liste. »
 */
class TASK1762VueNouveauxArrivesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-nouveaux', 'is_active' => true, 'locale' => 'fr',
            'structure_enabled' => true,
        ]);
        app()->instance('current_organization', $this->org);

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function membre(array $surcharge = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'approved_at' => now(),
        ], $surcharge));
    }

    private function page(array $parametres = []): string
    {
        $url = route('organization.members.index', $this->org);

        if ($parametres !== []) {
            $url .= '?'.http_build_query($parametres);
        }

        return $this->actingAs($this->visiteur)->get($url)->assertOk()->getContent();
    }

    // ── Le bouton ────────────────────────────────────────────────────────────

    /** Le bouton est dans la barre, et il mene a la vue. */
    public function test_le_bouton_est_present_et_mene_a_la_vue(): void
    {
        $this->membre();

        $html = $this->page();

        $this->assertStringContainsString('data-directory-latest', $html);
        $this->assertStringContainsString(__('directory.latest_button'), $html);
        $this->assertMatchesRegularExpression('/<a[^>]*data-directory-latest[^>]*>/', $html);
        $this->assertStringContainsString('view=latest', $html);
    }

    /** 🔴 Le bouton CONSERVE les filtres, et retire la page. */
    public function test_le_bouton_conserve_les_filtres(): void
    {
        $this->membre(['city' => 'Marseille']);
        $this->membre(['city' => 'Menton']);

        $html = $this->page(['q' => 'camille', 'city' => 'Marseille', 'page' => 1]);

        preg_match('/<a href="([^"]*)"[^>]*\n?[^>]*data-directory-latest/s', $html, $m);
        $this->assertNotEmpty($m, 'Le bouton est introuvable.');

        $href = html_entity_decode($m[1]);

        $this->assertStringContainsString('view=latest', $href);
        $this->assertStringContainsString('q=camille', $href);
        $this->assertStringContainsString('city=Marseille', $href);
        $this->assertStringNotContainsString('page=', $href);
    }

    /** Actif, il est rempli et son lien RETIRE la vue : c'est une bascule. */
    public function test_le_bouton_actif_ramene_a_la_grille(): void
    {
        $this->membre();

        $html = $this->page(['view' => 'latest']);

        preg_match('/<a href="([^"]*)"[^>]*\n?[^>]*data-directory-latest/s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('view=latest', html_entity_decode($m[1]));
        $this->assertStringContainsString('aria-pressed="true"', $html);
    }

    // ── La presentation ──────────────────────────────────────────────────────

    /** 🔴 En vue nouveaux : des LIGNES, pas la grille de cartes. */
    public function test_la_vue_rend_des_lignes_et_non_la_grille(): void
    {
        $this->membre(['first_name' => 'Camille', 'name' => 'MOREAU']);

        $html = $this->page(['view' => 'latest']);

        $this->assertStringContainsString('data-member-row', $html);
        $this->assertStringNotContainsString('grid sm:grid-cols-2', $html);

        // Et la vue normale rend l'inverse.
        $grille = $this->page();
        $this->assertStringNotContainsString('data-member-row', $grille);
        $this->assertStringContainsString('grid sm:grid-cols-2', $grille);
    }

    /**
     * 🔴 LA demande centrale : la presentation est lue EN ENTIER.
     *
     * La carte tronque la bio a trois lignes (`line-clamp-3`). La ligne de la
     * vue « Nouveaux arrivés » doit porter le texte complet, sans la classe qui
     * tronque.
     */
    public function test_la_presentation_est_affichee_en_entier(): void
    {
        $bio = str_repeat('Une presentation longue qui depasse largement trois lignes. ', 12);
        $this->membre(['bio' => trim($bio), 'first_name' => 'Camille', 'name' => 'MOREAU']);

        $html = $this->page(['view' => 'latest']);

        $this->assertStringContainsString('data-member-bio-complete', $html);
        $this->assertStringContainsString(trim($bio), $html, 'La bio doit etre rendue en ENTIER.');
        $this->assertStringNotContainsString('line-clamp-3', $html);
    }

    /** La pastille d'arrivee donne son sens a la vue. */
    public function test_la_date_d_arrivee_est_affichee(): void
    {
        $this->membre(['created_at' => now()->subDays(3)]);

        $html = $this->page(['view' => 'latest']);

        $this->assertStringContainsString('data-member-joined', $html);
        $this->assertStringContainsString(__('directory.joined_ago', ['when' => now()->subDays(3)->diffForHumans()]), $html);
    }

    /** 🔴 Du plus recent au plus ancien — le contrat de la vue. */
    public function test_l_ordre_va_du_plus_recent_au_plus_ancien(): void
    {
        $this->membre(['first_name' => 'Ancien', 'name' => 'MEMBRE', 'created_at' => now()->subMonths(3)]);
        $this->membre(['first_name' => 'Recent', 'name' => 'MEMBRE', 'created_at' => now()->subHours(2)]);

        $html = $this->page(['view' => 'latest']);

        $this->assertLessThan(
            strpos($html, 'Ancien MEMBRE'),
            strpos($html, 'Recent MEMBRE'),
            'Le plus recent doit venir en premier.'
        );
    }

    /** 🔴 La structure reste gatee par l'Organization, dans cette vue aussi. */
    public function test_la_structure_reste_gatee_dans_cette_vue(): void
    {
        $this->membre(['structure' => 'SCOP Oxalis']);

        $this->assertStringContainsString('SCOP Oxalis', $this->page(['view' => 'latest']));

        $this->org->update(['structure_enabled' => false]);

        $this->assertStringNotContainsString('SCOP Oxalis', $this->page(['view' => 'latest']));
    }

    /** Un parametre forge se lit « vue normale », pas un etat tiers. */
    public function test_un_parametre_forge_rend_la_vue_normale(): void
    {
        $this->membre();

        $html = $this->page(['view' => 'exotique']);

        $this->assertStringNotContainsString('data-member-row', $html);
        $this->assertStringContainsString('grid sm:grid-cols-2', $html);
    }

    /** 🔴 Le chargement au defilement suit, et son URL porte la vue. */
    public function test_le_chargement_au_defilement_porte_la_vue(): void
    {
        User::factory()->count(40)->create([
            'organization_id' => $this->org->id, 'approved_at' => now(),
        ]);

        $html = $this->page(['view' => 'latest']);

        $this->assertStringContainsString('data-directory-more', $html);
        $this->assertMatchesRegularExpression('/data-next-url="[^"]*view=latest[^"]*"/', $html);
    }
}
