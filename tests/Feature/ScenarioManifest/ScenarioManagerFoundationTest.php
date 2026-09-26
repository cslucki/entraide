<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1646 — la fondation administrative du Scenario Manager.
 *
 * Ce fichier ne verifie pas que « la table existe » : il verifie ce que la
 * table PROMET. Une colonne presente ne prouve rien ; une FK qui se denoue
 * quand on supprime sa cible, si.
 *
 * Trois promesses y sont eprouvees par l'experience plutot que par
 * introspection :
 *
 * 1. `LOADED` n'est pas stocke — on le derive, et on prouve qu'aucune colonne
 *    ne le porte ;
 * 2. la disparition d'une sandbox denoue le lien et rend la version
 *    simplement VALID (CDC 7.3) — on supprime reellement le chargement ;
 * 3. le predicat SQL `scopeLoaded()` et le predicat PHP `isLoaded()` rendent
 *    le MEME verdict — deux definitions du meme etat finiraient par diverger.
 */
class ScenarioManagerFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);
    }

    /**
     * Cree une version, en laissant les defauts utiles pour le cas teste.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function version(array $attributes = []): ScenarioManifestVersion
    {
        return ScenarioManifestVersion::create(array_merge([
            'scenario_key' => 'ofsh',
            'name' => 'OFSH',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_QA,
            'origin' => ScenarioManifestVersion::ORIGIN_NEW,
            'state' => ScenarioManifestVersion::STATE_DRAFT,
            'json_source' => '{"manifest_version":1}',
            'created_by' => $this->superAdmin->id,
        ], $attributes));
    }

    /**
     * Un chargement vivant. L'audit de T1646 a mesure qu'un chargement est
     * vivant si et seulement si sa ligne existe : pas de colonne d'etat, pas
     * de `removed_at`, pas de suppression douce.
     */
    private function livingLoad(): ScenarioPackLoad
    {
        return ScenarioPackLoad::create([
            'pack_id' => 'manifest-ofsh',
            'pack_version' => '1.0.0',
            'organization_id' => $this->organization->id,
            'loaded_at' => now(),
        ]);
    }

    // =====================================================================
    // 1. Le schema porte ce que le CDC 7.2 demande, et RIEN de plus
    // =====================================================================

    public function test_la_table_porte_exactement_les_colonnes_du_cdc(): void
    {
        $this->assertTrue(Schema::hasTable('scenario_manifest_versions'));

        foreach ([
            'id', 'scenario_key', 'name', 'version', 'usage', 'origin', 'state',
            'json_source', 'digest', 'validation_summary',
            'approved_digest', 'approved_by', 'approved_at',
            'parent_id', 'captured_from_organization_id', 'scenario_pack_load_id',
            'created_by', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('scenario_manifest_versions', $column),
                "La colonne {$column} est exigee par le CDC 7.2."
            );
        }
    }

    public function test_loaded_n_est_pas_une_colonne(): void
    {
        // CDC 7.3 et 33.8 : LOADED est derive. Une colonne serait une seconde
        // verite, fausse des que la sandbox disparaitrait.
        foreach (['loaded', 'is_loaded', 'loaded_at', 'state_loaded'] as $forbidden) {
            $this->assertFalse(
                Schema::hasColumn('scenario_manifest_versions', $forbidden),
                "LOADED doit rester derive : la colonne {$forbidden} ne doit pas exister."
            );
        }
    }

    public function test_la_table_est_de_portee_plateforme_et_ne_reintroduit_pas_community(): void
    {
        // Le Scenario Manager est une surface SuperAdmin qui ne depend d'aucune
        // Organization (CDC 4.1) : pas de colonne tenant. `captured_from_...`
        // n'est pas un scope, c'est une provenance nullable.
        $this->assertFalse(Schema::hasColumn('scenario_manifest_versions', 'organization_id'));

        foreach (['community_id', 'current_community', 'community'] as $legacy) {
            $this->assertFalse(
                Schema::hasColumn('scenario_manifest_versions', $legacy),
                "Community est une dette legacy : {$legacy} ne doit jamais apparaitre dans une table neuve."
            );
        }
    }

    public function test_deux_versions_du_meme_scenario_ne_peuvent_pas_partager_une_semver(): void
    {
        $this->version(['version' => '1.0.0']);

        // Le meme scenario en 1.1.0 est legitime (CDC 19.1 : ofsh porte
        // 1.0.0, 1.1.0, 1.2.0).
        $this->version(['version' => '1.1.0']);
        $this->assertSame(2, ScenarioManifestVersion::query()->forScenario('ofsh')->count());

        // Le meme couple, non.
        $this->expectException(QueryException::class);
        $this->version(['version' => '1.1.0']);
    }

    public function test_un_autre_scenario_peut_porter_la_meme_semver(): void
    {
        $this->version(['scenario_key' => 'ofsh', 'version' => '1.0.0']);
        $this->version(['scenario_key' => 'amt', 'name' => 'AMT', 'version' => '1.0.0']);

        $this->assertSame(2, ScenarioManifestVersion::query()->count());
    }

    // =====================================================================
    // 2. Les etats persistes : DEUX, et la liste est fermee
    // =====================================================================

    public function test_seuls_draft_et_valid_sont_des_etats_persistes(): void
    {
        $this->assertSame(
            ['draft', 'valid'],
            ScenarioManifestVersion::STATES,
            'La liste des etats persistes est FERMEE : loaded n en fait pas partie.'
        );

        $this->assertNotContains('loaded', ScenarioManifestVersion::STATES);
    }

    public function test_postgresql_refuse_un_etat_hors_liste(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('La contrainte CHECK est pgsql-only : SQLite ne peut pas en ajouter a une table existante.');
        }

        $this->expectException(QueryException::class);

        // On contourne deliberement le modele : c'est la BASE qu'on teste ici.
        DB::table('scenario_manifest_versions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'scenario_key' => 'ofsh',
            'name' => 'OFSH',
            'version' => '9.9.9',
            'usage' => ScenarioManifestVersion::USAGE_QA,
            'origin' => ScenarioManifestVersion::ORIGIN_NEW,
            'state' => 'loaded',
            'json_source' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // =====================================================================
    // 3. LOADED : la derivation, eprouvee dans les quatre cas
    // =====================================================================

    public function test_une_version_valide_reliee_a_un_chargement_vivant_est_loaded(): void
    {
        $version = $this->version([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'scenario_pack_load_id' => $this->livingLoad()->id,
        ]);

        $this->assertTrue($version->isLoaded());
    }

    public function test_une_version_valide_sans_chargement_n_est_pas_loaded(): void
    {
        $version = $this->version(['state' => ScenarioManifestVersion::STATE_VALID]);

        $this->assertTrue($version->isValid());
        $this->assertFalse($version->isLoaded(), 'Approuvee mais jamais chargee : VALID, pas LOADED.');
    }

    public function test_un_brouillon_relie_a_un_chargement_n_est_pas_loaded(): void
    {
        // L'asymetrie qui compte : le lien seul ne suffit pas. Une version qui
        // repasse DRAFT ne doit plus etre annoncee chargee (CDC 8.3).
        $version = $this->version([
            'state' => ScenarioManifestVersion::STATE_DRAFT,
            'scenario_pack_load_id' => $this->livingLoad()->id,
        ]);

        $this->assertFalse($version->isLoaded());
    }

    public function test_un_brouillon_nu_n_est_pas_loaded(): void
    {
        $this->assertFalse($this->version()->isLoaded());
    }

    public function test_la_disparition_de_la_sandbox_denoue_le_lien_et_rend_la_version_simplement_valide(): void
    {
        // La promesse du CDC 7.3, prouvee par l'experience et non par le
        // schema : on supprime reellement le chargement.
        $load = $this->livingLoad();
        $version = $this->version([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'scenario_pack_load_id' => $load->id,
        ]);

        $this->assertTrue($version->isLoaded());

        $load->delete();

        $version->refresh();

        $this->assertNull($version->scenario_pack_load_id, 'nullOnDelete doit avoir denoue le lien.');
        $this->assertTrue($version->isValid(), 'La version survit a sa sandbox.');
        $this->assertFalse($version->isLoaded(), 'Elle redevient simplement VALID.');
        $this->assertSame(
            '{"manifest_version":1}',
            $version->json_source,
            'Le document administratif ne doit pas etre emporte par la sandbox.'
        );
    }

    public function test_le_predicat_sql_et_le_predicat_php_rendent_le_meme_verdict(): void
    {
        // Deux definitions du meme etat finissent par diverger. Ici elles sont
        // comparees sur les quatre combinaisons possibles.
        $load = $this->livingLoad();

        $this->version(['version' => '1.0.0', 'state' => ScenarioManifestVersion::STATE_VALID, 'scenario_pack_load_id' => $load->id]);
        $this->version(['version' => '1.1.0', 'state' => ScenarioManifestVersion::STATE_VALID]);
        $this->version(['version' => '1.2.0', 'state' => ScenarioManifestVersion::STATE_DRAFT, 'scenario_pack_load_id' => $load->id]);
        $this->version(['version' => '1.3.0', 'state' => ScenarioManifestVersion::STATE_DRAFT]);

        $parLeSql = ScenarioManifestVersion::query()->loaded()->pluck('version')->sort()->values()->all();
        $parLePhp = ScenarioManifestVersion::query()->get()
            ->filter(fn (ScenarioManifestVersion $v) => $v->isLoaded())
            ->pluck('version')->sort()->values()->all();

        $this->assertSame(['1.0.0'], $parLeSql);
        $this->assertSame($parLeSql, $parLePhp, 'scopeLoaded() et isLoaded() doivent porter le MEME filtre.');
    }

    // =====================================================================
    // 4. Les FK se denouent vraiment
    // =====================================================================

    public function test_la_suppression_de_l_auteur_detache_sans_emporter_la_version(): void
    {
        $auteur = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);
        $version = $this->version(['created_by' => $auteur->id, 'approved_by' => $auteur->id]);

        $auteur->forceDelete();

        $version->refresh();

        $this->assertNull($version->created_by);
        $this->assertNull($version->approved_by);
        $this->assertDatabaseHas('scenario_manifest_versions', ['id' => $version->id]);
    }

    public function test_la_suppression_du_parent_denoue_la_provenance_sans_orpheliner(): void
    {
        // Difference assumee avec TASK-1630 : `parent_id` porte une PROVENANCE,
        // pas une CONTENANCE. La descendante reste complete et autonome.
        $parent = $this->version(['version' => '1.0.0', 'state' => ScenarioManifestVersion::STATE_VALID]);
        $enfant = $this->version([
            'version' => '1.1.0',
            'origin' => ScenarioManifestVersion::ORIGIN_CAPTURE,
            'parent_id' => $parent->id,
        ]);

        $parent->delete();

        $enfant->refresh();

        $this->assertNull($enfant->parent_id);
        $this->assertSame('{"manifest_version":1}', $enfant->json_source);
        $this->assertDatabaseHas('scenario_manifest_versions', ['id' => $enfant->id]);
    }

    public function test_la_suppression_de_l_organization_capturee_denoue_la_provenance(): void
    {
        $sandbox = Organization::factory()->create();
        $version = $this->version(['captured_from_organization_id' => $sandbox->id]);

        $sandbox->forceDelete();

        $version->refresh();

        $this->assertNull($version->captured_from_organization_id);
        $this->assertDatabaseHas('scenario_manifest_versions', ['id' => $version->id]);
    }

    // =====================================================================
    // 5. Le document et l'approbation
    // =====================================================================

    public function test_un_json_invalide_est_conserve_tel_quel(): void
    {
        // CDC 7.2 : `json_source` est conserve MEME invalide. Un brouillon qui
        // ne parse pas doit rester editable, sinon l'auteur perd son travail.
        $casse = '{"manifest_version":1, "organization": ';

        $version = $this->version(['json_source' => $casse, 'digest' => null]);

        $this->assertSame($casse, $version->fresh()->json_source);
        $this->assertNull($version->fresh()->digest, 'Un document qui ne parse pas n a pas de digest.');
    }

    public function test_le_resume_de_validation_revient_en_tableau(): void
    {
        $resume = ['verdict' => 'INVALID', 'digest' => null, 'counters' => ['users' => 3], 'error_count' => 2];

        $version = $this->version(['validation_summary' => $resume]);

        $this->assertSame($resume, $version->fresh()->validation_summary);
    }

    public function test_une_approbation_ne_vaut_que_pour_le_contenu_approuve(): void
    {
        $digest = str_repeat('a', 64);

        $aligne = $this->version(['version' => '1.0.0', 'digest' => $digest, 'approved_digest' => $digest]);
        $this->assertTrue($aligne->approvalMatchesCurrentDigest());

        // Le document a bouge apres l'approbation : elle ne vaut plus rien.
        $derive = $this->version(['version' => '1.1.0', 'digest' => str_repeat('b', 64), 'approved_digest' => $digest]);
        $this->assertFalse($derive->approvalMatchesCurrentDigest());

        // Jamais approuvee.
        $jamais = $this->version(['version' => '1.2.0', 'digest' => $digest]);
        $this->assertFalse($jamais->approvalMatchesCurrentDigest());
    }

    public function test_le_plafond_du_document_est_celui_du_parser(): void
    {
        // Une seconde valeur de plafond, ailleurs, se perimerait en silence.
        $this->assertSame(
            \App\Support\ScenarioManifest\ManifestJsonParser::MAX_BYTES,
            ScenarioManifestVersion::MAX_JSON_BYTES
        );
    }
}
