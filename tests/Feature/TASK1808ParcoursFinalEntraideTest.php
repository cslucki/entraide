<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1808 — LE CRITERE DE RECETTE BLOQUANT DE MASTER (11/11), en code.
 *
 * « Tester exactement : D1 demande -> D2 accepte -> D1 ouvre le chat.
 *   Resultat obligatoire : conversation ouverte, bouton de cloture visible,
 *   ZERO element d'evaluation. Puis : D1 clique -> cloture et points ->
 *   confirmation -> ecran etoiles -> ecran commentaire facultatif. Verifier
 *   que rechargement, navigation, retour arriere, acces direct aux URL et
 *   requetes serveur ne permettent JAMAIS d'evaluer une transaction non
 *   terminee. »
 *
 * Les deux invariants confirmes a MASTER avant de poursuivre :
 *
 *   1. AUCUNE evaluation possible avant `completed` — ni dans le rendu
 *      (aucun marqueur, aucune etoile, aucun champ), ni par le serveur
 *      (`reviews.store`, `reviews.skip`, `reviews.comment` refusent tous) ;
 *   2. le bouton de cloture TERMINE reellement l'entraide — un seul PATCH,
 *      sans corps : `completed`, points transferes une fois, messagerie en
 *      lecture seule. Aucun formulaire intermediaire.
 */
