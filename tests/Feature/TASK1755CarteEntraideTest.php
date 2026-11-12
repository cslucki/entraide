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

        // `locale` EXPLICITE. `SetLocale` resout session → compte → ORGANIZATION
        // → navigateur, et la fabrique d'Organization ne garantit pas le
        // francais : sans cette ligne, la page sortait en anglais et les
        // assertions sur les libelles francais echouaient pour une raison qui
        // n'avait rien a voir avec ce qu'elles mesurent.
        $this->organization = Organization::factory()->create([
            'slug' => 'org-1755', 'is_active' => true, 'locale' => 'fr',
        ]);
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
            // TASK-1808 — ce test attendait « vous demande » POUR L'AIDE, et
            // consacrait donc le defaut signale par Cyril : la vue de l'aide
            // annoncait que l'autre lui demandait de l'aide. Apres l'accord,
            // l'aide doit lire que l'autre L'AIDE.
            $this->assertStringContainsString(
                'vous aide', CarteEntraide::sens($echange, $this->aide)['texte'],
                "Celui qui recoit l'aide doit lire « vous aide » ({$origine}).",
            );
            $this->assertStringNotContainsString(
                'vous demande de', CarteEntraide::sens($echange, $this->aide)['texte'],
                "L'aide ne doit JAMAIS lire qu'on lui demande de l'aide ({$origine}).",
            );
        }
    }

    /**
     * TASK-1808 (observation de Cyril, 11/11) — LES QUATRE CASES DU TABLEAU.
     *
     * Le defaut ne venait pas d'une phrase mal ecrite mais d'un branchement
     * incomplet : `sens()` ne regardait que le ROLE. Or le sens de l'aide se
     * dit en croisant le role ET l'etape, et deux des quatre cas n'avaient
     * aucun libelle. Ce test les epingle tous les quatre, pour qu'aucun ne
     * puisse reprendre la phrase d'un autre.
     */
    public function test_le_sens_croise_le_role_et_l_etape(): void
    {
        $attendus = [
            // etape    => [phrase de l'aidant,    phrase de l'aide]
            'pending' => ['vous demande de l’aide', 'Vous demandez de l’aide'],
            'accepted' => ['Vous aidez', 'vous aide'],
        ];

        foreach ($attendus as $statut => [$cotéAidant, $cotéAide]) {
            $echange = $this->echange($statut);

            $this->assertStringContainsString(
                $cotéAidant, CarteEntraide::sens($echange, $this->aidant)['texte'],
                "Aidant en « {$statut} ».",
            );
            $this->assertStringContainsString(
                $cotéAide, CarteEntraide::sens($echange, $this->aide)['texte'],
                "Aide en « {$statut} ».",
            );
        }

        // Les deux lectures ne doivent jamais se confondre : c'est le defaut
        // d'origine, ou la meme phrase servait les deux roles.
        $accepte = $this->echange('accepted');
        $this->assertNotSame(
            CarteEntraide::sens($accepte, $this->aidant)['texte'],
            CarteEntraide::sens($accepte, $this->aide)['texte'],
            'Les deux roles ne peuvent pas lire la meme phrase.',
        );
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
     * Les trois etapes avancent, et une entraide SORTIE du parcours n'en
     * allume aucune.
     *
     * Allumer « Proposition » sur un echange decline ferait croire qu'il attend
     * encore quelque chose.
     *
     * TASK-1806 (arbitrage du 11/11) — TROIS etapes visibles : demande,
     * accord, confirmation. `completed` allume la derniere.
     */
    public function test_l_etapier_avance_et_s_eteint_quand_l_echange_sort_du_parcours(): void
    {
        $this->assertSame(1, CarteEntraide::etape($this->echange('pending')));
        $this->assertSame(2, CarteEntraide::etape($this->echange('accepted')));
        // `buyer_done` est un statut HERITE : les points n'y sont pas encore
        // transferes, il reste donc a l'etape 2 comme `accepted`.
        $this->assertSame(2, CarteEntraide::etape($this->echange('buyer_done')));
        $this->assertSame(3, CarteEntraide::etape($this->echange('completed')));

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
        // Au statut `pending`, c'est le DECIDEUR qui porte l'action.
        $echange = $this->echange('pending');
        $this->assertNotNull(CarteEntraide::actions($echange, $this->aidant)['principale']);
        $this->assertNull(CarteEntraide::actions($echange, $this->aide)['principale']);

        // TASK-1806 — une fois l'accord pris, PLUS AUCUNE action principale
        // en en-tete, pour personne : la confirmation se prend en bas du fil,
        // avec l'evaluation. Un bouton ici serait un second chemin, qui
        // cloturerait sans jamais proposer de dire merci.
        foreach (['accepted', 'buyer_done'] as $statut) {
            $echange = $this->echange($statut);

            foreach ([$this->aide, $this->aidant] as $lecteur) {
                $this->assertNull(CarteEntraide::actions($echange, $lecteur)['principale'],
                    "Au statut [{$statut}], aucune action principale n'est attendue en en-tete.");
                $this->assertNull(CarteEntraide::actions($echange, $lecteur)['secondaire'],
                    "Au statut [{$statut}], aucune action secondaire n'est attendue en en-tete.");
            }
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

    // ── Les filtres de la liste (§5.2) ──────────────────────────────────────

    /** Chaque pastille ne ramene QUE ce qu'elle annonce. */
    public function test_les_filtres_ne_ramenent_que_ce_qu_ils_annoncent(): void
    {
        $enCours = $this->echange('accepted');
        $termine = $this->echange('completed');
        $decline = $this->echange('refused');
        $discussion = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->aide->id, 'seller_id' => $this->aidant->id,
            'service_id' => null, 'request_id' => null, 'points_proposed' => 0,
        ]);

        $voit = function (?string $filtre, Transaction $t) {
            $html = $this->actingAs($this->aidant)
                ->get(route('messages.index', $filtre ? ['filtre' => $filtre] : []))
                ->assertOk()->getContent();

            return str_contains($html, 'data-conversation-ligne="'.$t->id.'"');
        };

        $this->assertTrue($voit('en_cours', $enCours));
        $this->assertFalse($voit('en_cours', $termine));
        $this->assertFalse($voit('en_cours', $discussion),
            'Une discussion n\'a pas d\'etat : elle n\'est jamais « en cours ».');

        $this->assertTrue($voit('termines', $termine));
        // Un echange DECLINE est sorti du parcours : il appartient aux termines.
        $this->assertTrue($voit('termines', $decline));
        $this->assertFalse($voit('termines', $enCours));

        $this->assertTrue($voit('discussions', $discussion));
        $this->assertFalse($voit('discussions', $enCours));

        // Et « tous » ramene tout le monde.
        foreach ([$enCours, $termine, $decline, $discussion] as $t) {
            $this->assertTrue($voit(null, $t));
        }
    }

    /**
     * La conversation OUVERTE n'est jamais masquee par un filtre.
     *
     * Ouvrir un echange termine depuis « En cours » viderait la liste sous les
     * pieds du lecteur, qui perdrait son contexte sans comprendre pourquoi.
     */
    public function test_la_conversation_ouverte_survit_au_filtre(): void
    {
        $termine = $this->echange('completed');

        $html = $this->actingAs($this->aidant)
            ->get(route('messages.show', ['transaction' => $termine, 'filtre' => 'en_cours']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-conversation-ligne="'.$termine->id.'"', $html);
    }

    /** Un filtre inconnu ne filtre RIEN — il ne vide pas l'ecran en silence. */
    public function test_un_filtre_inconnu_ne_masque_rien(): void
    {
        $echange = $this->echange('accepted');

        $html = $this->actingAs($this->aidant)
            ->get(route('messages.index', ['filtre' => 'inexistant']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-conversation-ligne="'.$echange->id.'"', $html);
    }

    /**
     * Le SENS de l'aide sort bien sur la ligne, et il differe selon le lecteur.
     *
     * Une assertion sur une seule vue passerait au vert sur un sens fige.
     */
    public function test_la_ligne_porte_le_sens_et_il_differe_selon_le_lecteur(): void
    {
        $this->echange('accepted');

        $vuAidant = $this->actingAs($this->aidant)->get(route('messages.index'))->getContent();
        $vuAide = $this->actingAs($this->aide)->get(route('messages.index'))->getContent();

        $this->assertStringContainsString('data-conversation-sens', $vuAidant);
        $this->assertStringContainsString('Vous aidez', $vuAidant);
        $this->assertStringNotContainsString('Vous aidez', $vuAide);
        // TASK-1808 — l'aide lit desormais qu'on L'AIDE, et surtout jamais
        // qu'on lui demande de l'aide. C'est le defaut signale par Cyril, vu
        // depuis la vraie page de liste et non depuis la seule classe.
        $this->assertStringContainsString('vous aide', $vuAide);
        $this->assertStringNotContainsString('vous demande de', $vuAide);
    }

    /** La phrase est TRADUITE — jamais figee en francais. */
    public function test_la_phrase_est_traduite(): void
    {
        $echange = $this->echange('accepted');

        $fr = CarteEntraide::phrase($echange, $this->aide);
        $this->app->setLocale('en');
        $en = CarteEntraide::phrase($echange, $this->aide);

        $this->assertNotSame($fr, $en);
        $this->assertStringContainsString('confirm', $en);
    }
}
