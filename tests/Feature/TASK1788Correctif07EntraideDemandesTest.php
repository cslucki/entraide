<?php

namespace Tests\Feature;

use App\Livewire\Explorer;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1788, correctif 07 — l'Entraide s'ouvre sur les DEMANDES.
 *
 * Demande de Cyril : « par defaut on doit afficher les demandes d'aide et non
 * les propositions d'aide ». Une plateforme d'entraide se lit d'abord par ce
 * dont les gens ont besoin.
 *
 * ## Ce que le changement a revele
 *
 * DIX tests existants s'appuyaient sur l'ancien defaut sans jamais le dire :
 * ils ouvraient l'explorateur et cherchaient des ANNONCES. Aucun n'etait faux
 * — ils mesuraient une precondition implicite. Ils la declarent desormais
 * (`->set('tab', 'services')` ou `?tab=services`), ce qui les rend lisibles et
 * insensibles a ce genre de bascule.
 */
class TASK1788Correctif07EntraideDemandesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Category $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788-07', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->categorie = Category::factory()->create(['organization_id' => $this->organization->id]);
    }

    private function garnir(): void
    {
        Service::factory()->for($this->membre, 'user')->for($this->categorie)->create([
            'organization_id' => $this->organization->id,
            'title' => 'UNE PROPOSITION D AIDE',
            'status' => 'active',
        ]);

        ServiceRequest::factory()->for($this->membre, 'user')->for($this->categorie)->create([
            'organization_id' => $this->organization->id,
            'title' => 'UNE DEMANDE D AIDE',
            'status' => 'open',
        ]);
    }

    public function test_l_explorateur_s_ouvre_sur_les_demandes(): void
    {
        $this->garnir();

        Livewire::actingAs($this->membre)
            ->test(Explorer::class)
            ->assertSet('tab', 'requests')
            ->assertSee('UNE DEMANDE D AIDE')
            ->assertDontSee('UNE PROPOSITION D AIDE');
    }

    /** L'autre onglet n'a pas disparu : il se demande, voila tout. */
    public function test_les_propositions_restent_accessibles_par_leur_onglet(): void
    {
        $this->garnir();

        Livewire::actingAs($this->membre)
            ->test(Explorer::class)
            ->call('switchTab', 'services')
            ->assertSet('tab', 'services')
            ->assertSee('UNE PROPOSITION D AIDE')
            ->assertDontSee('UNE DEMANDE D AIDE');
    }

    /**
     * L'adresse reste un point d'entree valide, dans les deux sens.
     *
     * `#[Url]` n'ecrit dans l'adresse que ce qui DIFFERE du defaut : c'est
     * desormais `?tab=services` qui parait et `?tab=requests` qui est
     * implicite. Les deux restent acceptees — aucun lien existant ne casse.
     */
    public function test_l_adresse_impose_encore_l_onglet_dans_les_deux_sens(): void
    {
        $this->garnir();

        $services = $this->actingAs($this->membre)
            ->get(route('explorer', ['tab' => 'services']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('UNE PROPOSITION D AIDE', $services,
            'Un lien `?tab=services` n\'ouvre plus les propositions.');

        $demandes = $this->actingAs($this->membre)
            ->get(route('explorer', ['tab' => 'requests']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('UNE DEMANDE D AIDE', $demandes,
            'Un lien `?tab=requests` n\'ouvre plus les demandes.');
    }

    /**
     * ⚠️ Le detail qu'il ne fallait pas casser.
     *
     * `filterByTag()` force l'onglet « propositions », et il le doit : les
     * etiquettes n'existent que sur les annonces (`tagFilter` ne vit que dans
     * la branche `services` du rendu). Cliquer une etiquette depuis les
     * demandes doit donc bien basculer — sinon le filtre s'appliquerait a une
     * liste qui ne le connait pas, et ne filtrerait rien.
     */
    public function test_cliquer_une_etiquette_bascule_bien_sur_les_propositions(): void
    {
        $this->garnir();

        Livewire::actingAs($this->membre)
            ->test(Explorer::class)
            ->assertSet('tab', 'requests')
            ->call('filterByTag', 'une-etiquette')
            ->assertSet('tab', 'services')
            ->assertSet('tagFilter', 'une-etiquette');
    }
}
