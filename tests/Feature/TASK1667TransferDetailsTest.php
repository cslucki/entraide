<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1667 — « 3 contenus a confier », mais LESQUELS ?
 *
 * La modal demandait a l'admin de choisir un repreneur pour des contenus qu'il
 * n'avait jamais vus. Le detail par famille existait DEJA dans
 * `UserDeletionExecutor::precheck()` : seul `array_sum()` le jetait.
 *
 * ## Ce que ces tests protegent en priorite
 *
 * 1. **La somme et le detail ne doivent jamais diverger.** `transfer_total`
 *    reste affiche ; si un jour l'un des deux se calculait autrement, la modal
 *    annoncerait « 3 contenus » en listant autre chose. Un test l'interdit.
 *
 * 2. **`feed_posts` n'a AUCUN ecran d'administration dans ce depot.** Sa ligne
 *    doit sortir avec son compte mais SANS `url` : un lien vers une page
 *    absente serait pire que pas de lien.
 *
 * 3. **Le filtre `user_id` ne detourne pas `search`**, qui cherche un titre sur
 *    ces ecrans. Les deux doivent rester utilisables ensemble.
 *
 * 4. La forme de l'UUID est validee avant d'atteindre PostgreSQL
 *    (`SQLSTATE 22P02`). Ce cas est **vide de sens en SQLite**, qui accepte le
 *    texte libre : il ne prouve quelque chose que joue en PostgreSQL.
 */
