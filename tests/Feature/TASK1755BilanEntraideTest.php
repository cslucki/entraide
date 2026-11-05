<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entraide\BilanEntraide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1755 (§9.6) — le profil comme BILAN D'ENTRAIDE.
 *
 * Le profil affichait un solde : « 160 pts ». C'est un chiffre de portefeuille,
 * il ne dit rien de ce que la personne a fait pour les autres.
 *
 * La garde qui compte le plus est celle du TENANT — et elle n'est pas
 * theorique : la premiere version de `BilanEntraide` utilisait
 * `withoutGlobalScopes()` sans borner l'Organization, et faisait apparaitre sur
 * le profil un merci ecrit dans un AUTRE tenant.
 */
class TASK1755BilanEntraideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $aidant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-bilan', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-bilan', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->orgA);

        $this->aidant = User::factory()->create(['organization_id' => $this->orgA->id]);
    }

    private function entraideTerminee(Organization $org, User $aidant, User $aide, int $points = 30): Transaction
    {
        return Transaction::factory()->create([
            'organization_id' => $org->id,
            'seller_id' => $aidant->id,
            'buyer_id' => $aide->id,
            // Une entraide passe par une VRAIE annonce. Un uuid invente
            // violerait la cle etrangere — et surtout il ne mesurerait rien :
            // c'est l'existence de l'annonce qui distingue une entraide d'une
            // discussion directe.
            'service_id' => $this->annonce($org, $aidant)->id,
            'status' => 'completed',
            'points_proposed' => $points,
            'points_agreed' => $points,
        ]);
    }

    private function annonce(Organization $org, User $auteur): Service
    {
        return Service::factory()->create([
            'user_id' => $auteur->id,
            'organization_id' => $org->id,
            'category_id' => Category::factory()->create(['organization_id' => $org->id])->id,
            'status' => 'active',
            'points_cost' => 30,
        ]);
    }

    /** Les trois nombres disent ce qu'ils annoncent. */
    public function test_le_bilan_compte_les_personnes_les_fois_et_le_temps(): void
    {
        $a = User::factory()->create(['organization_id' => $this->orgA->id]);
        $b = User::factory()->create(['organization_id' => $this->orgA->id]);

        // Aide DEUX fois la meme personne : une seule personne aidee.
        $this->entraideTerminee($this->orgA, $this->aidant, $a, 30);
        $this->entraideTerminee($this->orgA, $this->aidant, $a, 60);
        $this->entraideTerminee($this->orgA, $this->aidant, $b, 30);

        // Et se fait aider une fois.
        $this->entraideTerminee($this->orgA, $b, $this->aidant, 45);

        $bilan = BilanEntraide::pour($this->aidant, $this->orgA);

        $this->assertSame(2, $bilan['personnes_aidees'], 'Deux echanges avec la MEME personne ne font qu\'une personne aidee.');
        $this->assertSame(1, $bilan['fois_aide']);
        $this->assertSame(120, $bilan['temps_offert'], 'Le temps offert ne compte que ce qu\'on a DONNE.');
        $this->assertSame('2 h', BilanEntraide::tempsOffert($bilan['temps_offert']));
    }

    /**
     * 🔴 LA GARDE DE TENANT — regression reellement introduite puis corrigee.
     *
     * Un merci ecrit dans l'Organization B ne doit NI compter NI s'afficher
     * quand on lit le profil depuis l'Organization A. La frontiere est portee
     * par la TRANSACTION, pas par l'avis : un avis n'a pas d'Organization
     * propre, il herite de celle de l'echange qu'il clot.
     */
    public function test_un_merci_d_un_autre_tenant_ne_compte_ni_ne_s_affiche(): void
    {
        $aideA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $aideB = User::factory()->create(['organization_id' => $this->orgB->id]);

        Review::factory()->forTransaction($this->entraideTerminee($this->orgA, $this->aidant, $aideA))->create([
            'reviewed_id' => $this->aidant->id, 'reviewer_id' => $aideA->id,
            'comment' => 'MERCI_VISIBLE_EN_A', 'rating' => 5,
        ]);
        Review::factory()->forTransaction($this->entraideTerminee($this->orgB, $this->aidant, $aideB))->create([
            'reviewed_id' => $this->aidant->id, 'reviewer_id' => $aideB->id,
            'comment' => 'MERCI_CACHE_DE_B', 'rating' => 5,
        ]);

        $bilan = BilanEntraide::pour($this->aidant, $this->orgA);
        $this->assertSame(1, $bilan['mercis'], 'Un merci d\'un autre tenant a ete compte.');

        $mots = BilanEntraide::mercis($this->aidant, $this->orgA)->pluck('comment');
        $this->assertContains('MERCI_VISIBLE_EN_A', $mots);
        $this->assertNotContains('MERCI_CACHE_DE_B', $mots, 'Fuite inter-tenant sur le profil.');

        // Et les echanges non plus ne traversent pas la frontiere.
        $this->assertSame(1, $bilan['personnes_aidees']);
    }

    /**
     * Une DISCUSSION directe n'est pas une entraide.
     *
     * « Ecrire a quelqu'un » cree une Transaction a 0 point. La compter
     * gonflerait le bilan de tout le monde avec de simples bonjours.
     */
    public function test_une_discussion_directe_n_entre_pas_au_bilan(): void
    {
        $autre = User::factory()->create(['organization_id' => $this->orgA->id]);

        Transaction::factory()->create([
            'organization_id' => $this->orgA->id,
            'seller_id' => $this->aidant->id, 'buyer_id' => $autre->id,
            'service_id' => null, 'request_id' => null,
            'status' => 'completed', 'points_proposed' => 0, 'points_agreed' => 0,
        ]);

        $this->assertSame(0, BilanEntraide::pour($this->aidant, $this->orgA)['personnes_aidees']);
    }

    /**
     * Un coup de main OFFERT compte, lui — c'est toute la distinction du §9.3.
     *
     * Zero point, mais une annonce : une entraide reelle. La filtrer sur les
     * points au lieu de l'annonce l'aurait effacee du bilan.
     */
    public function test_un_coup_de_main_offert_compte_comme_une_entraide(): void
    {
        $autre = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->entraideTerminee($this->orgA, $this->aidant, $autre, points: 0);

        $bilan = BilanEntraide::pour($this->aidant, $this->orgA);

        $this->assertSame(1, $bilan['personnes_aidees']);
        $this->assertSame(0, $bilan['temps_offert']);
    }

    /**
     * Un bilan VIDE ne s'affiche pas.
     *
     * « 0 personne · 0 fois · 0 min » est un reproche, pas un bilan.
     */
    public function test_un_bilan_vide_ne_s_affiche_pas_et_un_bilan_rempli_oui(): void
    {
        $neuf = User::factory()->create(['organization_id' => $this->orgA->id]);
        $lecteur = User::factory()->create(['organization_id' => $this->orgA->id]);

        $this->assertTrue(BilanEntraide::estVide(BilanEntraide::pour($neuf, $this->orgA)));

        $vide = $this->actingAs($lecteur)->get(route('profile.show', $neuf))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-entraide-bilan', $vide);

        $this->entraideTerminee($this->orgA, $neuf, $lecteur);

        $rempli = $this->actingAs($lecteur)->get(route('profile.show', $neuf))->assertOk()->getContent();
        $this->assertStringContainsString('data-entraide-bilan', $rempli);
        $this->assertStringContainsString('personne aidée', $rempli);
    }
}
