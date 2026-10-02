<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Users\UserDeletionExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1636 — la surface SuperAdmin : qui peut declencher la suppression reelle,
 * et a quelles conditions.
 *
 * La route de simulation reste une simulation : elle n'a pas ete detournee. La
 * suppression definitive vit sur une action distincte, atteignable seulement
 * apres avoir vu ce qu'elle emporte.
 */
class TASK1636UserDeletionSuperAdminTest extends TestCase
{
    private Organization $organization;

    private User $superAdmin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->for($this->organization)->create(['is_admin' => true]);
        $this->target = User::factory()->for($this->organization)->create(['name' => 'Membre A Supprimer']);
    }

    private function fingerprintFor(User $user): string
    {
        $precheck = app(UserDeletionExecutor::class)->precheck($user);

        $blocks = collect($precheck['blocks'])
            ->map(fn (array $b) => $b['key'].':'.$b['count'])
            ->sort()->values()->all();

        $transferable = $precheck['transferable'];
        ksort($transferable);

        return hash('sha256', json_encode(['blocks' => $blocks, 'transferable' => $transferable]));
    }

    // =====================================================================
    // V — le SuperAdmin supprime reellement
    // =====================================================================

    public function test_le_superadmin_supprime_definitivement_un_compte(): void
    {
        // TASK-1640 : plus aucune recopie de nom. L'empreinte suffit.
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), [
                'preview_fingerprint' => $this->fingerprintFor($this->target),
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertDatabaseMissing('users', ['id' => $this->target->id]);
    }

    public function test_la_route_de_simulation_reste_une_simulation(): void
    {
        // Garde de non-regression : le POST historique ne detruit rien.
        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.delete', $this->target), [
                'confirmation' => $this->target->fullName,
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    /**
     * TASK-1640 — la recopie du nom n'est PLUS une garde sur ce chemin.
     *
     * Ce test disait l'inverse avant : une confirmation erronee bloquait. La
     * regle a ete retiree du controleur, donc le test doit dire ce que le produit
     * fait maintenant — un champ `confirmation` envoye par un vieux formulaire est
     * simplement ignore, il ne fait ni passer ni echouer la suppression.
     */
    public function test_un_champ_de_confirmation_residuel_n_a_plus_aucun_effet(): void
    {
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), [
                'confirmation' => 'Pas le bon nom',
                'preview_fingerprint' => $this->fingerprintFor($this->target),
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertDatabaseMissing('users', ['id' => $this->target->id]);
    }

    /**
     * En revanche l'empreinte, elle, reste OBLIGATOIRE.
     *
     * C'est la garde qui a remplace la recopie : sans elle, la suppression ne
     * part pas du tout.
     */
    public function test_sans_empreinte_la_suppression_est_refusee(): void
    {
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), [])
            ->assertSessionHasErrors('preview_fingerprint');

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    // =====================================================================
    // U — une simulation perimee ne vaut plus rien
    // =====================================================================

    public function test_une_simulation_perimee_est_refusee_avant_toute_mutation(): void
    {
        $fingerprint = $this->fingerprintFor($this->target);

        // Entre l'ecran et la confirmation, le membre recoit une propriete :
        // ce que l'admin a lu ne decrit plus la realite.
        $this->insertService($this->target->id);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), [
                'preview_fingerprint' => $fingerprint,
            ])
            ->assertRedirect(route('admin.users.delete-preview', $this->target))
            // Le message doit etre celui de la PEREMPTION, pas un refus metier :
            // sans cette precision, le test passerait meme si la garde de
            // fraicheur etait retiree, puisque le blocage « transfert requis »
            // produit exactement la meme redirection.
            ->assertSessionHas('error', __('admin.user_delete.stale'));

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    public function test_une_empreinte_inventee_par_le_navigateur_est_refusee(): void
    {
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), [
                'preview_fingerprint' => hash('sha256', 'ce que je veux'),
            ])
            ->assertRedirect(route('admin.users.delete-preview', $this->target))
            ->assertSessionHas('error', __('admin.user_delete.stale'));

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    public function test_un_refus_metier_renvoie_a_la_simulation_sans_rien_detruire(): void
    {
        $this->organization->update(['admin_id' => $this->target->id]);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->target), [
                'preview_fingerprint' => $this->fingerprintFor($this->target),
            ])
            ->assertRedirect(route('admin.users.delete-preview', $this->target))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    // =====================================================================
    // La confirmation demandee a l'ecran est celle que le serveur attend
    // =====================================================================

    /**
     * Ce que l'ecran DEMANDE de taper, extrait du HTML rendu.
     *
     * Le test ne choisit pas cette valeur : il la lit la ou l'admin la lit.
     * Comparer a une valeur prise dans le controleur aurait fait passer le test
     * quoi qu'il arrive — c'est exactement ce qui masquait le defaut.
     */
    private function confirmationDemandeeParLEcran(User $user): string
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.delete-preview', $user))
            ->assertOk()
            ->getContent();

        $gabarit = __('admin.user_delete_confirmation_label', ['name' => '@@NOM@@']);
        [$avant, $apres] = explode('@@NOM@@', $gabarit);

        $trouve = preg_match(
            '/'.preg_quote($avant, '/').'(.+?)'.preg_quote($apres, '/').'/u',
            $html,
            $m
        );

        $this->assertSame(1, $trouve, "L'ecran ne demande aucune confirmation lisible.");

        return html_entity_decode($m[1], ENT_QUOTES);
    }

    public function test_l_ecran_demande_le_nom_complet_prenom_inclus(): void
    {
        $user = User::factory()->for($this->organization)->create([
            'first_name' => 'Jean',
            'name' => 'Dupont',
        ]);

        $this->assertSame('Jean Dupont', $this->confirmationDemandeeParLEcran($user));
    }

    public function test_la_simulation_accepte_le_nom_complet_et_refuse_le_nom_seul(): void
    {
        $user = User::factory()->for($this->organization)->create([
            'first_name' => 'Jean',
            'name' => 'Dupont',
        ]);

        $demande = $this->confirmationDemandeeParLEcran($user);

        // « Jean Dupont » — ce que l'ecran demande — declenche la simulation.
        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.delete', $user), ['confirmation' => $demande])
            ->assertOk()
            // Le marqueur de « la simulation a eu lieu » est desormais le bloc
            // de suppression definitive, que TASK-1636 a mis a la place du
            // simple pied de page.
            ->assertSee(__('admin.user_delete_final_title'));

        // « Dupont » seul ne suffit pas : l'ecran n'a jamais demande cela.
        $this->actingAs($this->superAdmin)
            ->post(route('admin.users.delete', $user), ['confirmation' => $user->name])
            ->assertOk()
            ->assertDontSee(__('admin.user_delete_final_title'));
    }

    /**
     * TASK-1640 — la suppression reelle ne depend plus du nom, ni complet ni seul.
     *
     * Le test precedent mesurait « nom complet accepte, nom seul refuse » sur ce
     * chemin. Cette distinction n'existe plus : le nom n'entre pas dans la
     * decision. Ce qui reste verifie, c'est que l'ECRAN de simulation continue de
     * demander le nom complet (test ci-dessus), et que le chemin destructif, lui,
     * ne le regarde pas.
     */
    public function test_la_suppression_reelle_ne_depend_plus_du_nom(): void
    {
        $user = User::factory()->for($this->organization)->create([
            'first_name' => 'Jean',
            'name' => 'Dupont',
        ]);

        // Meme le nom partiel — qui etait REFUSE avant — ne change plus rien.
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $user), [
                'confirmation' => $user->name,
                'preview_fingerprint' => $this->fingerprintFor($user),
            ])
            ->assertRedirect(route('admin.users'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    // =====================================================================
    // W — personne d'autre
    // =====================================================================

    public function test_un_membre_ordinaire_ne_peut_pas_atteindre_la_suppression(): void
    {
        $membre = User::factory()->for($this->organization)->create();

        $this->actingAs($membre)
            ->delete(route('admin.users.destroy', $this->target))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    public function test_un_invite_ne_peut_pas_atteindre_la_suppression(): void
    {
        $this->delete(route('admin.users.destroy', $this->target))->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    public function test_l_orgadmin_n_a_aucune_route_de_suppression_globale(): void
    {
        // TASK-1636 ne donne PAS ce pouvoir a l'OrgAdmin : son parcours reste
        // non destructif. La garde porte sur l'ABSENCE de route, pas sur un
        // refus d'acces — une route qui existerait pourrait etre ouverte demain
        // par inadvertance.
        $names = collect(app('router')->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values();

        $this->assertTrue(
            $names->contains('admin.users.destroy'),
            'La route SuperAdmin de suppression doit exister.'
        );

        $this->assertFalse(
            $names->contains(fn (string $name) => str_contains($name, 'users.destroy') && $name !== 'admin.users.destroy'),
            'Aucune autre surface que le SuperAdmin ne doit porter une route de suppression de compte global.'
        );
    }

    public function test_l_orgadmin_garde_son_parcours_non_destructif(): void
    {
        $orgAdmin = User::factory()->for($this->organization)->create();
        $this->organization->update(['admin_id' => $orgAdmin->id]);

        // Sa route historique existe et reste une simulation.
        $this->actingAs($orgAdmin)
            ->post(route('organization.admin.users.delete', ['organization' => $this->organization->slug, 'user' => $this->target]), [
                'confirmation' => $this->target->fullName,
            ]);

        $this->assertDatabaseHas('users', ['id' => $this->target->id]);
    }

    private function insertService(string $userId): string
    {
        $categoryId = (string) Str::uuid();
        DB::table('categories')->insert([
            'id' => $categoryId,
            'organization_id' => $this->organization->id,
            'name_b2c' => 'Categorie',
            'name_b2b' => 'Categorie',
            'slug' => 'cat-'.Str::random(8),
            'color' => '#6366f1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (string) Str::uuid();
        DB::table('services')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
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
