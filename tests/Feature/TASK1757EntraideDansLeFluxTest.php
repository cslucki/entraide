<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Feed\FeedEventCatalogue;
use App\Support\Feed\FeedEventRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1757 (§9.5 du CDC entraide) — l'entraide entre dans le Flux.
 *
 * ## Les deux decisions que ce fichier garde
 *
 * 1. **L'accord est explicite et par echange** (arbitrage du 05/11, toujours
 *    vrai pour le chemin HERITE `confirm()`/`partagerDansLeFlux()` — voir son
 *    docbloc). Le §9.5 dit « opt-in » et aucun stockage n'existait pour ca.
 *    La case vit dans le formulaire de confirmation : decochee, aucune ligne.
 *    Donc aucune colonne, aucune migration.
 *
 *    TASK-1809 §2 (12/11) a ouvert un SECOND chemin, desormais le chemin
 *    COURANT : `EvaluationEntraide::enregistrer()` partage AUTOMATIQUEMENT,
 *    sans case, des que l'aide depose son evaluation — plus de parcours
 *    visible ne pose la case. Ce fichier ne teste QUE le chemin herite ; le
 *    partage automatique est teste dans
 *    `TASK1808ParcoursFinalEntraideTest::test_l_evaluation_partage_
 *    automatiquement_l_entraide_dans_le_flux`.
 *
 * 2. **Les DEUX personnes sont nommees** (decision Cyril, 12/11 — REMPLACE
 *    l'arbitrage du 05/11, qui ne nommait que l'aidant : « Barnabe a RECU
 *    30 min d'aide » exposait quelqu'un qui n'avait rien decide). Mot pour
 *    mot : « Si on nomme les deux, c'est une plateforme de confiance et
 *    ouverte. Et il faut etre connecte et membre accepte. Donc aucun
 *    probleme a dire les choses et de facon bienveillante. » La carte nomme
 *    donc l'aidant ET l'aide, et l'annonce par son TITRE (plus par sa seule
 *    categorie) — BouclePro est une plateforme de pedagogie par entraide, pas
 *    de troc.
 */
