<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1761 points 3, 4 et 5 — la surface `/admin/loops`.
 *
 * - **3** : la MEME carte que sur le tableau de bord, et elle suit le filtre
 *   Organization de la page.
 * - **4** : « N cards liees » cede la place au nombre de demandes en attente.
 * - **5** : le PROPRIETAIRE — prenom ET nom — avec « se connecter sous ».
 */
class TASK1761AdminLoopsSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1761s', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1761s', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->orgA);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
    }

    /** @return array{0: Loop, 1: User} la Boucle et son proprietaire */
    private function boucleAvecProprietaire(Organization $org, array $attributsProprietaire = []): array
    {
        $proprietaire = User::factory()->create(array_merge([
            'organization_id' => $org->id,
            'first_name' => 'Jeanne',
            'name' => 'MARTIN',
        ], $attributsProprietaire));

        $createur = User::factory()->create([
            'organization_id' => $org->id,
            'first_name' => 'Paul',
            'name' => 'DURAND',
        ]);

        $boucle = Loop::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $createur->id,
        ]);

        LoopMember::create([
            'loop_id' => $boucle->id, 'user_id' => $proprietaire->id,
            'role' => 'owner', 'status' => 'active',
            'joined_at' => now(), 'organization_id' => $org->id,
        ]);

        return [$boucle, $proprietaire];
    }

    private function demandeEnAttente(Loop $boucle): LoopJoinRequest
    {
        return LoopJoinRequest::create([
            'organization_id' => $boucle->organization_id,
            'loop_id' => $boucle->id,
            'user_id' => User::factory()->create(['organization_id' => $boucle->organization_id])->id,
            'status' => LoopJoinRequest::STATUS_PENDING,
        ]);
    }

    // ── Point 3 ──────────────────────────────────────────────────────────────

    /** La carte est la, et c'est le MEME composant que sur le tableau de bord. */
    public function test_la_carte_est_sur_admin_loops(): void
    {
        [$boucle] = $this->boucleAvecProprietaire($this->orgA);
        $this->demandeEnAttente($boucle);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()->getContent();

        $this->assertStringContainsString('data-marqueur="demandes-adhesion"', $html);
        $this->assertSame(1, substr_count($html, 'data-marqueur="demande-adhesion"'));
    }

    /**
     * 🔴 Et elle SUIT le filtre Organization de la page.
     *
     * `/admin/loops?organization_id=X` ne montre que les Boucles de X. Une carte
     * qui resterait globale ferait apparaitre, sous une page filtree, des
     * demandes portant sur des Boucles absentes du tableau.
     */
    public function test_la_carte_suit_le_filtre_organization(): void
    {
        [$boucleA] = $this->boucleAvecProprietaire($this->orgA);
        [$boucleB] = $this->boucleAvecProprietaire($this->orgB);
        $this->demandeEnAttente($boucleA);
        $this->demandeEnAttente($boucleB);

        // Sans filtre : les deux Organizations.
        $global = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()->getContent();
        $this->assertSame(2, substr_count($global, 'data-marqueur="demande-adhesion"'));

        // Filtree sur A : la sienne seulement.
        $filtree = $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => $this->orgA->id]))
            ->assertOk()->getContent();

        $this->assertSame(1, substr_count($filtree, 'data-marqueur="demande-adhesion"'));
        $this->assertStringContainsString($boucleA->name, $filtree);
    }

    // ── Point 4 ──────────────────────────────────────────────────────────────

    /** Le nombre de demandes remplace « N cards liees ». */
    public function test_le_nombre_de_demandes_remplace_les_cards_liees(): void
    {
        [$boucle] = $this->boucleAvecProprietaire($this->orgA);
        $this->demandeEnAttente($boucle);
        $this->demandeEnAttente($boucle);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()->getContent();

        // Les deux attributs sont sur DEUX lignes dans la vue : les assortir
        // colles mesurerait la mise en page du HTML, pas la presence du marqueur.
        $this->assertStringContainsString('data-marqueur="boucle-demandes-en-attente"', $html);
        $this->assertStringContainsString('data-boucle="'.$boucle->id.'"', $html);
        $this->assertStringContainsString(trans_choice('admin.join_requests_pending_count', 2, ['count' => 2]), $html);

        // La mention remplacee ne doit plus etre la.
        $this->assertStringNotContainsString(mb_strtolower(__('loops.cards_linked')), mb_strtolower($html));
    }

    /**
     * Et le compte est celui des demandes EN ATTENTE, pas de toutes.
     *
     * Une demande deja tranchee gonflerait un chiffre qui sert a savoir ce
     * qu'il reste a faire.
     */
    public function test_le_compte_ignore_les_demandes_deja_tranchees(): void
    {
        [$boucle] = $this->boucleAvecProprietaire($this->orgA);
        $this->demandeEnAttente($boucle);
        $this->demandeEnAttente($boucle)->update(['status' => LoopJoinRequest::STATUS_ACCEPTED]);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()->getContent();

        $this->assertStringContainsString(trans_choice('admin.join_requests_pending_count', 1, ['count' => 1]), $html);
        $this->assertStringNotContainsString(trans_choice('admin.join_requests_pending_count', 2, ['count' => 2]), $html);
    }

    /** Sans demande, aucun « 0 » : le chiffre ne s'affiche que s'il y a a faire. */
    public function test_sans_demande_aucun_compteur(): void
    {
        $this->boucleAvecProprietaire($this->orgA);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-marqueur="boucle-demandes-en-attente"', $html);
    }

    // ── Point 5 ──────────────────────────────────────────────────────────────

    /** 🔴 Le PROPRIETAIRE, prenom ET nom — et non le createur. */
    public function test_la_ligne_montre_le_proprietaire_prenom_et_nom(): void
    {
        [$boucle, $proprietaire] = $this->boucleAvecProprietaire($this->orgA);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()->getContent();

        $this->assertStringContainsString('data-marqueur="boucle-proprietaires" data-boucle="'.$boucle->id.'"', $html);
        $this->assertStringContainsString($proprietaire->full_name, $html);
        $this->assertSame('Jeanne MARTIN', $proprietaire->full_name);

        // Le createur, lui, n'est plus montre : il ne l'est que faute de proprietaire.
        $this->assertStringNotContainsString('Paul DURAND', $html);
    }

    /**
     * Une Boucle SANS proprietaire montre son createur, nomme comme tel.
     *
     * Le cas existe (T1679). Ne rien afficher laisserait croire a un defaut
     * d'affichage ; afficher le createur sans le dire le ferait passer pour le
     * proprietaire.
     */
    public function test_une_boucle_sans_proprietaire_montre_son_createur_nomme_comme_tel(): void
    {
        $createur = User::factory()->create([
            'organization_id' => $this->orgA->id, 'first_name' => 'Paul', 'name' => 'DURAND',
        ]);

        Loop::factory()->create(['organization_id' => $this->orgA->id, 'created_by' => $createur->id]);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()->getContent();

        $this->assertStringContainsString(__('admin.loop_creator_label').' Paul DURAND', $html);
        $this->assertStringNotContainsString('data-marqueur="boucle-proprietaires"', $html);
    }

    /** « Se connecter sous » pointe sur la route existante, en POST. */
    public function test_se_connecter_sous_pointe_sur_la_route_existante(): void
    {
        [, $proprietaire] = $this->boucleAvecProprietaire($this->orgA);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()->getContent();

        $this->assertStringContainsString('data-marqueur="se-connecter-sous"', $html);
        $this->assertStringContainsString(route('admin.users.login-as', $proprietaire), $html);
    }

    /**
     * 🔴 Pas de bouton quand `loginAsUser()` refusera de toute facon.
     *
     * Les deux refus sont connus d'avance — banni, ou autre administrateur.
     * Offrir le bouton serait une promesse que la route ne tiendra pas.
     */
    public function test_aucun_bouton_pour_un_proprietaire_administrateur_ou_banni(): void
    {
        $this->boucleAvecProprietaire($this->orgA, ['is_admin' => true, 'first_name' => 'Ada', 'name' => 'ADMIN']);
        $this->boucleAvecProprietaire($this->orgB, ['banned_at' => now(), 'first_name' => 'Bob', 'name' => 'BANNI']);

        $html = $this->actingAs($this->superAdmin)->get(route('admin.loops'))->assertOk()->getContent();

        // Les deux sont NOMMES — on doit savoir qui tient la Boucle.
        $this->assertStringContainsString('Ada ADMIN', $html);
        $this->assertStringContainsString('Bob BANNI', $html);

        // Mais aucun bouton n'est propose.
        $this->assertStringNotContainsString('data-marqueur="se-connecter-sous"', $html);
    }
}
