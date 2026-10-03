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

    // ───────────────────── la modal ─────────────────────

    public function test_la_modal_sait_rendre_le_detail(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()
            ->assertSee('transfers', false)
            ->assertSee('famille in transfers', false)
            ->assertSee('famille.url', false);
    }

    public function test_le_detail_est_rendu_meme_quand_la_suppression_est_bloquee(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();

        // Le detail ne doit pas vivre UNIQUEMENT dans la branche « suppression
        // possible » : un compte bloque peut avoir des contenus, et c'est avant
        // de lever les blocages que l'admin a besoin de le savoir.
        $brancheBloquee = substr(
            $html,
            strpos($html, 'blocks.length > 0'),
            strpos($html, 'blocks.length === 0') - strpos($html, 'blocks.length > 0')
        );

        // Asserter la presence du balisage ne suffit PAS : neutraliser la
        // condition (`x-if="false"`) laisse le balisage en place et le test
        // resterait vert. C'est la GARDE qu'il faut exiger.
        $this->assertStringContainsString('x-if="transfers.length > 0"', $brancheBloquee);
        $this->assertStringContainsString('famille in transfers', $brancheBloquee);
        $this->assertStringContainsString(__('admin.user_delete_transfer_blocked_title'), $brancheBloquee);
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
