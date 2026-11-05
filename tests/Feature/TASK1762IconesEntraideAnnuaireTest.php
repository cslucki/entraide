<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1762 point 11.5 — les icones du rail sur les cartes de l'annuaire.
 *
 * Deux gestes dans le coin de chaque carte : voir les propositions d'aide
 * (icone « Entraide » du rail) et ecrire (icone « Messagerie » du rail). Et
 * plus aucun « 0 proposition » ni « 0 demande ».
 */
class TASK1762IconesEntraideAnnuaireTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $visiteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1762-icones', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->org);

        $this->visiteur = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function membre(array $surcharge = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'first_name' => 'Camille', 'name' => 'MOREAU',
            'approved_at' => now(),
        ], $surcharge));
    }

    /** Une proposition d'aide active, par le vrai chemin du produit. */
    private function proposition(User $membre): Service
    {
        return Service::factory()->create([
            'user_id' => $membre->id,
            'organization_id' => $this->org->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->org->id])->id,
            // `status` et non `is_active` : la colonne n'existe pas, et c'est
            // `scopeActive()` qui fait autorite sur ce que « active » veut dire.
            'status' => 'active',
        ]);
    }

    private function page(array $parametres = []): string
    {
        $url = route('organization.members.index', $this->org);

        if ($parametres !== []) {
            $url .= '?'.http_build_query($parametres);
        }

        return $this->actingAs($this->visiteur)->get($url)->assertOk()->getContent();
    }

    // ── L'icone Entraide ─────────────────────────────────────────────────────

    /** 🔴 Avec au moins une proposition, l'icone Entraide est la. */
    public function test_l_icone_entraide_apparait_avec_une_proposition(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);

        $html = $this->page();

        $this->assertStringContainsString('data-member-entraide="'.$membre->id.'"', $html);
    }

    /** 🔴 Zero proposition : aucune icone Entraide. */
    public function test_aucune_icone_entraide_sans_proposition(): void
    {
        $membre = $this->membre();

        $html = $this->page();

        $this->assertStringNotContainsString('data-member-entraide="'.$membre->id.'"', $html);
    }

    /**
     * 🔴 C'est le MEME chemin que l'entree « Entraide » du rail gauche.
     *
     * Une icone d'Entraide qui ne ressemble pas a celle du rail n'apprend rien
     * a personne. Le test compare au chemin de `config/navigation_icons.php`,
     * la source que le rail lit lui aussi.
     */
    public function test_l_icone_est_celle_du_rail(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);

        $html = $this->page();

        $this->assertStringContainsString(config('navigation_icons.exchanges'), $html);
        $this->assertStringContainsString(config('navigation_icons.messaging'), $html);
    }

    /** L'icone mene aux propositions de CETTE personne. */
    public function test_l_icone_mene_aux_propositions_de_la_personne(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);

        $html = $this->page();

        preg_match('/<a href="([^"]*)"[^>]*data-member-entraide/', $html, $m);

        $this->assertNotEmpty($m, 'L\'icone Entraide est introuvable.');

        $href = html_entity_decode($m[1]);

        $this->assertStringContainsString('tab=services', $href);
        $this->assertStringContainsString('member='.$membre->profileRouteKey(), $href);
    }

    // ── Les compteurs a zero ─────────────────────────────────────────────────

    /** 🔴 Plus de « 0 proposition » ni « 0 demande ». */
    public function test_aucun_compteur_a_zero(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);

        $html = $this->page();

        // Il a UNE proposition et ZERO demande : la proposition s'affiche,
        // la demande disparait entierement.
        $this->assertStringContainsString(trans_choice('directory.service_label', 1), $html);
        $this->assertStringNotContainsString('>0</span> '.trans_choice('directory.request_label', 0), $html);
        $this->assertStringNotContainsString('>0</span>', $html);
    }

    /** Et le separateur ne vient que si les DEUX compteurs sont la. */
    public function test_le_separateur_ne_vient_qu_avec_les_deux_compteurs(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);

        $html = $this->page();

        preg_match('/data-member-counters="'.$membre->id.'"(.*?)<\/div>/s', $html, $m);

        $this->assertNotEmpty($m, 'La rangee de compteurs est introuvable.');
        $this->assertStringNotContainsString('·', $m[1], 'Un seul compteur ne doit pas porter de separateur.');
    }

    // ── La vue « Nouveaux arrivés » ──────────────────────────────────────────

    /** 🔴 Les deux icones sont aussi dans la vue en liste. */
    public function test_les_icones_sont_aussi_dans_la_vue_nouveaux(): void
    {
        $membre = $this->membre();
        $this->proposition($membre);

        $html = $this->page(['view' => 'latest']);

        $this->assertStringContainsString('data-member-row', $html);
        $this->assertStringContainsString('data-member-entraide="'.$membre->id.'"', $html);
        $this->assertStringContainsString('data-member-write="'.$membre->id.'"', $html);
    }

    /** On ne s'ecrit pas a soi-meme, mais on voit ses propres propositions. */
    public function test_pas_d_icone_messagerie_sur_sa_propre_carte(): void
    {
        $this->proposition($this->visiteur);

        $html = $this->page();

        $this->assertStringNotContainsString('data-member-write="'.$this->visiteur->id.'"', $html);
        $this->assertStringContainsString('data-member-entraide="'.$this->visiteur->id.'"', $html);
    }
}
