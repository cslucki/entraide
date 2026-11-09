<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Review;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1788, correctif 05 — les remerciements repris et les BADGES s'en vont.
 *
 * Deux demandes de Cyril :
 *
 * 1. retirer « 🙏 1 merci reçu » et sa citation de l'apercu : « puisqu'on a
 *    deja les evaluations » — la section « Avis » de la colonne de gauche les
 *    porte en entier, avec leur note et leur date ;
 * 2. supprimer DEFINITIVEMENT les badges, « et donc le systeme d'attribution
 *    de badges qui est suite a certains evenements, ainsi on allege le code ».
 *
 * ## Ce que ce fichier tient
 *
 * Qu'il ne reste RIEN : ni affichage, ni attribution, ni classe. Un systeme a
 * moitie retire est pire que pas retire du tout — il continue d'ecrire en base
 * sans que personne ne regarde le resultat.
 *
 * ## Les deux TABLES subsistent, et c'est voulu
 *
 * `badges` et `badge_user` restent en place : les retirer demande une
 * MIGRATION, qui sort du perimetre d'un lot de correctifs (condition d'arret
 * posee a l'ouverture de TASK-1788), et qui detruirait des attributions
 * reelles — 15 lignes pour 4 membres sur le jumeau au moment du correctif.
 * Plus aucun code ne les lit ni ne les ecrit.
 */
