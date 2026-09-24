<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1630 — le GATE : supprimer une Boucle qui traine une ancienne
 * arborescence ne doit plus rendre 500.
 *
 * ## Le meme defaut, deux visages selon le moteur — et le piege du test
 *
 * MESURE sur le code d'avant, pas supposee :
 *
 *   · **PostgreSQL** : `SQLSTATE[23514] dossiers_holder_xor`, la requete
 *     casse, le SuperAdmin recoit un 500 et la Boucle reste la ;
 *   · **SQLite** : la contrainte CHECK n'existe pas (elle ne peut pas etre
 *     ajoutee a une table existante, cf. migration `2026_08_12_090000`). La
 *     requete REUSSIT — et laisse derriere elle des Dossiers sans parent,
 *     sans proprietaire et sans Boucle. Pas de crash : une corruption
 *     silencieuse.
 *
 * D'ou la forme de ces tests. Un test qui se contenterait d'attendre « la
 * requete reussit » serait VERT en SQLite sur le code casse — il aurait
 * mesure le moteur, pas le comportement. Les assertions portent donc sur
 * l'ETAT DE LA BASE APRES : plus aucune ligne orpheline, plus aucun fichier
 * detache. Ce contrat-la rougit sur les deux moteurs, et le fichier tourne
 * donc sur les dix shards de la CI, pas seulement sur les six PostgreSQL.
 *
 * ## Le mecanisme qu'on garde
 *
 * `Loop` n'a pas de SoftDeletes -> la ligne part -> `dossiers.loop_id` est en
 * CASCADE, la racine suit -> `dossiers.parent_id` est en SET NULL, donc les
 * sous-dossiers n'etaient pas detruits mais ORPHELINES : `parent_id`,
 * `owner_id` et `loop_id` tous les trois NULL, la seule combinaison que le
 * CHECK refuse -> `SQLSTATE[23514]`, 500 pour le SuperAdmin, et la Boucle
 * toujours la.
 */
class TASK1630LoopDeleteLegacyBranchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true, 'slug' => 'org-1630-gate']);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);
    }

    /**
     * La reproduction exacte du cas signale : Loop -> racine -> enfant ->
     * petit-enfant, puis DELETE SuperAdmin.
     */
    public function test_deleting_a_loop_that_still_carries_a_legacy_tree_succeeds(): void
    {
        $loop = $this->loopAvecArborescence();
        $racine = Dossier::where('loop_id', $loop->getKey())->firstOrFail();
        $enfant = Dossier::where('parent_id', $racine->getKey())->firstOrFail();
        $petitEnfant = Dossier::where('parent_id', $enfant->getKey())->firstOrFail();

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loops.destroy', ['loop' => $loop->getKey()]))
            ->assertRedirect(route('admin.loops'));

        // La Boucle est partie...
        $this->assertDatabaseMissing('loops', ['id' => $loop->getKey()]);

        // ...et TOUTE la branche avec elle, physiquement. Pas de promotion en
        // racine, pas de `parent_id` NULL artificiel, pas de branche
        // orpheline conservee.
        foreach ([$racine, $enfant, $petitEnfant] as $noeud) {
            $this->assertSame(0, Dossier::withTrashed()->whereKey($noeud->getKey())->count());
        }
    }

    public function test_no_orphaned_row_survives_the_deletion(): void
    {
        $loop = $this->loopAvecArborescence();

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loops.destroy', ['loop' => $loop->getKey()]))
            ->assertRedirect();

        // La ligne que `dossiers_holder_xor` refuse : ni parent, ni
        // proprietaire, ni Boucle. S'il en reste une, la contrainte ne l'a pas
        // vue passer seulement parce que rien ne l'a relue depuis.
        $this->assertSame(0, Dossier::withTrashed()
            ->whereNull('parent_id')->whereNull('owner_id')->whereNull('loop_id')->count());

        // Et aucun fichier detache de son Dossier par le SET NULL.
        $this->assertSame(0, DossierFile::withTrashed()->whereNull('dossier_id')->count());
    }

    public function test_the_dependencies_of_the_branch_are_cleaned_too(): void
    {
        $loop = $this->loopAvecArborescence();
        $petitEnfant = Dossier::whereNotNull('parent_id')
            ->whereIn('parent_id', Dossier::whereNotNull('parent_id')->pluck('id'))
            ->firstOrFail();

        $fichier = DossierFile::factory()->create([
            'organization_id' => $this->org->id,
            'dossier_id' => $petitEnfant->getKey(),
            'uploaded_by' => $this->superAdmin->id,
            'display_name' => 'annexe.pdf',
        ]);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loops.destroy', ['loop' => $loop->getKey()]))
            ->assertRedirect();

        $this->assertSame(0, DossierFile::withTrashed()->whereKey($fichier->getKey())->count());
    }

    /**
     * Une racine deja soft-deletee garde sa LIGNE, donc garde ses enfants,
     * donc garde le crash intact. C'est le cas que `withTrashed()` couvre.
     */
    public function test_a_soft_deleted_root_still_lets_the_loop_be_deleted(): void
    {
        $loop = $this->loopAvecArborescence();
        Dossier::where('loop_id', $loop->getKey())->firstOrFail()->delete();

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loops.destroy', ['loop' => $loop->getKey()]))
            ->assertRedirect(route('admin.loops'));

        $this->assertDatabaseMissing('loops', ['id' => $loop->getKey()]);
        $this->assertSame(0, Dossier::withTrashed()->where('organization_id', $this->org->id)
            ->whereNull('parent_id')->whereNull('owner_id')->whereNull('loop_id')->count());
    }

    /**
     * Le residu legacy le plus probable : un sous-dossier INTERMEDIAIRE deja
     * soft-delete, avec des enfants vivants sous lui.
     *
     * Une purge qui ne lirait que les lignes non supprimees s'arreterait a
     * lui : elle ne verrait jamais le petit-enfant, qui survivrait avec un
     * `parent_id` pointant une ligne detruite — puis mis a NULL par le SET
     * NULL. Ce cas echappait a `test_a_soft_deleted_root_...`, qui ne
     * supprime que la racine, et un sabotage de `withTrashed()` dans
     * `branch()` passait donc au vert ici.
     */
    public function test_a_soft_deleted_middle_folder_does_not_hide_its_children(): void
    {
        $loop = $this->loopAvecArborescence();
        $racine = Dossier::where('loop_id', $loop->getKey())->firstOrFail();
        $enfant = Dossier::where('parent_id', $racine->getKey())->firstOrFail();
        $petitEnfant = Dossier::where('parent_id', $enfant->getKey())->firstOrFail();

        // L'intermediaire disparait des requetes ordinaires ; sa ligne reste.
        $enfant->delete();

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loops.destroy', ['loop' => $loop->getKey()]))
            ->assertRedirect(route('admin.loops'));

        foreach ([$racine, $enfant, $petitEnfant] as $noeud) {
            $this->assertSame(0, Dossier::withTrashed()->whereKey($noeud->getKey())->count());
        }

        $this->assertSame(0, Dossier::withTrashed()
            ->whereNull('parent_id')->whereNull('owner_id')->whereNull('loop_id')->count());
    }

    /**
     * Une Boucle sans arborescence n'a pas change de comportement : le
     * correctif ne devait pas transformer le cas ordinaire.
     */
    public function test_deleting_a_loop_without_any_subfolder_still_works(): void
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->superAdmin->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->superAdmin->id, 'joined_at' => now(),
        ]);
        $racine = Dossier::create([
            'organization_id' => $this->org->id, 'owner_id' => null, 'loop_id' => $loop->id,
            'name' => 'Documents', 'visibility' => Dossier::VISIBILITY_LOOP,
        ]);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loops.destroy', ['loop' => $loop->getKey()]))
            ->assertRedirect(route('admin.loops'));

        $this->assertDatabaseMissing('loops', ['id' => $loop->getKey()]);
        $this->assertSame(0, Dossier::withTrashed()->whereKey($racine->getKey())->count());
    }

    private function loopAvecArborescence(): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->superAdmin->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->superAdmin->id, 'joined_at' => now(),
        ]);

        $racine = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'loop_id' => $loop->id,
            'name' => 'Documents de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
        $enfant = Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $racine->getKey(),
            'name' => 'Communication',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
        Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $enfant->getKey(),
            'name' => 'Presse',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);

        return $loop;
    }
}
