<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\AssignData\DatasetClassification;
use App\Support\AssignData\DatasetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1631 — l'outil `/admin/outils/assign-data` reconstruit.
 *
 * Le bug de depart : sur la ligne « Utilisateurs », cliquer sur le compteur
 * « 14 » n'affichait rien, et « Voir detail » non plus. La cause n'etait pas
 * serveur — la route rendait 200 avec ses lignes. Les compteurs etaient des
 * `<button>` qui fabriquaient une URL en JavaScript et l'ouvraient dans une
 * popup dimensionnee, derriere un `return` place plus haut dans le meme bloc
 * pour une raison etrangere. Rien de tout cela n'etait testable.
 *
 * D'ou la forme de ces tests : ils verifient que la page contient de VRAIS
 * liens, vers la route canonique, avec le bon filtre — et que le nombre
 * affiche est bien celui qu'on obtient en suivant le lien.
 */
class TASK1631AssignDataToolTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();
        DatasetRegistry::flushCache();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true, 'slug' => 'org-1631']);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => false]);
    }

    // ── A. Le bug des Utilisateurs ─────────────────────────────────────────

    public function test_the_table_counts_the_fourteen_users_without_organization(): void
    {
        $this->usersSansOrganisation(14);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data'))
            ->assertOk()
            ->getContent();

        // Le compteur est un LIEN vers la route canonique, filtre compris.
        $this->assertStringContainsString(
            route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'without_organization']),
            $html
        );
    }

    /**
     * Le coeur du bug : suivre le compteur doit montrer EXACTEMENT les lignes
     * qui le composent, jamais une page vide.
     */
    public function test_following_the_counter_shows_exactly_those_fourteen_rows(): void
    {
        $ids = $this->usersSansOrganisation(14);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'without_organization']))
            ->assertOk();

        $rows = $reponse->viewData('rows');

        $this->assertSame(14, $rows->total());
        $this->assertSame(14, $reponse->viewData('without_organization'));

        // Et ce sont bien CES lignes-la.
        $affiches = collect($rows->items())->pluck('id')->all();
        sort($affiches);
        sort($ids);
        $this->assertSame($ids, $affiches);
    }

    public function test_the_detail_page_is_never_empty_when_the_count_is_positive(): void
    {
        $this->usersSansOrganisation(3);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'without_organization']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('admin.assign_data.detail_empty'), $html);
    }

    // ── B. « Voir detail » pour chaque dataset ─────────────────────────────

    public function test_every_declared_dataset_has_a_working_detail_route(): void
    {
        foreach (app(DatasetRegistry::class)->all() as $dataset) {
            $this->actingAs($this->superAdmin)
                ->get(route('admin.outils.assign-data.detail', ['dataset' => $dataset->key]))
                ->assertOk();
        }
    }

    public function test_an_unknown_dataset_is_a_clean_404(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'nexiste-pas']))
            ->assertNotFound();
    }

    public function test_the_three_filters_are_offered_and_honoured(): void
    {
        $this->usersSansOrganisation(4);
        // Deux comptes rattaches existent deja (superAdmin, membre).

        $sans = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'without_organization']))
            ->assertOk();
        $avec = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'with_organization']))
            ->assertOk();
        $tous = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'all']))
            ->assertOk();

        $this->assertSame(4, $sans->viewData('rows')->total());
        $this->assertSame(2, $avec->viewData('rows')->total());
        $this->assertSame(6, $tous->viewData('rows')->total());
    }

    public function test_an_unknown_filter_falls_back_to_all_instead_of_showing_nothing(): void
    {
        $this->usersSansOrganisation(2);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'n-importe-quoi']))
            ->assertOk();

        $this->assertSame('all', $reponse->viewData('filter'));
        $this->assertSame(4, $reponse->viewData('rows')->total());
    }

    public function test_the_detail_paginates(): void
    {
        $this->usersSansOrganisation(60);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'without_organization']))
            ->assertOk();

        $rows = $reponse->viewData('rows');

        $this->assertSame(60, $rows->total());
        // 50 par page : le compteur reste juste, la page reste lisible.
        $this->assertCount(50, $rows->items());
        $this->assertTrue($rows->hasMorePages());
    }

    /**
     * La liste BLANCHE : seules les colonnes declarees sont rendues.
     *
     * L'ancien ecran faisait `toArray()` puis retirait treize noms connus —
     * tout le reste passait, y compris une colonne ajoutee plus tard. Ici,
     * `password` et `remember_token` ne sont pas declares, donc jamais rendus.
     */
    public function test_only_whitelisted_columns_reach_the_page(): void
    {
        $secret = Str::random(40);
        $user = User::factory()->create(['organization_id' => null, 'remember_token' => $secret]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'without_organization']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($user->email, $html);
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringNotContainsString($user->password, $html);
    }

    // ── C. Les donnees globales ────────────────────────────────────────────

    public function test_a_global_dataset_offers_no_assignment(): void
    {
        $registry = app(DatasetRegistry::class);

        $categories = $registry->find('categories');
        $this->assertSame(DatasetClassification::GlobalNullValid, $categories->classification);
        $this->assertFalse($categories->isAssignable());

        DB::table('categories')->insert([
            'id' => (string) Str::uuid(), 'name_b2c' => 'Globale', 'name_b2b' => 'Globale',
            'slug' => 'globale-1631', 'organization_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data'))
            ->assertOk()
            ->getContent();

        // Le compteur existe — c'est un diagnostic — mais aucune action n'est
        // proposee : rattacher une categorie globale la retirerait de toutes
        // les autres Organizations.
        $this->assertStringContainsString(__('admin.assign_data.classification_global_null_valid'), $html);
        $this->assertStringNotContainsString(
            __('admin.assign_data.action_assign', ['count' => 1]),
            $html
        );
    }

    /**
     * La garde SERVEUR. L'absence de bouton n'en est pas une.
     */
    public function test_a_forged_post_cannot_assign_a_global_dataset(): void
    {
        $id = (string) Str::uuid();
        DB::table('categories')->insert([
            'id' => $id, 'name_b2c' => 'Globale', 'name_b2b' => 'Globale',
            'slug' => 'globale-forge', 'organization_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'categories',
                'organization_id' => $this->org->id,
                'confirm' => '1',
            ])
            ->assertRedirect(route('admin.outils.assign-data'))
            ->assertSessionHas('error');

        $this->assertNull(DB::table('categories')->where('id', $id)->value('organization_id'));
    }

    public function test_a_forged_post_cannot_assign_a_history_or_diagnostic_dataset(): void
    {
        foreach (['ai_interactions', 'badge_user'] as $key) {
            $this->actingAs($this->superAdmin)
                ->post(route('admin.outils.assign-data.assign'), [
                    'dataset' => $key,
                    'organization_id' => $this->org->id,
                    'confirm' => '1',
                ])
                ->assertSessionHas('error');
        }
    }

    public function test_the_preview_also_refuses_a_non_assignable_dataset(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.preview'), [
                'dataset' => 'categories',
                'organization_id' => $this->org->id,
            ])
            ->assertRedirect(route('admin.outils.assign-data'))
            ->assertSessionHas('error');
    }

    // ── D. L'affectation d'un dataset reellement assignable ───────────────

    public function test_the_preview_announces_the_exact_count_without_writing(): void
    {
        $this->usersSansOrganisation(5);

        $reponse = $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.preview'), [
                'dataset' => 'users',
                'organization_id' => $this->org->id,
            ])
            ->assertOk();

        $this->assertSame(5, $reponse->viewData('count'));
        // Rien n'a bouge.
        $this->assertSame(5, DB::table('users')->whereNull('organization_id')->count());
    }

    public function test_assignment_writes_only_the_rows_without_organization(): void
    {
        $orphelins = $this->usersSansOrganisation(5);
        $autre = Organization::factory()->create(['is_active' => true, 'slug' => 'autre-1631']);
        $dejaAilleurs = User::factory()->create(['organization_id' => $autre->id]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $this->org->id,
                'confirm' => '1',
                'confirmation' => 'REASSIGN USERS',
            ])
            ->assertRedirect(route('admin.outils.assign-data'))
            ->assertSessionHas('success');

        foreach ($orphelins as $id) {
            $this->assertSame($this->org->id, DB::table('users')->where('id', $id)->value('organization_id'));
        }

        // La ligne deja rattachee ailleurs n'est PAS touchee. L'ancien UPDATE
        // n'avait aucun `whereNull` et reecrivait tout le dataset.
        $this->assertSame($autre->id, DB::table('users')->where('id', $dejaAilleurs->id)->value('organization_id'));
    }

    public function test_assignment_touches_no_other_column(): void
    {
        $user = User::factory()->create(['organization_id' => null]);
        $avant = (array) DB::table('users')->where('id', $user->id)->first();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $this->org->id,
                'confirm' => '1',
                'confirmation' => 'REASSIGN USERS',
            ])
            ->assertRedirect();

        $apres = (array) DB::table('users')->where('id', $user->id)->first();

        unset($avant['organization_id'], $apres['organization_id']);
        $this->assertSame($avant, $apres, 'Seule `organization_id` doit etre ecrite.');
    }

    public function test_a_critical_dataset_demands_the_typed_confirmation(): void
    {
        $this->usersSansOrganisation(3);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $this->org->id,
                'confirm' => '1',
                // `confirmation` absente.
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(3, DB::table('users')->whereNull('organization_id')->count());
    }

    public function test_assignment_refuses_without_the_confirmation_checkbox(): void
    {
        $this->usersSansOrganisation(3);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $this->org->id,
            ])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(3, DB::table('users')->whereNull('organization_id')->count());
    }

    public function test_a_non_critical_assignable_dataset_works_without_the_typed_phrase(): void
    {
        DB::table('tags')->insert([
            'id' => (string) Str::uuid(), 'name' => 'Legacy', 'slug' => 'legacy-1631',
            'organization_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'tags',
                'organization_id' => $this->org->id,
                'confirm' => '1',
            ])
            ->assertSessionHas('success');

        $this->assertSame(0, DB::table('tags')->whereNull('organization_id')->count());
    }

    // ── F. Regressions reprises de l'ancien fichier de tests ─────────────
    //
    // `AdminOutilsAssignDataTest` mesurait l'API disparue (route `.do`,
    // `datasets[]`, filtres `in_org`). Chacun de ses contrats est repris ici
    // sur la nouvelle API, pour qu'aucune garde ne parte avec le fichier.

    public function test_a_wrong_confirmation_phrase_is_refused(): void
    {
        $this->usersSansOrganisation(3);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => $this->org->id,
                'confirm' => '1',
                'confirmation' => 'reassign users',
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame(3, DB::table('users')->whereNull('organization_id')->count());
    }

    public function test_assignment_requires_a_dataset_and_an_existing_organization(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), ['confirm' => '1'])
            ->assertSessionHasErrors(['dataset', 'organization_id']);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.assign-data.assign'), [
                'dataset' => 'users',
                'organization_id' => (string) Str::uuid(),
                'confirm' => '1',
            ])
            ->assertSessionHasErrors('organization_id');
    }

    /**
     * Aucun libelle brut a l'ecran.
     *
     * Le registre nomme ses datasets par cle de traduction ; une cle absente
     * de `lang/` s'afficherait telle quelle (« admin.assign_data.dataset_x »),
     * ce qu'aucun test d'existence de page ne verrait.
     */
    public function test_no_raw_translation_key_reaches_the_page(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('admin.assign_data.', $html);

        foreach (app(DatasetRegistry::class)->all() as $dataset) {
            $this->assertNotSame($dataset->labelKey(), __($dataset->labelKey()), "Libelle manquant pour {$dataset->key}.");
            $this->assertNotSame($dataset->descriptionKey(), __($dataset->descriptionKey()), "Description manquante pour {$dataset->key}.");
        }
    }

    public function test_the_detail_page_writes_nothing(): void
    {
        $this->usersSansOrganisation(4);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.assign-data.detail', ['dataset' => 'users', 'filter' => 'without_organization']))
            ->assertOk();

        // Une vue de lecture ne rattache rien au passage.
        $this->assertSame(4, DB::table('users')->whereNull('organization_id')->count());
    }

    // ── E. Autorisation ────────────────────────────────────────────────────

    public function test_a_guest_is_refused(): void
    {
        $this->get(route('admin.outils.assign-data'))->assertRedirect(route('login'));
    }

    public function test_a_plain_member_is_refused(): void
    {
        $this->actingAs($this->membre)->get(route('admin.outils.assign-data'))->assertForbidden();
        $this->actingAs($this->membre)->get(route('admin.outils.assign-data.detail', ['dataset' => 'users']))->assertForbidden();
        $this->actingAs($this->membre)->post(route('admin.outils.assign-data.assign'), [
            'dataset' => 'users', 'organization_id' => $this->org->id, 'confirm' => '1',
        ])->assertForbidden();
    }

    /**
     * Un OrgAdmin administre SON Organization ; cet outil voit toute la
     * plateforme. `OrgAdminMiddleware` accepte `organizations.admin_id`, le
     * middleware `admin` exige `users.is_admin` : ce sont deux notions
     * distinctes, et ce test le prouve plutot que de s'en remettre au fait
     * que le lien est cache.
     */
    public function test_an_org_admin_who_is_not_platform_admin_is_refused(): void
    {
        $orgAdmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => false]);
        $this->org->update(['admin_id' => $orgAdmin->id]);

        $this->actingAs($orgAdmin)->get(route('admin.outils.assign-data'))->assertForbidden();
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function usersSansOrganisation(int $combien): array
    {
        $ids = [];

        for ($i = 0; $i < $combien; $i++) {
            $user = User::factory()->create();
            // `HasOrganizationId` remplit la colonne a la creation : on la
            // vide ensuite, ce qui reproduit exactement l'etat legacy.
            DB::table('users')->where('id', $user->id)->update(['organization_id' => null]);
            $ids[] = $user->id;
        }

        return $ids;
    }
}
