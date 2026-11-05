<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Entraide\CarteEntraide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1755 — ce qu'un echange RACONTE, selon qui regarde.
 *
 * Ce fichier mesure la DECISION (phrase, etape, sens, action principale), pas
 * le rendu : le pixel appartient a la recette navigateur.
 *
 * La garde qui compte le plus est celle du SENS : il ne doit jamais s'inverser,
 * meme quand le PROPOSANT s'inverse — et le proposant s'inverse pour de vrai
 * entre une proposition et une demande (T1693 bug S).
 */
class TASK1755CarteEntraideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aidant;

    private User $aide;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1755', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->aidant = User::factory()->create([
            'organization_id' => $this->organization->id, 'first_name' => 'Ottoline', 'name' => 'Kaminski',
        ]);
        $this->aide = User::factory()->create([
            'organization_id' => $this->organization->id, 'first_name' => 'Brunhilde', 'name' => 'Vasquez',
        ]);
    }

    private function echange(string $statut, ?string $serviceId = null, ?string $requestId = null): Transaction
    {
        return Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->aide->id,
            'seller_id' => $this->aidant->id,
            'service_id' => $serviceId,
            'request_id' => $requestId,
            'status' => $statut,
            'points_proposed' => 30,
            'points_agreed' => $statut === 'pending' ? null : 30,
        ]);
    }

    // ── Le sens de l'aide ────────────────────────────────────────────────────

    /**
     * LA garde centrale : le sens de l'aide ne s'inverse JAMAIS.
     *
     * Le proposant, lui, s'inverse — cote proposition c'est celui qui recoit
     * l'aide, cote demande c'est celui qui l'apporte (T1693 bug S). Construire
     * les phrases sur `proposerId()` au lieu de `estLAidant()` reintroduirait
     * ce bug, et il serait INVISIBLE : les deux coincident cote proposition.
     * Ce test couvre donc les DEUX origines.
     */
    public function test_le_sens_de_l_aide_ne_s_inverse_pas_selon_l_origine(): void
    {
        $categorie = Category::factory()->create(['organization_id' => $this->organization->id]);

        $service = Service::factory()->create([
            'user_id' => $this->aidant->id, 'organization_id' => $this->organization->id,
            'category_id' => $categorie->id, 'status' => 'active', 'points_cost' => 30,
        ]);
        $demande = ServiceRequest::factory()->create([
            'user_id' => $this->aide->id, 'organization_id' => $this->organization->id,
            'category_id' => $categorie->id, 'status' => 'open', 'budget_min' => 30,
        ]);

        foreach ([
            'depuis une proposition' => $this->echange('accepted', serviceId: $service->id),
            'depuis une demande' => $this->echange('accepted', requestId: $demande->id),
        ] as $origine => $echange) {
            $this->assertStringContainsString(
                'Vous aidez', CarteEntraide::sens($echange, $this->aidant)['texte'],
                "L'aidant doit lire « Vous aidez » ({$origine}).",
            );
            $this->assertStringContainsString(
                'vous demande', CarteEntraide::sens($echange, $this->aide)['texte'],
                "Celui qui recoit l'aide doit lire « vous demande » ({$origine}).",
            );
        }
    }

    /** Une discussion directe n'est pas une entraide : ni sens, ni phrase, ni action. */
    public function test_une_discussion_directe_ne_porte_ni_phrase_ni_action(): void
    {
        $discussion = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->aide->id, 'seller_id' => $this->aidant->id,
            'service_id' => null, 'request_id' => null, 'points_proposed' => 0,
        ]);

        $this->assertSame('💬', CarteEntraide::sens($discussion, $this->aide)['emoji']);
        $this->assertNull(CarteEntraide::phrase($discussion, $this->aide));
        $this->assertNull(CarteEntraide::actions($discussion, $this->aide)['principale']);
    }

    // ── L'etapier ────────────────────────────────────────────────────────────

    /**
     * Les quatre etapes avancent, et un echange SORTI du parcours n'en allume
     * aucune.
     *
     * Allumer « Proposition » sur un echange decline ferait croire qu'il attend
     * encore quelque chose.
     */
    public function test_l_etapier_avance_et_s_eteint_quand_l_echange_sort_du_parcours(): void
    {
        $this->assertSame(1, CarteEntraide::etape($this->echange('pending')));
        $this->assertSame(2, CarteEntraide::etape($this->echange('accepted')));
        $this->assertSame(3, CarteEntraide::etape($this->echange('buyer_done')));
        $this->assertSame(4, CarteEntraide::etape($this->echange('completed')));

        $this->assertSame(0, CarteEntraide::etape($this->echange('refused')));
        $this->assertSame(0, CarteEntraide::etape($this->echange('cancelled')));
    }

    // ── UNE action principale ────────────────────────────────────────────────

    /**
     * A chaque instant, UNE seule action principale, et pour UNE seule partie.
     *
     * Le bandeau alignait quatre boutons de meme poids : rien ne disait lequel
     * on attendait, et les deux parties voyaient la meme chose.
     */
    public function test_une_seule_partie_porte_l_action_principale(): void
    {
        $cas = [
            // statut      => [qui agit,        qui n'agit pas]
            'pending' => [$this->aidant, $this->aide],      // l'auteur de l'annonce decide
            'accepted' => [$this->aide, $this->aidant],     // celui qui recoit declare
            'buyer_done' => [$this->aidant, $this->aide],   // celui qui a aide confirme
        ];

        foreach ($cas as $statut => [$agit, $attend]) {
            $echange = $this->echange($statut);

            $this->assertNotNull(CarteEntraide::actions($echange, $agit)['principale'],
                "Au statut [{$statut}], une action principale est attendue.");
            $this->assertNull(CarteEntraide::actions($echange, $attend)['principale'],
                "Au statut [{$statut}], l'autre partie ne doit PAS porter l'action principale.");
        }
    }

    /** Un echange termine ne propose plus rien — ni principale, ni menu. */
    public function test_un_echange_termine_ne_propose_plus_d_action(): void
    {
        foreach (['completed', 'refused', 'cancelled'] as $statut) {
            $actions = CarteEntraide::actions($this->echange($statut), $this->aidant);

            $this->assertNull($actions['principale'], "statut [{$statut}]");
            $this->assertSame([], $actions['menu'], "statut [{$statut}]");
        }
    }

    /**
     * Un TIERS ne voit aucune action, meme s'il atteint la carte.
     *
     * La garde d'autorisation vit dans les policies ; celle-ci evite d'AFFICHER
     * un bouton qui serait de toute facon refuse — un bouton mort est une
     * promesse qu'on ne tient pas.
     */
    public function test_un_tiers_ne_voit_aucune_action(): void
    {
        $tiers = User::factory()->create(['organization_id' => $this->organization->id]);

        $actions = CarteEntraide::actions($this->echange('pending'), $tiers);

        $this->assertNull($actions['principale']);
        $this->assertNull($actions['secondaire']);
        $this->assertSame([], $actions['menu']);
    }

    /**
     * « Retirer ma proposition » pour le proposant, « Annuler l'échange » pour
     * le decideur — et jamais l'inverse.
     *
     * Les confondre sous « Annuler » cachait ce qui se passe vraiment (§3.3).
     */
    public function test_le_menu_distingue_retirer_sa_proposition_et_annuler_l_echange(): void
    {
        $echange = $this->echange('pending');

        $marqueurs = fn (User $u) => array_column(CarteEntraide::actions($echange, $u)['menu'], 'marqueur');

        // L'echange naît d'une proposition : le decideur est l'AIDANT.
        $this->assertContains('cancel_exchange', $marqueurs($this->aidant));
        $this->assertContains('cancel_withdraw', $marqueurs($this->aide));
    }

    /**
     * La carte est REELLEMENT rendue dans le fil, avec ses quatre parties.
     *
     * Les tests precedents mesurent la DECISION ; celui-ci verifie qu'elle
     * arrive a l'ecran. Il ne mesure pas la visibilite — `assertSee()` en est
     * incapable — seulement que les marqueurs sortent, ce que la recette
     * navigateur completera.
     */
    public function test_la_carte_est_rendue_dans_le_fil(): void
    {
        $echange = $this->echange('pending');

        $html = $this->actingAs($this->aidant)
            ->get(route('messages.show', $echange))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-entraide-carte', $html);
        $this->assertStringContainsString('data-entraide-phrase', $html);
        $this->assertStringContainsString('data-entraide-etapier="1"', $html);
        // L'auteur de l'annonce decide : c'est lui qui porte « Accepter ».
        $this->assertStringContainsString('data-entraide-action="accept"', $html);
        $this->assertStringContainsString('data-entraide-action="cancel_exchange"', $html);

        // Et celui qui recoit l'aide ne porte PAS l'action principale.
        $autre = $this->actingAs($this->aide)
            ->get(route('messages.show', $echange))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-entraide-action="accept"', $autre);
        $this->assertStringContainsString('data-entraide-action="cancel_withdraw"', $autre);
    }

    /** La phrase est TRADUITE — jamais figee en francais. */
    public function test_la_phrase_est_traduite(): void
    {
        $echange = $this->echange('buyer_done');

        $fr = CarteEntraide::phrase($echange, $this->aidant);
        $this->app->setLocale('en');
        $en = CarteEntraide::phrase($echange, $this->aidant);

        $this->assertNotSame($fr, $en);
        $this->assertStringContainsString('Confirm', $en);
    }
}