class TASK1808ParcoursFinalEntraideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aide;    // D1 — buyer

    private User $aidant;  // D2 — seller

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->aide = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 100]);
        $this->aidant = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 10]);
        app()->instance('current_organization', $this->organization);
    }

    private function entraideAcceptee(array $overrides = []): Transaction
    {
        return Transaction::factory()
            ->forBuyer($this->aide)
            ->forSeller($this->aidant)
            ->accepted()
            ->create(array_merge([
                'organization_id' => $this->organization->id,
                'points_proposed' => 20,
                'points_agreed' => 20,
            ], $overrides));
    }

    // ── Invariant 1 — AVANT la cloture, zero element d'evaluation ──────────

    public function test_avant_la_cloture_le_fil_de_l_aide_ne_porte_aucun_element_d_evaluation(): void
    {
        $entraide = $this->entraideAcceptee();

        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide])
            ->html();

        // Ce qui DOIT etre la : la conversation, et le bouton de cloture.
        $this->assertStringContainsString('data-entraide-cloturer', $html);
        $this->assertStringContainsString(__('messages.cloturer_bouton'), $html);

        // Ce qui ne doit PAS y etre — « meme replie » : le marqueur absent
        // prouve que l'element n'est pas dans le DOM, pas seulement masque.
        foreach ([
            'data-entraide-etoiles',
            'data-entraide-mot',
            'data-entraide-merci-ignorer',
            'data-entraide-partager-flux',
            'name="rating"',
            'name="comment"',
            '★', '☆',
        ] as $interdit) {
            $this->assertStringNotContainsString(
                $interdit, $html,
                "« {$interdit} » apparait dans le fil AVANT la cloture — l'evaluation est proposee trop tot.",
            );
        }

        // Et aucune phrase ne laisse croire qu'une evaluation est attendue.
        $this->assertStringNotContainsString(__('messages.etoiles_titre', ['prenom' => $this->aidant->first_name]), $html);
        $this->assertStringNotContainsString(__('messages.mot_titre'), $html);
    }

    public function test_avant_la_cloture_le_serveur_refuse_toute_evaluation(): void
    {
        $entraide = $this->entraideAcceptee();

        // Acces direct aux URL : les trois portes d'evaluation, fermees.
        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 5])
            ->assertForbidden();
        $this->actingAs($this->aide)->post(route('reviews.skip', $entraide))
            ->assertForbidden();
        $this->actingAs($this->aide)->post(route('reviews.comment', $entraide), ['comment' => 'trop tot'])
            ->assertForbidden();
        $this->actingAs($this->aide)->post(route('reviews.comment.skip', $entraide))
            ->assertForbidden();

        // Rien n'a ete ecrit : ni avis, ni renoncement.
        $this->assertSame(0, Review::where('transaction_id', $entraide->id)->count());
        $this->assertNull($entraide->fresh()->reviews_skipped_at);
    }

    public function test_avant_la_cloture_le_partage_dans_le_flux_est_refuse_aussi(): void
    {
        $entraide = $this->entraideAcceptee();

        $this->actingAs($this->aide)->post(route('transactions.share-in-feed', $entraide))
            ->assertForbidden();
    }

    // ── Invariant 2 — le clic cloture REELLEMENT, sans formulaire ──────────

    public function test_le_bouton_cloture_transfere_et_ferme_sans_rien_demander(): void
    {
        $entraide = $this->entraideAcceptee();

        // Le geste de recette : UN PATCH, corps VIDE — exactement ce que le
        // formulaire du bouton envoie. Pas de note, pas de mot, pas de case.
        $this->actingAs($this->aide)
            ->patch(route('transactions.complete', $entraide))
            ->assertRedirect(route('messages.show', $entraide));

        $entraide->refresh();

        // Cloture et transfert, une seule fois.
        $this->assertSame('completed', $entraide->status);
        $this->assertSame(80, $this->aide->fresh()->points_balance);
        $this->assertSame(30, $this->aidant->fresh()->points_balance);
        $this->assertSame(2, PointLedger::where('transaction_id', $entraide->id)->count());

        // AUCUNE evaluation n'a ete fabriquee au passage : pas d'avis, et pas
        // de renoncement non plus — l'evaluation n'a simplement pas encore
        // ete proposee.
        $this->assertSame(0, Review::where('transaction_id', $entraide->id)->count());
        $this->assertNull($entraide->reviews_skipped_at);

        // Un second clic (retour arriere, double envoi) ne re-transfere rien.
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));
        $this->assertSame(80, $this->aide->fresh()->points_balance);
        $this->assertSame(2, PointLedger::where('transaction_id', $entraide->id)->count());
    }

    public function test_apres_la_cloture_la_messagerie_est_en_lecture_seule_pour_les_deux(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        foreach ([$this->aide, $this->aidant] as $membre) {
            Livewire::actingAs($membre)
                ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
                ->set('newMessage', 'un message apres la fin')
                ->call('sendMessage');
        }

        // La garde est SERVEUR : aucun message n'est entre, quel que soit le
        // role. Le champ masque n'est pas la protection, il n'en est que le
        // reflet.
        $this->assertSame(
            0,
            $entraide->messages()->whereNotNull('sender_id')->where('body', 'un message apres la fin')->count(),
        );
    }

    // ── La sequence d'apres-cloture : confirmation, ecran A, puis B ────────

    public function test_apres_la_cloture_d1_lit_la_confirmation_puis_l_ecran_des_etoiles(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();

        // « Entraide terminee ! X points ont ete transferes a <prenom>. »
        $this->assertStringContainsString('data-entraide-cloture-aide', $html);
        $this->assertStringContainsString(__('messages.cloture_aide_titre'), $html);

        // Puis l'ecran A — et jamais l'ecran B en meme temps.
        $this->assertStringContainsString('data-entraide-etoiles', $html);
        $this->assertStringContainsString(__('messages.etoiles_plus_tard'), $html);
        $this->assertStringNotContainsString('data-entraide-mot', $html);

        // Aucune preselection : l'etat Alpine demarre a zero.
        $this->assertStringContainsString('note: 0', $html);
    }

    public function test_l_ecran_des_etoiles_revient_apres_un_plus_tard_et_la_note_s_enregistre_seule(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        // « Plus tard » n'ecrit rien : au rechargement, l'ecran A est
        // toujours la et aucun renoncement n'a ete trace.
        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringContainsString('data-entraide-etoiles', $html);
        $this->assertNull($entraide->fresh()->reviews_skipped_at);

        // La note SEULE est une evaluation complete, enregistree aussitot.
        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 4]);

        $this->assertDatabaseHas('reviews', [
            'transaction_id' => $entraide->id,
            'reviewer_id' => $this->aide->id,
            'reviewed_id' => $this->aidant->id,
            'rating' => 4,
            'comment' => null,
        ]);

        // Et les points n'ont pas bouge d'un iota : evaluer ne re-transfere
        // jamais.
        $this->assertSame(80, $this->aide->fresh()->points_balance);
        $this->assertSame(30, $this->aidant->fresh()->points_balance);
    }

    public function test_l_ecran_du_mot_ne_vient_qu_apres_les_etoiles_et_quitter_ne_perd_jamais_la_note(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));
        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 5]);

        // L'ecran B remplace l'ecran A — jamais les deux.
        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringContainsString('data-entraide-mot', $html);
        $this->assertStringNotContainsString('data-entraide-etoiles', $html);

        // Publier le mot complete l'avis EXISTANT — une seule ligne, note
        // intacte.
        $this->actingAs($this->aide)->post(route('reviews.comment', $entraide), ['comment' => 'Merci encore !']);

        $avis = Review::where('transaction_id', $entraide->id)->get();
        $this->assertCount(1, $avis);
        $this->assertSame(5, $avis->first()->rating);
        $this->assertSame('Merci encore !', $avis->first()->comment);

        // L'ecran B ne revient plus.
        $this->assertStringNotContainsString(
            'data-entraide-mot',
            Livewire::actingAs($this->aide)->test(MessageThread::class, ['transaction' => $entraide->fresh()])->html(),
        );
    }

    public function test_terminer_sans_commentaire_garde_la_note_et_clot_la_sequence(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));
        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 3]);

        $this->actingAs($this->aide)->post(route('reviews.comment.skip', $entraide));

        $avis = Review::where('transaction_id', $entraide->id)->first();
        $this->assertSame(3, $avis->rating);
        $this->assertNull($avis->comment);
        $this->assertNotNull($avis->comment_skipped_at);

        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringNotContainsString('data-entraide-mot', $html);
        $this->assertStringNotContainsString('data-entraide-etoiles', $html);
    }

    // ── Cote aidant : la reconnaissance, et une seule pluie de coeurs ──────

    public function test_l_aidant_lit_le_merci_et_recoit_la_pluie_de_coeurs_une_seule_fois(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        // Premiere visite : le merci, les points recus, et les coeurs.
        $premiere = Livewire::actingAs($this->aidant)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringContainsString('data-entraide-cloture-aidant', $premiere);
        $this->assertStringContainsString(__('messages.cloture_aidant_titre'), $premiere);
        $this->assertStringContainsString('data-entraide-coeurs', $premiere);
        $this->assertNotNull($entraide->fresh()->seller_celebrated_at);

        // Deuxieme visite (rechargement, autre appareil) : plus de pluie.
        $seconde = Livewire::actingAs($this->aidant)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringNotContainsString('data-entraide-coeurs', $seconde);

        // Et l'aidant ne voit JAMAIS un formulaire d'evaluation, ni avant ni
        // apres : il n'evalue pas, on ne lui annonce aucune attente.
        $this->assertStringNotContainsString('data-entraide-etoiles', $premiere);
        $this->assertStringNotContainsString('data-entraide-mot', $premiere);
    }

    public function test_la_pluie_de_coeurs_n_est_jamais_pour_l_aide(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();

        $this->assertStringNotContainsString('data-entraide-coeurs', $html);
        // Le marqueur reste vierge : la visite de l'aide ne consomme pas la
        // celebration de l'aidant.
        $this->assertNull($entraide->fresh()->seller_celebrated_at);
    }

    /**
     * TASK-1808 (recette navigateur, 11/11) — DEFAUT TROUVE EN CONDITIONS
     * REELLES, invisible depuis PHPUnit : `messages/index.blade.php` monte le
     * MEME composant DEUX FOIS sur une seule page (une copie mobile
     * `md:hidden`, une copie desktop `hidden md:flex`). Chaque copie appelle
     * `mount()` independamment. Sans garde, le `whereNull` qui pose
     * `seller_celebrated_at` ne laissait gagner QU'UNE des deux instances —
     * et c'etait systematiquement la copie mobile, premiere dans le Blade,
     * meme a un viewport desktop : la pluie de coeurs etait donc posee dans
     * la copie INVISIBLE et jamais vue a l'ecran, quel que soit l'appareil.
     *
     * Ce test reproduit le double montage DANS LA MEME REQUETE (deux
     * `Livewire::test()` pour la MEME transaction, sans rafraichir entre les
     * deux) et prouve que les deux instances s'accordent desormais :
     * laquelle que la CSS montre, elle montre la pluie.
     */
    public function test_les_deux_instances_mobile_et_desktop_s_accordent_sur_la_pluie(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $fraiche = $entraide->fresh();

        $premiereInstance = Livewire::actingAs($this->aidant)
            ->test(MessageThread::class, ['transaction' => $fraiche])
            ->html();
        $deuxiemeInstance = Livewire::actingAs($this->aidant)
            ->test(MessageThread::class, ['transaction' => $fraiche])
            ->html();

        // Les deux copies recoivent la MEME reponse : soit les deux montrent
        // les coeurs, soit aucune — jamais une seule au hasard de l'ordre.
        $this->assertStringContainsString('data-entraide-coeurs', $premiereInstance);
        $this->assertStringContainsString('data-entraide-coeurs', $deuxiemeInstance);

        // Et une seule celebration a ete ecrite en base, quel que soit le
        // nombre d'instances qui l'ont demandee dans la meme requete.
        $this->assertNotNull($fraiche->fresh()->seller_celebrated_at);
    }

    // ── Precision MASTER (11/11 soir) — « Plus tard » ne revient plus ─────

    public function test_plus_tard_ne_rouvre_plus_le_formulaire_aux_visites_suivantes(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        // Premiere visite : le formulaire est ouvert en clair.
        $premiere = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringContainsString('data-entraide-etoiles', $premiere);
        $this->assertStringContainsString(__('messages.etoiles_titre', ['prenom' => $this->aidant->first_name]), $premiere);

        $this->actingAs($this->aide)->post(route('reviews.defer', $entraide))
            ->assertRedirect(route('messages.show', $entraide));

        $entraide->refresh();
        $this->assertNotNull($entraide->evaluation_deferred_at);
        // « Plus tard » n'est PAS un refus.
        $this->assertNull($entraide->reviews_skipped_at);
        $this->assertTrue($entraide->etoilesEnAttente());

        // Visites suivantes : la structure bascule vers la presentation
        // « differee » — seule l'action secondaire est VISIBLE au chargement
        // (`<template x-if>` : le formulaire n'est clone dans le DOM qu'au
        // clic, Alpine a besoin de son contenu dans la page source pour
        // pouvoir le cloner, ce qui n'est pas une relance — rien n'est
        // affiche avant une action explicite du lecteur). La preuve VISUELLE
        // de ce comportement est la recette navigateur, pas ce test PHP.
        $html = Livewire::actingAs($this->aide)
            ->test(MessageThread::class, ['transaction' => $entraide->fresh()])
            ->html();
        $this->assertStringContainsString('data-entraide-etoiles-differe', $html);
        $this->assertStringContainsString('data-entraide-etoiles-reprendre', $html);
        $this->assertStringContainsString(__('messages.etoiles_laisser_un_avis'), $html);
        // La premiere presentation (ouverte en clair, sans wrapper "differe")
        // n'est plus celle qui est rendue.
        $this->assertStringNotContainsString('x-data="{ note: 0, survol: 0 }"', $html);

        // La note reste utilisable « librement » depuis cette action.
        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 5]);
        $this->assertDatabaseHas('reviews', ['transaction_id' => $entraide->id, 'rating' => 5]);
    }

    public function test_un_second_plus_tard_reste_sans_effet(): void
    {
        $entraide = $this->entraideAcceptee();
        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));

        $this->actingAs($this->aide)->post(route('reviews.defer', $entraide));
        $premiereDate = $entraide->fresh()->evaluation_deferred_at;

        $this->actingAs($this->aide)->post(route('reviews.defer', $entraide));
        $this->assertEquals($premiereDate, $entraide->fresh()->evaluation_deferred_at);
    }

    public function test_defer_est_refuse_avant_la_cloture_et_apres_une_note_deposee(): void
    {
        $entraide = $this->entraideAcceptee();

        // Avant cloture : refuse comme les autres portes d'evaluation.
        $this->actingAs($this->aide)->post(route('reviews.defer', $entraide))
            ->assertForbidden();

        $this->actingAs($this->aide)->patch(route('transactions.complete', $entraide));
        $this->actingAs($this->aide)->post(route('reviews.store', $entraide), ['rating' => 5]);

        // Un avis existe deja : differer n'a plus de sens.
        $this->actingAs($this->aide)->post(route('reviews.defer', $entraide))
            ->assertForbidden();
    }
}