class TASK1667TransferDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $auteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->for($this->organization)->create(['is_admin' => true]);
        $this->auteur = User::factory()->for($this->organization)->create();
    }

    private function article(?User $de = null, string $titre = 'Un article'): BlogPost
    {
        return BlogPost::create([
            'user_id' => ($de ?? $this->auteur)->id,
            'organization_id' => $this->organization->id,
            'title' => $titre,
            'slug' => Str::slug($titre).'-'.Str::random(6),
            'content' => 'Contenu de test.',
            'status' => 'published',
        ]);
    }

    private function publicationDeFil(?User $de = null): FeedPost
    {
        return FeedPost::create([
            'user_id' => ($de ?? $this->auteur)->id,
            'organization_id' => $this->organization->id,
            'content' => 'Une publication de fil.',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function transfers(?User $sur = null): array
    {
        return $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $sur ?? $this->auteur))
            ->assertOk()
            ->json('transfers');
    }

    // ───────────────────── le detail du payload ─────────────────────

    public function test_le_detail_nomme_chaque_famille_avec_son_compte(): void
    {
        $this->article(titre: 'Premier');
        $this->article(titre: 'Second');
        Service::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
        ]);

        $transfers = collect($this->transfers())->keyBy('key');

        $this->assertSame(2, $transfers['blog_posts']['count']);
        $this->assertSame(1, $transfers['services']['count']);
        $this->assertFalse($transfers->has('service_requests'), 'une famille vide ne doit pas sortir');
        $this->assertFalse($transfers->has('feed_posts'), 'une famille vide ne doit pas sortir');
    }

    public function test_la_somme_affichee_egale_toujours_le_detail(): void
    {
        $this->article();
        $this->article(titre: 'Autre');
        Service::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
        ]);
        ServiceRequest::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
        ]);

        $payload = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->auteur))
            ->assertOk()
            ->json();

        // Si ces deux nombres divergeaient, la modal annoncerait un total en
        // listant autre chose : c'est la garantie la plus importante du fichier.
        $this->assertSame(
            $payload['transfer_total'],
            collect($payload['transfers'])->sum('count')
        );
    }

    public function test_chaque_famille_pointe_vers_son_ecran(): void
    {
        $this->article();
        Service::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
        ]);
        ServiceRequest::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
        ]);

        $transfers = collect($this->transfers())->keyBy('key');

        foreach (['blog_posts' => 'admin.blog', 'services' => 'admin.services', 'service_requests' => 'admin.requests'] as $cle => $route) {
            $this->assertStringContainsString(
                'user_id='.$this->auteur->id,
                $transfers[$cle]['url'],
                "la famille {$cle} doit mener a son ecran, borne sur l'auteur"
            );
            $this->assertStringStartsWith(route($route), $transfers[$cle]['url']);
        }
    }

    public function test_une_famille_sans_ecran_sort_sans_lien(): void
    {
        $this->publicationDeFil();

        $transfers = collect($this->transfers())->keyBy('key');

        // `feed_posts` n'a aucun ecran d'administration dans ce depot : le
        // compte doit sortir, le lien NON.
        $this->assertSame(1, $transfers['feed_posts']['count']);
        $this->assertArrayNotHasKey('url', $transfers['feed_posts']);
    }

    public function test_sans_contenu_le_detail_est_vide(): void
    {
        $this->assertSame([], $this->transfers());
    }

    public function test_un_membre_ordinaire_n_obtient_pas_le_detail(): void
    {
        $this->actingAs($this->auteur)
            ->getJson(route('admin.users.delete-precheck', $this->auteur))
            ->assertForbidden();
    }

    // ───────────────────── le filtre sur les ecrans ─────────────────────

    public function test_le_filtre_borne_le_blog_a_un_auteur(): void
    {
        $sien = $this->article(titre: 'Article de la personne visee');
        $autre = $this->article(User::factory()->for($this->organization)->create(), 'Article de quelqu un d autre');

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.blog', ['organization_id' => $this->organization->id, 'user_id' => $this->auteur->id]))
            ->assertOk();

        $reponse->assertSee($sien->title, false);
        $reponse->assertDontSee($autre->title, false);
    }

    public function test_le_filtre_borne_les_services_a_un_auteur(): void
    {
        $sien = Service::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
            'title' => 'Service de la personne visee',
        ]);
        $autre = Service::factory()->create([
            'user_id' => User::factory()->for($this->organization)->create()->id,
            'organization_id' => $this->organization->id,
            'title' => 'Service de quelqu un d autre',
        ]);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.services', ['organization_id' => $this->organization->id, 'user_id' => $this->auteur->id]))
            ->assertOk();

        $reponse->assertSee($sien->title, false);
        $reponse->assertDontSee($autre->title, false);
    }

    public function test_le_filtre_borne_les_demandes_a_un_auteur(): void
    {
        $sienne = ServiceRequest::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
            'title' => 'Demande de la personne visee',
        ]);
        $autre = ServiceRequest::factory()->create([
            'user_id' => User::factory()->for($this->organization)->create()->id,
            'organization_id' => $this->organization->id,
            'title' => 'Demande de quelqu un d autre',
        ]);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.requests', ['organization_id' => $this->organization->id, 'user_id' => $this->auteur->id]))
            ->assertOk();

        $reponse->assertSee($sienne->title, false);
        $reponse->assertDontSee($autre->title, false);
    }

    public function test_un_uuid_malforme_ne_leve_pas_d_erreur_et_ne_rend_rien(): void
    {
        $present = $this->article(titre: 'Article present');

        // En PostgreSQL une valeur libre sur une colonne `uuid` leverait 22P02.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.blog', ['organization_id' => $this->organization->id, 'user_id' => 'pas-un-uuid']))
            ->assertOk()
            ->assertDontSee($present->title, false);
    }

    public function test_un_uuid_inconnu_rend_une_liste_vide(): void
    {
        $present = $this->article(titre: 'Article present');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.blog', ['organization_id' => $this->organization->id, 'user_id' => (string) Str::uuid()]))
            ->assertOk()
            ->assertDontSee($present->title, false);
    }

    public function test_le_filtre_de_titre_reste_utilisable_avec_celui_d_auteur(): void
    {
        $cherche = $this->article(titre: 'Zebre');
        $autreTitre = $this->article(titre: 'Lampadaire');

        // `search` cherche un TITRE : le detourner en pseudo-filtre d'identite
        // rendrait les deux usages impossibles a distinguer.
        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.blog', [
                'organization_id' => $this->organization->id,
                'user_id' => $this->auteur->id,
                'search' => 'Zebre',
            ]))
            ->assertOk();

        $reponse->assertSee($cherche->title, false);
        $reponse->assertDontSee($autreTitre->title, false);
    }

    public function test_le_filtre_est_reporte_en_champ_cache_sinon_il_s_elargit_en_silence(): void
    {
        $this->article();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.blog', ['organization_id' => $this->organization->id, 'user_id' => $this->auteur->id]))
            ->assertOk()
            ->assertSee('<input type="hidden" name="user_id" value="'.$this->auteur->id.'">', false);
    }

    public function test_une_liste_bornee_nomme_la_personne_a_l_ecran(): void
    {
        $this->article();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.blog', ['organization_id' => $this->organization->id, 'user_id' => $this->auteur->id]))
            ->assertOk()
            ->assertSee($this->auteur->full_name, false);
    }

    public function test_sans_filtre_aucun_champ_cache_ni_banniere(): void
    {
        $this->article();

        $this->actingAs($this->superAdmin)
            ->get(route('admin.blog', ['organization_id' => $this->organization->id]))
            ->assertOk()
            ->assertDontSee('name="user_id"', false);
    }

    // ──────────── les liens des blocages (points, Boucles) ────────────

    private function ecritureDePoints(?User $sur = null, string $raison = 'exchange_earned'): void
    {
        DB::table('point_ledger')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => ($sur ?? $this->auteur)->id,
            'organization_id' => $this->organization->id,
            'delta' => 10,
            // Surtout pas `welcome_bonus` : T1638 l'a rendu RESOLVABLE.
            'reason' => $raison,
            'created_at' => now(),
        ]);
    }

    /**
     * Le corps du tableau du grand livre, isole du reste de la page.
     *
     * Le `select` de choix du membre liste TOUS les membres : une assertion
     * `assertDontSee($autre->full_name)` sur la page entiere echouerait donc
     * meme avec un filtre parfaitement correct. Ce qu'il faut regarder, ce sont
     * les LIGNES.
     */
    private function corpsDuTableau(string $html): string
    {
        $debut = strpos($html, '<tbody');
        $fin = strpos($html, '</tbody>', $debut ?: 0);

        return $debut === false || $fin === false
            ? $html
            : substr($html, $debut, $fin - $debut);
    }

    public function test_le_blocage_des_points_mene_au_grand_livre(): void
    {
        $this->ecritureDePoints();

        $blocs = collect($this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->auteur))
            ->assertOk()
            ->json('blocks'));

        $bloc = $blocs->first(fn (array $b) => str_contains($b['message'], 'point')
            || str_contains($b['message'], 'grand livre'));

        $this->assertNotNull($bloc, 'premisse : le grand livre bloque bien');
        $this->assertCount(1, $bloc['links'], 'une seule destination suffit');
        $this->assertStringContainsString('user_id='.$this->auteur->id, $bloc['links'][0]['url']);
        $this->assertStringStartsWith(route('admin.points'), $bloc['links'][0]['url']);
    }

    public function test_le_grand_livre_est_borne_sur_la_personne(): void
    {
        $this->ecritureDePoints();
        $autre = User::factory()->for($this->organization)->create();
        $this->ecritureDePoints($autre, 'exchange_spent');

        $corps = $this->corpsDuTableau($this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id, 'user_id' => $this->auteur->id]))
            ->assertOk()
            ->getContent());

        $this->assertStringContainsString($this->auteur->full_name, $corps);
        $this->assertStringNotContainsString($autre->full_name, $corps);
    }

    public function test_le_grand_livre_ne_leve_pas_d_erreur_sur_un_uuid_malforme(): void
    {
        $this->ecritureDePoints();

        $corps = $this->corpsDuTableau($this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id, 'user_id' => 'pas-un-uuid']))
            ->assertOk()
            ->getContent());

        $this->assertStringNotContainsString($this->auteur->full_name, $corps);
    }

    public function test_le_grand_livre_est_refuse_a_un_membre_ordinaire(): void
    {
        $this->actingAs($this->auteur)->get(route('admin.points'))->assertForbidden();
    }

    public function test_le_grand_livre_ne_propose_aucune_suppression(): void
    {
        $this->ecritureDePoints();

        // Lecture seule a dessein : un historique comptable ne se supprime pas,
        // et c'est precisement pour cela qu'il bloque.
        //
        // La garantie est verifiee au niveau du ROUTAGE, pas du HTML : le
        // gabarit d'administration contient de toute facon des formulaires POST
        // (deconnexion), et chercher « method=POST » dans la page rendait le
        // test faux sans rien prouver.
        // Les ECRITURES restent immuables : aucune route ne les edite ni ne les
        // supprime. Corriger un solde se fait en AJOUTANT une ecriture, via la
        // primitive existante — l'historique n'est jamais reecrit.
        $this->assertFalse(Route::has('admin.points.destroy'));
        $this->assertFalse(Route::has('admin.points.update'));
        $this->assertFalse(Route::has('admin.points.store'));

        $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id]))
            ->assertOk();
    }

    public function test_le_grand_livre_est_atteignable_depuis_le_rail_gauche(): void
    {
        // Rappel T1656 : une capacite non exposee est une capacite ABSENTE.
        // L'ecran peut etre livre et teste sans qu'aucune poignee n'y mene.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.points'), false)
            ->assertSee(__('admin.points_nav'), false);
    }

    public function test_le_choix_du_membre_est_offert_a_l_ecran(): void
    {
        $this->ecritureDePoints();

        // Sans ce choix, corriger un solde exigeait de fabriquer l'URL
        // `?user_id=...` a la main : la capacite existait sans poignee.
        // Rappel T1656 : une capacite non exposee est une capacite ABSENTE.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id]))
            ->assertOk()
            ->assertSee('name="user_id"', false)
            ->assertSee($this->auteur->full_name, false);
    }

    public function test_le_choix_du_membre_conserve_la_personne_selectionnee(): void
    {
        $this->ecritureDePoints();

        // Le `select` remplace l'ancien champ cache : il doit porter le report
        // du filtre, sinon un changement d'organisation ELARGIT la liste.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id, 'user_id' => $this->auteur->id]))
            ->assertOk()
            ->assertSee('value="'.$this->auteur->id.'" selected', false);
    }

    // ──────────── corriger un solde SANS reecrire l'historique ────────────

    public function test_la_correction_ajoute_une_ecriture_et_ne_detruit_rien(): void
    {
        $this->auteur->update(['points_balance' => 100]);
        $this->ecritureDePoints();
        $avant = DB::table('point_ledger')->where('user_id', $this->auteur->id)->count();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.adjust-points', $this->auteur), [
                'delta' => -40,
                'reason' => 'correction_test',
            ])->assertRedirect();

        // Une ecriture de PLUS, aucune de moins : le passe n'est pas reecrit.
        $this->assertSame($avant + 1, DB::table('point_ledger')->where('user_id', $this->auteur->id)->count());
        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $this->auteur->id,
            'delta' => -40,
            'reason' => 'correction_test',
        ]);
        $this->assertSame(60, $this->auteur->fresh()->points_balance);
    }

    public function test_le_motif_par_defaut_reste_adjustment(): void
    {
        $this->auteur->update(['points_balance' => 10]);

        // Les appels existants ne fournissent pas de motif : leur comportement
        // ne doit pas changer.
        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.adjust-points', $this->auteur), ['delta' => 5]);

        $this->assertDatabaseHas('point_ledger', [
            'user_id' => $this->auteur->id,
            'delta' => 5,
            'reason' => 'adjustment',
        ]);
    }

    public function test_la_remise_a_zero_est_une_ecriture_opposee_au_solde(): void
    {
        $this->auteur->update(['points_balance' => 110]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id, 'user_id' => $this->auteur->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('value="-110"', $html);
        $this->assertStringContainsString('value="admin_reset"', $html);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.adjust-points', $this->auteur), ['delta' => -110, 'reason' => 'admin_reset']);

        $this->assertSame(0, $this->auteur->fresh()->points_balance);
        $this->assertDatabaseHas('point_ledger', ['user_id' => $this->auteur->id, 'reason' => 'admin_reset']);
    }

    public function test_aucune_remise_a_zero_quand_le_solde_est_deja_nul(): void
    {
        $this->auteur->update(['points_balance' => 0]);

        // Un `delta` de 0 est refuse par la validation : un bouton qui ne peut
        // qu'echouer est pire que pas de bouton.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id, 'user_id' => $this->auteur->id]))
            ->assertOk()
            ->assertDontSee('value="admin_reset"', false);
    }

    public function test_aucun_panneau_de_correction_sans_personne_ciblee(): void
    {
        $this->ecritureDePoints();

        // Sans destinataire designe, « corriger le solde » n'a pas de sens.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id]))
            ->assertOk()
            ->assertDontSee('name="delta"', false);
    }

    // ──────────── le tri ────────────

    public function test_le_tri_sur_le_mouvement_ordonne_les_ecritures(): void
    {
        DB::table('point_ledger')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $this->auteur->id, 'organization_id' => $this->organization->id, 'delta' => 5, 'reason' => 'petit', 'created_at' => now()->subDay()],
            ['id' => (string) Str::uuid(), 'user_id' => $this->auteur->id, 'organization_id' => $this->organization->id, 'delta' => 90, 'reason' => 'grand', 'created_at' => now()],
        ]);

        $asc = $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id, 'sort' => 'delta', 'direction' => 'asc']))
            ->assertOk()->getContent();

        $this->assertLessThan(
            strpos($asc, 'grand'),
            strpos($asc, 'petit'),
            'en ordre croissant, le plus petit mouvement vient en premier'
        );

        $desc = $this->actingAs($this->superAdmin)
            ->get(route('admin.points', ['organization_id' => $this->organization->id, 'sort' => 'delta', 'direction' => 'desc']))
            ->assertOk()->getContent();

        $this->assertLessThan(strpos($desc, 'petit'), strpos($desc, 'grand'));
    }

    public function test_une_colonne_hors_liste_blanche_retombe_sur_la_date(): void
    {
        // Le premier jet de ce test n'assertait qu'un code 200 sur une valeur
        // absurde (`'user_id; drop table'`). Il etait VERT avec ou sans la liste
        // blanche : SQLite accepte l'identifiant cite sans broncher, donc le
        // test ne prouvait rien.
        //
        // On prend donc une colonne qui EXISTE mais n'est pas proposee a
        // l'ecran : c'est le risque reel. Avec la liste blanche, le tri retombe
        // sur la date ; sans elle, il obeirait.
        // Les identifiants sont ordonnes dans le temps (UUID v7). On fait donc
        // DIVERGER les deux ordres : le compte cree en PREMIER (id le plus petit)
        // porte l'ecriture la plus RECENTE. Sans cela, trier par `user_id`
        // donnerait le meme resultat que trier par date, et le test serait vert
        // par coincidence.
        $premierCree = User::factory()->for($this->organization)->create();
        $secondCree = User::factory()->for($this->organization)->create();

        DB::table('point_ledger')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $premierCree->id, 'organization_id' => $this->organization->id, 'delta' => 5, 'reason' => 'recente', 'created_at' => now()],
            ['id' => (string) Str::uuid(), 'user_id' => $secondCree->id, 'organization_id' => $this->organization->id, 'delta' => 5, 'reason' => 'ancienne', 'created_at' => now()->subDay()],
        ]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.points', [
                'organization_id' => $this->organization->id,
                'sort' => 'user_id',
                'direction' => 'desc',
            ]))
            ->assertOk()
            ->getContent();

        // Avec la liste blanche : repli sur `created_at` descendant -> la plus
        // RECENTE d'abord. Sans elle : `user_id` descendant -> le second compte
        // cree d'abord, donc la plus ANCIENNE. Les deux ordres s'opposent.
        $this->assertLessThan(
            strpos($html, 'ancienne'),
            strpos($html, 'recente'),
            'une colonne hors liste blanche doit etre ignoree au profit de la date'
        );
    }

    // ───────────────────── la modal ─────────────────────

    public function test_la_page_de_suppression_rend_le_detail(): void
    {
        // TASK-1668 — la modal rendait le detail par Alpine : seule la presence
        // du gabarit etait verifiable. La page rend cote serveur, donc on exige
        // le CONTENU — strictement plus fort.
        $this->article();
        Service::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.users.delete-page', $this->auteur))
            ->assertOk()
            ->assertSee(__('admin.user_delete_transfer_family_blog_posts'), false)
            ->assertSee(__('admin.user_delete_transfer_family_services'), false)
            ->assertSee('user_id='.$this->auteur->id, false);
    }

    public function test_le_detail_est_rendu_meme_quand_la_suppression_est_bloquee(): void
    {
        $this->article();
        $this->ecritureDePoints();

        // Un compte bloque peut avoir des contenus, et c'est AVANT de lever les
        // blocages que l'admin a besoin de le savoir. TASK-1668 : la page rendant
        // cote serveur, ce test observe desormais le resultat et non le gabarit —
        // il ne peut plus etre vert sur une condition neutralisee.
        $page = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.delete-page', $this->auteur))
            ->assertOk();

        $page->assertSee(__('admin.user_delete_blocked_title'), false);
        $page->assertSee(__('admin.user_delete_transfer_blocked_title'), false);
        $page->assertSee(__('admin.user_delete_transfer_family_blog_posts'), false);
    }

    public function test_le_detail_reste_calcule_meme_quand_un_blocage_existe(): void
    {
        $this->article();

        // Un blocage dur : ecriture au grand livre des points.
        DB::table('point_ledger')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
            'delta' => 10,
            // Surtout pas `welcome_bonus` : T1638 l'a rendu RESOLVABLE, donc il
            // ne bloque plus rien. Une ecriture d'echange, elle, bloque.
            'reason' => 'exchange_earned',
            'created_at' => now(),
        ]);

        $payload = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->auteur))
            ->assertOk()
            ->json();

        $this->assertNotEmpty($payload['blocks'], 'premisse : le compte est bien bloque');
        $this->assertSame(1, collect($payload['transfers'])->firstWhere('key', 'blog_posts')['count']);
    }
}