class TASK1757EntraideDansLeFluxTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $aidant;

    private User $aide;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1757-flux', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->aidant = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Ottoline']);
        $this->aide = User::factory()->create(['organization_id' => $this->organization->id, 'first_name' => 'Barnabe', 'points_balance' => 500]);
    }

    /** Un echange prêt a etre confirme : l'aide a dit avoir recu. */
    private function pretAConfirmer(int $points = 30, string $categorie = 'Bricolage'): Transaction
    {
        $annonce = Service::factory()->create([
            'user_id' => $this->aidant->id,
            'organization_id' => $this->organization->id,
            // `name_b2c` : `categories` n'a pas de colonne `name`.
            'category_id' => Category::factory()->create([
                'organization_id' => $this->organization->id,
                'name_b2c' => $categorie,
                'name_b2b' => $categorie,
            ])->id,
            'status' => 'active',
            'points_cost' => $points,
        ]);

        return Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'service_id' => $annonce->id,
            'seller_id' => $this->aidant->id,
            'buyer_id' => $this->aide->id,
            'status' => 'buyer_done',
            'points_proposed' => $points,
            'points_agreed' => $points,
        ]);
    }

    private function confirmer(Transaction $echange, bool $partager): void
    {
        $this->actingAs($this->aidant)
            ->patch(route('transactions.confirm', $echange), $partager ? ['partager_dans_le_flux' => '1'] : [])
            ->assertSessionHasNoErrors();
    }

    private function lignesDEntraide()
    {
        return FeedPost::withoutGlobalScopes()
            ->where('type', FeedEventCatalogue::ENTRAIDE_TERMINEE)
            ->get();
    }

    /** 🔴 L'OPT-IN — decochee, il ne se passe RIEN. */
    public function test_sans_la_case_cochee_aucune_ligne_n_est_ecrite(): void
    {
        $echange = $this->pretAConfirmer();

        $this->confirmer($echange, partager: false);

        $this->assertSame('completed', $echange->fresh()->status, 'L\'echange doit bien se clore.');
        $this->assertCount(0, $this->lignesDEntraide(), 'Une entraide a ete publiee sans accord.');
    }

    /** Cochee, la ligne existe — et son acteur est l'AIDANT. */
    public function test_avec_la_case_cochee_une_ligne_est_ecrite_au_nom_de_l_aidant(): void
    {
        $echange = $this->pretAConfirmer();

        $this->confirmer($echange, partager: true);

        $lignes = $this->lignesDEntraide();
        $this->assertCount(1, $lignes);

        $ligne = $lignes->first();
        $this->assertSame($this->aidant->id, $ligne->user_id, 'L\'acteur doit etre l\'aidant, celui qui a coche.');
        $this->assertSame((string) $echange->id, (string) $ligne->object_id);
        $this->assertSame(FeedEventCatalogue::VISIBILITE_ORGANIZATION, $ligne->visibility);
    }

    /**
     * 🔴 LA DECISION — la carte nomme les DEUX personnes, et le TITRE de
     * l'annonce (decision Cyril du 12/11, REMPLACE l'arbitrage du 05/11).
     */
    public function test_la_carte_nomme_l_aidant_l_aide_le_temps_et_le_titre_de_l_annonce(): void
    {
        $echange = $this->pretAConfirmer(points: 30, categorie: 'Bricolage');
        $titre = $echange->service->title;
        $this->confirmer($echange, partager: true);

        $ligne = $this->lignesDEntraide()->first();

        foreach ([FeedEventRenderer::libelle($ligne), FeedEventRenderer::ligneHtml($ligne, $this->organization->slug)] as $rendu)
        {
            $this->assertStringContainsString('Ottoline', $rendu, 'L\'aidant doit etre nomme.');
            $this->assertStringContainsString('Barnabe', $rendu, 'L\'aide doit desormais etre nomme aussi.');
            $this->assertStringContainsString('30 min', $rendu, 'Le temps donne doit apparaitre.');
            $this->assertStringContainsString($titre, $rendu, 'Le titre de l\'annonce doit apparaitre, plus la seule categorie.');
        }
    }

    /**
     * TASK-1809 (correctif Cyril, 12/11) — la piece jointe « premium » :
     * l'icone Entraide toujours, l'evaluation (etoiles + chiffre +
     * commentaire) SEULEMENT quand elle existe. Sans avis, la ligne se
     * suffit (contrat deja tenu par `apercu()` — `null` = pas de piece
     * jointe).
     */
    public function test_la_carte_porte_l_icone_et_l_evaluation_quand_elle_existe(): void
    {
        $echange = $this->pretAConfirmer();
        $this->confirmer($echange, partager: true);
        $ligne = $this->lignesDEntraide()->first();

        $this->assertNull(FeedEventRenderer::apercu($ligne, $this->organization->slug),
            'Sans avis depose, aucune piece jointe ne doit etre proposee.');

        Review::create([
            'transaction_id' => $echange->id,
            'organization_id' => $this->organization->id,
            'reviewer_id' => $this->aide->id,
            'reviewed_id' => $this->aidant->id,
            'rating' => 4,
            'comment' => 'Un vrai plaisir, merci !',
        ]);

        $apercu = FeedEventRenderer::apercu($ligne, $this->organization->slug);
        $this->assertNotNull($apercu);
        $this->assertSame('M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4', $apercu['icone'],
            'La MEME icone que le rail gauche, jamais une seconde icone inventee.');
        $this->assertStringContainsString('★★★★☆', $apercu['titre']);
        $this->assertStringContainsString('4/5', $apercu['titre']);
        $this->assertSame('Un vrai plaisir, merci !', $apercu['texte']);
    }

    /**
     * Une annonce RETIREE depuis ne doit pas afficher « un contenu supprime ».
     *
     * Premiere version de ce test : je mettais `category_id` a `null`. La base
     * l'a refuse — `services.category_id` est NOT NULL, donc une annonce sans
     * categorie N'EXISTE PAS. Le repli neutre n'est atteignable que par la
     * disparition de l'ANNONCE, et c'est bien le vrai cas : la ligne de Flux
     * survit a son annonce.
     *
     * Et le repli doit rester neutre : une entraide dont l'annonce a ete
     * retiree a quand meme eu lieu. « un contenu supprime » parlerait de
     * l'annonce, pas de l'aide — et dirait a l'aidant que ce qu'il a donne a
     * disparu.
     */
    public function test_une_annonce_retiree_depuis_retombe_sur_un_libelle_neutre(): void
    {
        $echange = $this->pretAConfirmer();
        $this->confirmer($echange, partager: true);

        $echange->service->delete();

        $rendu = FeedEventRenderer::libelle($this->lignesDEntraide()->first()->fresh());

        $this->assertStringContainsString('Ottoline', $rendu, 'L\'aidant reste nomme.');
        $this->assertStringContainsString('Barnabe', $rendu, 'L\'aide reste nomme aussi.');
        $this->assertStringContainsString('Entraide', $rendu);
        $this->assertStringNotContainsString('supprimé', $rendu);
    }

    /** Une DISCUSSION directe n'a rien a raconter, meme cochee. */
    public function test_une_discussion_directe_ne_produit_aucune_ligne(): void
    {
        $directe = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'service_id' => null, 'request_id' => null,
            'seller_id' => $this->aidant->id, 'buyer_id' => $this->aide->id,
            'status' => 'buyer_done', 'points_proposed' => 0, 'points_agreed' => 0,
        ]);

        $this->confirmer($directe, partager: true);

        $this->assertCount(0, $this->lignesDEntraide());
    }

    /**
     * L'interrupteur d'Organization garde la main.
     *
     * Une Organization qui a coupe les evenements automatiques ne doit pas voir
     * l'entraide arriver par la porte de derriere : l'accord du membre ouvre SA
     * part, pas celle de l'Organization.
     */
    public function test_une_organization_qui_a_coupe_les_evenements_ne_recoit_rien(): void
    {
        $this->organization->update(['feed_auto_events_enabled' => false]);

        $this->confirmer($this->pretAConfirmer(), partager: true);

        $this->assertCount(0, $this->lignesDEntraide());
    }

    /**
     * Deux confirmations ne font qu'une ligne.
     *
     * L'idempotence est STRUCTURELLE — `unique(organization, type, object_type,
     * object_id)` — mais la mesurer ici vaut le coup : un double envoi est le
     * cas le plus banal (deux onglets, un bouton de la PWA en cache).
     */
    public function test_une_seconde_confirmation_ne_duplique_pas_la_ligne(): void
    {
        $echange = $this->pretAConfirmer();

        $this->confirmer($echange, partager: true);
        $this->confirmer($echange->fresh(), partager: true);

        $this->assertCount(1, $this->lignesDEntraide());
    }

    /** Un coup de main OFFERT se partage aussi — « Offert » et non « 0 min ». */
    public function test_un_coup_de_main_offert_se_partage(): void
    {
        $echange = $this->pretAConfirmer(points: 0);

        $this->confirmer($echange, partager: true);

        $lignes = $this->lignesDEntraide();
        $this->assertCount(1, $lignes);
        $this->assertStringContainsString('Offert', FeedEventRenderer::libelle($lignes->first()));
    }
}
