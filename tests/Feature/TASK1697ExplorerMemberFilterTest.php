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
 * TASK-1697 correctif 19 — l'explorateur sait filtrer par AUTEUR.
 *
 * Au correctif 17, les compteurs de l'annuaire menaient a une ancre de la
 * fiche profil, faute de filtre par auteur. Cyril a tranche : « je voulais que
 * le lien soit fait vers la page des propositions ou des demandes. C'est
 * beaucoup plus ergonomique, car la fiche de l'utilisateur peut etre
 * chargee. » Le filtre est donc AJOUTE.
 *
 * ## Les deux proprietes qui comptent
 *
 * 1. **Il echoue FERME.** Si la clef ne resout personne — slug inconnu, ou
 *    membre d'une AUTRE Organization — le filtre ne retombe PAS sur « tout
 *    montrer ». C'est la difference entre un filtre et une fuite.
 * 2. **`Str::isUuid()` avant de comparer a `id`.** Un slug envoye a une
 *    colonne uuid leve `22P02` en PostgreSQL, et reste INVISIBLE en SQLite
 *    (lecons T1665 et T1683). Le test `…_un_slug_ne_casse_pas_la_requete` ne
 *    prouve donc rien ici : **c'est la gate PostgreSQL qui l'arbitre.**
 */
class TASK1697ExplorerMemberFilterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $ariane;

    private User $bastien;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1697x',
            'is_active' => true,
            'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->ariane = $this->membre('Ariane', 'Delcourt', 'ariane-d');
        $this->bastien = $this->membre('Bastien', 'Marchal', 'bastien-m');
    }

    public function test_le_filtre_ne_garde_que_les_propositions_de_cette_personne(): void
    {
        $sien = $this->proposition($this->ariane, 'Atelier reliure');
        $autre = $this->proposition($this->bastien, 'Cours de guitare');

        Livewire::test(Explorer::class, ['tab' => 'services', 'member' => 'ariane-d'])
            ->assertSee($sien->title)
            ->assertDontSee($autre->title);
    }

    public function test_le_filtre_ne_garde_que_les_demandes_de_cette_personne(): void
    {
        $sienne = $this->demande($this->ariane, 'Besoin d un coup de main');
        $autre = $this->demande($this->bastien, 'Cherche un transport');

        Livewire::test(Explorer::class, ['tab' => 'requests', 'member' => 'ariane-d'])
            ->assertSee($sienne->title)
            ->assertDontSee($autre->title);
    }

    public function test_sans_filtre_tout_le_monde_est_la(): void
    {
        $sien = $this->proposition($this->ariane, 'Atelier reliure');
        $autre = $this->proposition($this->bastien, 'Cours de guitare');

        Livewire::test(Explorer::class, ['tab' => 'services'])
            ->assertSee($sien->title)
            ->assertSee($autre->title);
    }

    /** LE point : un filtre qui ne resout personne ne doit rien montrer. */
    public function test_un_slug_inconnu_echoue_FERME(): void
    {
        $this->proposition($this->ariane, 'Atelier reliure');
        $this->proposition($this->bastien, 'Cours de guitare');

        Livewire::test(Explorer::class, ['tab' => 'services', 'member' => 'personne-ici'])
            ->assertDontSee('Atelier reliure')
            ->assertDontSee('Cours de guitare')
            ->assertSee(__('explorer.member_filter_unknown'));
    }

    /** Un membre d'une AUTRE Organization ne resout pas, et ne fuit rien. */
    public function test_un_membre_d_une_autre_organization_echoue_FERME(): void
    {
        $autreOrg = Organization::factory()->create(['slug' => 'org-1697y', 'is_active' => true]);
        $etranger = User::factory()->create([
            'organization_id' => $autreOrg->id,
            'first_name' => 'Etrangere',
            'name' => 'Ailleurs',
            'slug' => 'etrangere-a',
        ]);

        $this->proposition($this->ariane, 'Atelier reliure');

        Livewire::test(Explorer::class, ['tab' => 'services', 'member' => $etranger->slug])
            ->assertDontSee('Atelier reliure')
            ->assertSee(__('explorer.member_filter_unknown'));
    }

    /**
     * La garde `Str::isUuid()`. En SQLite, comparer un slug a une colonne uuid
     * passe sans bruit : ce test est VERT ici quoi qu'il arrive. Il n'a de
     * valeur que sous la gate PostgreSQL, ou l'absence de garde leverait
     * `22P02`.
     */
    public function test_un_slug_ne_casse_pas_la_requete(): void
    {
        $this->proposition($this->ariane, 'Atelier reliure');

        Livewire::test(Explorer::class, ['tab' => 'services', 'member' => 'ariane-d'])
            ->assertOk()
            ->assertSee('Atelier reliure');
    }

    public function test_un_uuid_resout_aussi(): void
    {
        $sien = $this->proposition($this->ariane, 'Atelier reliure');
        $this->proposition($this->bastien, 'Cours de guitare');

        Livewire::test(Explorer::class, ['tab' => 'services', 'member' => (string) $this->ariane->id])
            ->assertSee($sien->title)
            ->assertDontSee('Cours de guitare');
    }

    public function test_le_bandeau_nomme_la_personne_filtree(): void
    {
        $this->proposition($this->ariane, 'Atelier reliure');

        Livewire::test(Explorer::class, ['tab' => 'services', 'member' => 'ariane-d'])
            ->assertSee('data-member-filter="'.$this->ariane->id.'"', false)
            ->assertSee(__('explorer.member_filter_label', ['name' => $this->ariane->fullName]));
    }

    public function test_retirer_le_filtre_ramene_tout_le_monde(): void
    {
        $this->proposition($this->ariane, 'Atelier reliure');
        $this->proposition($this->bastien, 'Cours de guitare');

        Livewire::test(Explorer::class, ['tab' => 'services', 'member' => 'ariane-d'])
            ->assertDontSee('Cours de guitare')
            ->call('clearMember')
            ->assertSee('Cours de guitare')
            ->assertSee('Atelier reliure');
    }

    /** Le filtre survit au changement d'onglet : on suit la personne. */
    public function test_le_filtre_survit_au_changement_d_onglet(): void
    {
        $this->proposition($this->ariane, 'Atelier reliure');
        $sienne = $this->demande($this->ariane, 'Besoin d un coup de main');
        $this->demande($this->bastien, 'Cherche un transport');

        Livewire::test(Explorer::class, ['tab' => 'services', 'member' => 'ariane-d'])
            ->call('switchTab', 'requests')
            ->assertSee($sienne->title)
            ->assertDontSee('Cherche un transport');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function membre(string $prenom, string $nom, string $slug): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'first_name' => $prenom,
            'name' => $nom,
            'slug' => $slug,
        ]);
    }

    private function categorie(): string
    {
        return Category::factory()->create(['organization_id' => $this->organization->id])->id;
    }

    private function proposition(User $qui, string $titre): Service
    {
        return Service::factory()->create([
            'user_id' => $qui->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie(),
            'status' => 'active',
            'title' => $titre,
        ]);
    }

    private function demande(User $qui, string $titre): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'user_id' => $qui->id,
            'organization_id' => $this->organization->id,
            'category_id' => $this->categorie(),
            'status' => 'open',
            'title' => $titre,
        ]);
    }
}
