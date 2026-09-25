<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Users\UserDeletionExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1640 — supprimer un compte depuis la liste, sans detour et sans recopie.
 *
 * ## Ce que ces tests mesurent, et ou
 *
 * Le bouton et la modal vivent dans `admin/users.blade.php`, donc les assertions
 * lisent le HTML REELLEMENT rendu. Une assertion sur une cle de langue ne
 * prouverait rien : `__()` rend la cle brute quand la traduction manque, et la
 * cle brute n'apparait jamais dans le HTML — le test serait vert par construction.
 * Les libelles sont donc compares a `__()` resolu, et l'absence de l'ancien champ
 * est verifiee sur son attribut `name`, pas sur un texte.
 *
 * ## Le cout au rendu
 *
 * Le dernier test compte les requetes de `/admin/users`. C'est la mesure qui
 * justifie l'architecture retenue : le `precheck()` est demande AU CLIC, pour un
 * seul compte, et non pour les 20 lignes de la page.
 */
class TASK1640UserDeleteFromListTest extends TestCase
{
    private Organization $organization;

    private User $superAdmin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->for($this->organization)->create(['is_admin' => true]);
        $this->target = User::factory()->for($this->organization)->create([
            'first_name' => 'Jean',
            'name' => 'Dupont',
        ]);
    }

    // =====================================================================
    // 1/2/3 — la liste porte l'action, et l'ancien detour a disparu
    // =====================================================================

    public function test_la_liste_porte_un_bouton_supprimer_par_ligne(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();

        $this->assertStringContainsString(__('admin.user_delete_row_button'), $html);

        // Le bouton nomme le compte de la ligne : c'est ce qui permet a UNE modal
        // partagee de savoir qui elle supprime.
        $this->assertStringContainsString("openDelete('{$this->target->id}'", $html);
    }

    public function test_la_liste_n_impose_plus_de_passer_par_edit_ni_par_la_preview(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();

        // La modal poste directement sur la route destructive.
        $this->assertStringContainsString(route('admin.users.destroy', ['user' => '__ID__']), $html);

        // Et elle interroge la route de lecture, pas l'ecran de simulation.
        $this->assertStringContainsString(route('admin.users.delete-precheck', ['user' => '__ID__']), $html);
        $this->assertStringNotContainsString(
            route('admin.users.delete-preview', $this->target),
            $html,
            "La liste ne doit plus renvoyer vers l'ecran de simulation pour supprimer."
        );
    }

    public function test_aucun_champ_de_recopie_du_nom_sur_le_chemin_destructif(): void
    {
        $liste = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="confirmation"', $liste);

        // Et le formulaire destructif de l'ecran de simulation ne le porte plus
        // non plus : le champ a ete retire, pas cache. Il n'en reste qu'UN sur
        // cette page, celui de la simulation elle-meme (POST admin.users.delete).
        $preview = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.delete-preview', $this->target))
            ->assertOk()->getContent();

        $this->assertSame(
            1,
            substr_count($preview, 'name="confirmation"'),
            "Seule la SIMULATION garde une recopie du nom ; le chemin destructif n'en a plus."
        );
    }

    // =====================================================================
    // 4/5 — le precheck est demande au clic, pour UN seul compte
    // =====================================================================

    public function test_le_precheck_repond_en_json_pour_le_seul_compte_demande(): void
    {
        $reponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->target))
            ->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'name'],
                'blocks',
                'requires_transfer',
                'transfer_total',
                'transfer_candidates',
                'preview_fingerprint',
            ]);

        $this->assertSame($this->target->id, $reponse->json('user.id'));
        $this->assertSame('Jean Dupont', $reponse->json('user.name'));
        $this->assertSame([], $reponse->json('blocks'));
        $this->assertFalse($reponse->json('requires_transfer'));
    }

    public function test_la_route_de_precheck_n_ecrit_rien(): void
    {
        $mutations = 0;
        DB::listen(function ($q) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete|truncate)\b/i', $q->sql)) {
                $mutations++;
            }
        });

        $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->target))
            ->assertOk();

        $this->assertSame(0, $mutations, 'La route de precheck doit etre en LECTURE SEULE.');
        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    public function test_l_empreinte_rendue_est_celle_que_le_destroy_exige(): void
    {
        $fingerprint = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->target))
            ->json('preview_fingerprint');

        $this->assertNotEmpty($fingerprint);

        // Cette valeur-la, et pas une autre, ouvre la suppression.
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), ['preview_fingerprint' => $fingerprint])
            ->assertRedirect(route('admin.users'));

        $this->assertDatabaseMissing('users', ['id' => $this->target->id]);
    }

    // =====================================================================
    // 6/7/8 — les trois etats de la modal
    // =====================================================================

    public function test_cas_A_suppression_simple_va_au_bout(): void
    {
        $reponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->target));

        $this->assertSame([], $reponse->json('blocks'));
        $this->assertFalse($reponse->json('requires_transfer'));

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), [
                'preview_fingerprint' => $reponse->json('preview_fingerprint'),
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertDatabaseMissing('users', ['id' => $this->target->id]);
    }

    public function test_cas_B_transfert_propose_des_repreneurs_valides(): void
    {
        $repreneur = User::factory()->for($this->organization)->create(['first_name' => 'Alice', 'name' => 'Martin']);
        $banni = User::factory()->for($this->organization)->create(['banned_at' => now()]);
        $etranger = User::factory()->for(Organization::factory()->create())->create();

        $serviceId = $this->insertService($this->target->id, $this->organization->id);

        $reponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->target))
            ->assertOk();

        $this->assertTrue($reponse->json('requires_transfer'));
        $this->assertSame(1, $reponse->json('transfer_total'));

        $ids = collect($reponse->json('transfer_candidates'))->pluck('id')->all();
        $this->assertContains($repreneur->id, $ids);
        $this->assertNotContains($this->target->id, $ids, 'Le compte supprime ne peut pas se transferer a lui-meme.');
        $this->assertNotContains($banni->id, $ids, 'Un compte banni ne peut pas recevoir de contenu.');
        $this->assertNotContains($etranger->id, $ids, 'Un membre d une autre organisation ne doit pas etre propose.');

        // Le nom affiche dans le select est le nom COMPLET, comme partout ailleurs.
        $this->assertContains(
            'Alice Martin',
            collect($reponse->json('transfer_candidates'))->pluck('name')->all()
        );

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), [
                'preview_fingerprint' => $reponse->json('preview_fingerprint'),
                'transfer_to' => $repreneur->id,
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertDatabaseHas('services', ['id' => $serviceId, 'user_id' => $repreneur->id]);
        $this->assertDatabaseMissing('users', ['id' => $this->target->id]);
    }

    public function test_cas_C_un_blocage_rend_un_message_metier_et_aucune_cle_technique(): void
    {
        // Ce membre est responsable de l'Organization : blocage franc.
        $this->organization->update(['admin_id' => $this->target->id]);

        $reponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->target))
            ->assertOk();

        $blocks = $reponse->json('blocks');
        $this->assertNotEmpty($blocks);
        $this->assertSame(__('admin.user_delete.block.orgs_as_admin', ['count' => 1]), $blocks[0]['message']);

        // La cle technique du blocage ne sort PAS du serveur : l'ecran n'en a rien
        // a faire, et elle fuirait le vocabulaire interne dans le navigateur.
        $this->assertArrayNotHasKey('key', $blocks[0]);

        foreach (['organization_id', 'RESTRICT', 'foreign', 'orgs_as_admin', 'constraint'] as $jargon) {
            $this->assertStringNotContainsStringIgnoringCase($jargon, $blocks[0]['message']);
        }

        // Et la suppression reste refusee cote serveur, empreinte fraiche ou non.
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), [
                'preview_fingerprint' => $reponse->json('preview_fingerprint'),
            ])
            ->assertRedirect(route('admin.users.delete-preview', $this->target));

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    // =====================================================================
    // 9 — une empreinte perimee est refusee proprement
    // =====================================================================

    public function test_une_empreinte_perimee_est_refusee(): void
    {
        $fingerprint = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->target))
            ->json('preview_fingerprint');

        // Entre le clic et la confirmation, le membre recoit une propriete.
        $this->insertService($this->target->id, $this->organization->id);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), ['preview_fingerprint' => $fingerprint])
            ->assertRedirect(route('admin.users.delete-preview', $this->target))
            // Le message doit etre celui de la PEREMPTION : sans cette precision le
            // test passerait aussi si la garde de fraicheur etait retiree, puisque
            // « transfert requis » produit la meme redirection.
            ->assertSessionHas('error', __('admin.user_delete.stale'));

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    // =====================================================================
    // 10 — la route de lecture est aussi gardee que la route destructive
    // =====================================================================

    public function test_un_membre_ordinaire_ne_peut_pas_lire_le_precheck(): void
    {
        $membre = User::factory()->for($this->organization)->create();

        $this->actingAs($membre)
            ->getJson(route('admin.users.delete-precheck', $this->target))
            ->assertForbidden();
    }

    public function test_un_invite_ne_peut_pas_lire_le_precheck(): void
    {
        $this->getJson(route('admin.users.delete-precheck', $this->target))->assertUnauthorized();
    }

    public function test_un_membre_ordinaire_ne_voit_aucun_bouton_supprimer(): void
    {
        $membre = User::factory()->for($this->organization)->create();

        // La liste SuperAdmin lui est deja interdite : la garde n'est pas dans la
        // vue, elle est dans le middleware. On le verifie plutot que de le supposer.
        $this->actingAs($membre)->get(route('admin.users'))->assertForbidden();
    }

    public function test_le_superadmin_ne_peut_pas_se_supprimer_lui_meme_depuis_la_liste(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            "openDelete('{$this->superAdmin->id}'",
            $html,
            "La ligne du compte connecte ne doit pas porter de bouton Supprimer."
        );
    }

    // =====================================================================
    // Le cout au rendu — la mesure qui justifie l'architecture
    // =====================================================================

    /**
     * La liste ne doit PAS payer un `precheck()` par ligne.
     *
     * On mesure sur 12 comptes puis on compare a la meme page avec 3 comptes : si
     * un precheck etait calcule par ligne, l'ecart croitrait d'une quinzaine de
     * requetes par compte ajoute. Le seuil n'est donc pas un nombre absolu
     * arbitraire — c'est la PENTE qui est mesuree.
     */
    public function test_la_liste_ne_calcule_aucun_precheck_au_rendu(): void
    {
        $compter = function (): int {
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk();
            DB::flushQueryLog();

            return $n;
        };

        $avec3 = $compter();

        User::factory()->for($this->organization)->count(9)->create();

        $avec12 = $compter();

        // 9 comptes de plus. Un precheck par ligne couterait ~15 requetes chacun,
        // soit plus de 100 requetes d'ecart. Une liste paginee saine ne bouge que
        // de quelques requetes (ou pas du tout).
        $this->assertLessThan(
            30,
            $avec12 - $avec3,
            "Le rendu de la liste grandit de ".($avec12 - $avec3)." requetes pour 9 comptes : "
            ."un precheck est probablement calcule par ligne."
        );
    }

    // =====================================================================
    // Compteurs, fiche membre et responsive
    // =====================================================================

    public function test_le_bandeau_de_compteurs_porte_sur_toute_la_population(): void
    {
        User::factory()->for($this->organization)->count(3)->create(['is_available' => true, 'banned_at' => null]);
        User::factory()->for($this->organization)->count(2)->create(['banned_at' => now()]);

        // Un filtre est applique : les compteurs ne doivent PAS le suivre, sinon
        // ils repondraient « combien en vois-je » au lieu de « combien y en a-t-il ».
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users', ['status' => 'banned']))
            ->assertOk()
            ->getContent();

        $total = User::count();

        $this->assertStringContainsString(__('admin.users_stat_total'), $html);
        $this->assertStringContainsString(__('admin.users_stat_banned'), $html);
        $this->assertStringContainsString(number_format($total, 0, ',', ' '), $html);

        // Le total FILTRE, lui, est affiche a cote du tableau : 2 comptes bannis.
        $this->assertStringContainsString(__('admin.users_results_count', ['count' => 2]), $html);
    }

    public function test_les_compteurs_ne_coutent_qu_une_requete_de_plus(): void
    {
        // La forme agregee est portable ET bornee : cinq `count()` separes
        // auraient coute cinq requetes, et un compteur par ligne bien davantage.
        $n = 0;
        DB::listen(function ($q) use (&$n) {
            if (str_contains($q->sql, 'as disponibles')) { $n++; }
        });

        $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk();

        $this->assertSame(1, $n, 'Les compteurs doivent tenir en UNE seule requete agregee.');
    }

    public function test_la_fiche_membre_rend_des_comptages_et_jamais_de_contenu(): void
    {
        $serviceId = $this->insertService($this->target->id, $this->organization->id);
        // Un titre DISTINCTIF : « Service » seul est un mot present dans le
        // libelle « Services proposes », ce qui ferait echouer l'assertion pour
        // une raison qui n'a rien a voir avec la fuite de contenu.
        DB::table('services')->where('id', $serviceId)->update(['title' => 'Titre confidentiel Zorglub']);

        $reponse = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.profile-summary', $this->target))
            ->assertOk()
            ->assertJsonStructure([
                'identite' => ['name', 'email', 'organization', 'statut', 'is_admin', 'points', 'inscrit_le', 'derniere_connexion', 'note'],
                'contributions',
                'interactions',
                'ia' => ['appels', 'tokens', 'cout', 'interactions', 'shell', 'retours', 'profil_ia'],
            ]);

        $this->assertSame('Jean Dupont', $reponse->json('identite.name'));
        $this->assertSame(1, $reponse->json('contributions.'.__('admin.users_profile_services')));

        // Le service existe, mais son TITRE ne doit pas apparaitre : la fiche rend
        // des comptages, pas le contenu de la personne.
        $titre = DB::table('services')->where('id', $serviceId)->value('title');
        $this->assertStringNotContainsString($titre, $reponse->getContent());
    }

    public function test_la_fiche_membre_n_ecrit_rien_et_reste_reservee_au_superadmin(): void
    {
        $mutations = 0;
        DB::listen(function ($q) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete|truncate)\b/i', $q->sql)) { $mutations++; }
        });

        $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.profile-summary', $this->target))->assertOk();

        $this->assertSame(0, $mutations);

        $membre = User::factory()->for($this->organization)->create();
        $this->actingAs($membre)
            ->getJson(route('admin.users.profile-summary', $this->target))->assertForbidden();
    }

    /**
     * Le cas invite a son propre test : `actingAs()` vaut pour TOUTE la methode,
     * donc un appel « sans authentification » place apres un `actingAs()` reste
     * authentifie et rend 403 au lieu de 401. Le test passait pour la mauvaise
     * raison avant d'etre separe.
     */
    public function test_un_invite_ne_peut_pas_lire_la_fiche_membre(): void
    {
        $this->getJson(route('admin.users.profile-summary', $this->target))->assertUnauthorized();
    }

    public function test_la_liste_porte_un_bouton_fiche_par_ligne(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();

        $this->assertStringContainsString(__('admin.users_profile_button'), $html);
        $this->assertStringContainsString("openProfile('{$this->target->id}'", $html);
    }

    /**
     * Le tableau doit etre ATTEIGNABLE sur telephone.
     *
     * Le defaut corrige n'etait pas cosmetique : `overflow-hidden` COUPAIT les
     * dernieres colonnes — dont Actions — au lieu de laisser defiler. Le contenu
     * etait donc inaccessible, pas seulement a l'etroit.
     */
    public function test_le_tableau_defile_horizontalement_au_lieu_d_etre_coupe(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<div class="bg-white[^"]*overflow-x-auto"/',
            $html,
            "Le conteneur du tableau doit defiler horizontalement, pas couper."
        );

        // La largeur minimale n'est pas cosmetique : sans elle, la colonne Actions
        // est ecrasee a ~130 px sur telephone et empile ses dix liens
        // verticalement. Mesure a 375 px : les lignes passent de 101 px a ~350 px,
        // soit une ligne par ecran. La retirer casserait la page sans rien casser
        // d'autre — d'ou cette garde.
        $this->assertStringContainsString(
            'min-w-[46rem]',
            $html,
            "Le tableau doit garder un plancher de largeur, sinon la colonne Actions etire les lignes."
        );
    }

    /**
     * Chaque colonne masquee doit l'etre en EN-TETE ET en CELLULE, au meme palier.
     *
     * Un `<th>` masque sans son `<td>` decale toutes les colonnes suivantes — un
     * defaut visuel que rien d'autre ne rattraperait, et qui ne se voit pas dans
     * un test qui se contenterait de compter les classes.
     */
    public function test_chaque_colonne_masquee_l_est_en_entete_ET_en_cellule(): void
    {
        $blade = (string) file_get_contents(resource_path('views/admin/users.blade.php'));

        foreach (['sm:table-cell', 'md:table-cell', 'lg:table-cell', 'xl:table-cell'] as $palier) {
            $th = preg_match_all('/<th class="hidden '.preg_quote($palier, '/').'/', $blade);
            $td = preg_match_all('/<td class="hidden '.preg_quote($palier, '/').'/', $blade);

            $this->assertSame($th, $td, "Palier $palier : $th en-tete(s) pour $td cellule(s) — les colonnes seraient decalees.");
        }
    }

    public function test_rien_ne_disparait_sur_mobile_org_et_points_remontent_sous_le_nom(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();

        // Masquer une colonne sans rien mettre a la place rendrait l'ecran inutile
        // sur telephone plutot que simplement etroit.
        $this->assertStringContainsString('md:hidden', $html);
        $this->assertStringContainsString('pts', $html);
    }

    private function insertService(string $userId, string $organizationId): string
    {
        $categoryId = (string) Str::uuid();
        DB::table('categories')->insert([
            'id' => $categoryId,
            'organization_id' => $organizationId,
            'name_b2c' => 'Categorie',
            'name_b2b' => 'Categorie',
            'slug' => 'cat-'.Str::random(10),
            'color' => '#6366f1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (string) Str::uuid();
        DB::table('services')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'category_id' => $categoryId,
            'title' => 'Service',
            'description' => 'Description',
            'delivery_mode' => 'remote',
            'points_cost' => 10,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