class TASK1788Correctif05BadgesEtMercisTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1788-05', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    // ── 1. Les remerciements repris ──────────────────────────────────────────

    /**
     * ⚠️ Ma premiere version de ce test ne mesurait RIEN.
     *
     * Elle rendait la fiche d'un membre SANS aucun merci : le bloc
     * `@if($bilan['mercis'] > 0)` n'aurait de toute facon rien rendu, code
     * retire ou non. Le sabotage — remettre le bloc — la laissait verte.
     *
     * La precondition manquait. Ce test fabrique donc un VRAI merci : une
     * entraide terminee, puis un avis qui porte un commentaire. Le compteur du
     * bilan doit le voir ; seule la REPRISE des citations doit avoir disparu.
     */
    public function test_l_apercu_ne_reprend_plus_les_remerciements(): void
    {
        $this->unMerciReel();

        $html = $this->actingAs($this->membre)
            ->get(route('profile.show', $this->membre))
            ->assertOk()
            ->getContent();

        // TEMOINS : la fiche est rendue, l'apercu aussi, et le bilan COMPTE
        // bien ce merci — c'est ce qui prouve que la precondition a pris.
        $this->assertStringContainsString('data-profile-entete', $html,
            'Temoin absent : la fiche de profil n\'est pas rendue.');
        $this->assertStringContainsString('data-profile-apercu', $html,
            'Temoin absent : l\'apercu chiffre n\'est pas rendu.');
        $this->assertSame(1, \App\Support\Entraide\BilanEntraide::pour($this->membre, $this->organization)['mercis'],
            'Precondition non prise : le bilan ne compte aucun merci, le test ne prouverait rien.');

        $this->assertStringNotContainsString('data-entraide-bilan-mercis', $html,
            'La reprise des remerciements est encore rendue dans l\'apercu.');
        // ⚠️ L'assertion sur la CITATION porte sur la carte, pas sur la page :
        // le commentaire s'affiche toujours dans la section « Avis » de la
        // colonne de gauche — c'est exactement la raison du retrait (« puisqu'on
        // a deja les evaluations »). Le chercher dans tout le document faisait
        // rougir un correctif juste.
        $debut = strpos($html, 'data-profile-stats');
        $carte = substr($html, $debut, strpos($html, '</section>', $debut) - $debut);

        $this->assertStringNotContainsString('Un grand merci pour ce coup de main.', $carte,
            'La citation du remerciement est encore reprise dans la carte.');
        $this->assertStringContainsString('Un grand merci pour ce coup de main.', $html,
            'Le commentaire a disparu de la page : il devait rester dans la section « Avis ».');
    }

    /** Le titre demande par Cyril, au meme gabarit que « Blog ». */
    public function test_la_carte_porte_le_titre_stats(): void
    {
        $this->unMerciReel();

        $html = $this->actingAs($this->membre)
            ->get(route('profile.show', $this->membre))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-profile-stats', $html, 'La carte n\'a pas de section titree.');
        $this->assertStringContainsString('>'.__('profile.stats_section').'<', $html, 'Le titre « Stats » n\'est pas rendu.');

        // Le titre precede la carte, il ne la suit pas.
        $this->assertLessThan(
            strpos($html, 'data-profile-apercu'),
            strpos($html, 'data-profile-stats'),
            'Le titre est rendu APRES la carte.'
        );
    }

    /** Une entraide terminee, puis un avis qui porte un commentaire. */
    private function unMerciReel(): void
    {
        $beneficiaire = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $annonce = Service::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
            'points_cost' => 45,
        ]);

        $echange = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'seller_id' => $this->membre->id,
            'buyer_id' => $beneficiaire->id,
            'service_id' => $annonce->id,
            'status' => 'completed',
            'points_proposed' => 45,
            'points_agreed' => 45,
        ]);

        Review::factory()->create([
            'transaction_id' => $echange->id,
            'reviewer_id' => $beneficiaire->id,
            'reviewed_id' => $this->membre->id,
            'rating' => 5,
            'comment' => 'Un grand merci pour ce coup de main.',
        ]);
    }

    // ── 2. Les badges ────────────────────────────────────────────────────────

    /**
     * Le CODE a disparu : classes, service, catalogue, semoir, traductions.
     *
     * `class_exists()` et non un `grep` : un fichier peut rester sur le disque
     * sans etre charge, et un `grep` ne dit jamais si une surface est VIVANTE.
     */
    public function test_plus_aucune_classe_du_systeme_de_badges_n_existe(): void
    {
        foreach ([
            \App\Models\Badge::class,
            \App\Services\BadgeService::class,
            \App\Support\Entraide\CatalogueDeBadges::class,
            \Database\Seeders\BadgeSeeder::class,
            \App\Observers\ServiceObserver::class,
            \App\Observers\TransactionObserver::class,
        ] as $classe) {
            $this->assertFalse(class_exists($classe), "La classe `{$classe}` existe encore.");
        }
    }

    /** La relation du modele `User` s'en va avec le reste. */
    public function test_le_modele_user_ne_connait_plus_les_badges(): void
    {
        $this->assertFalse(method_exists($this->membre, 'badges'),
            '`User::badges()` existe encore : une relation sans modele au bout.');
    }

    /**
     * ⚠️ LE test du correctif : les evenements n'attribuent plus rien.
     *
     * Les deux observateurs n'existaient QUE pour cela — une annonce creee, un
     * echange termine. Les deposer sans le verifier aurait pu laisser un
     * `observe()` en place, qui aurait leve une erreur de classe introuvable au
     * premier enregistrement. Ce test cree et termine pour de vrai.
     */
    public function test_creer_une_annonce_et_terminer_un_echange_n_attribue_plus_rien(): void
    {
        $beneficiaire = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $annonce = \App\Models\Service::factory()->create([
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'category_id' => \App\Models\Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
            'points_cost' => 45,
        ]);

        $echange = \App\Models\Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'seller_id' => $this->membre->id,
            'buyer_id' => $beneficiaire->id,
            'service_id' => $annonce->id,
            'status' => 'pending',
            'points_proposed' => 45,
            'points_agreed' => 45,
        ]);

        // La transition qui declenchait l'attribution.
        $echange->update(['status' => 'completed']);

        $this->assertSame('completed', $echange->fresh()->status,
            'Temoin absent : la transition n\'a pas eu lieu, le test ne prouverait rien.');

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('badge_user')->count(),
            'Un badge a ete attribue alors que le systeme est supprime.');
    }

    /**
     * Les deux tables subsistent — decision, pas oubli.
     *
     * Les retirer est une MIGRATION : hors du perimetre d'un lot de correctifs,
     * et destructrice pour des attributions reelles. Ce test fixe la decision
     * pour qu'un futur passage ne la prenne pas pour un reste a nettoyer.
     */
    public function test_les_tables_subsistent_et_personne_ne_les_ecrit_plus(): void
    {
        $this->assertTrue(Schema::hasTable('badges'), 'La table `badges` a ete retiree : cela demande une TASK dediee.');
        $this->assertTrue(Schema::hasTable('badge_user'), 'La table `badge_user` a ete retiree : cela demande une TASK dediee.');
    }

    /**
     * La FK vers `users` reste declaree au registre de cycle de vie.
     *
     * Elle le doit tant que la table existe : le registre mesure le SCHEMA, pas
     * le code. Retirer l'entree en gardant la table ferait rougir
     * `UserDataLifecycleRegistryTest` sur les deux moteurs.
     */
    public function test_la_cle_etrangere_reste_declaree_au_registre(): void
    {
        $cles = array_column(\App\Services\UserDataLifecycleRegistry::entries(), 'key');

        $this->assertContains('badge_user', $cles,
            'L\'entree de registre a ete retiree alors que la table existe toujours.');
    }
}
