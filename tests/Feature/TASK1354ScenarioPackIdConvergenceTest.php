<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1354 — convergence des `pack_id` vers leurs identites finales.
 *
 * ## Le defaut que cette migration repare
 *
 * `2026_09_01_140000_rename_scenario_pack_ids...` a ete ecrite avec
 * `artscilab-en-test` au SINGULIER, puis executee. L'arbitrage a ensuite fixe le
 * PLURIEL. Laravel ne rejoue pas une migration enregistree : la base qui l'avait
 * deja passee porte un identifiant qu'aucune version du code n'attend plus. Le
 * pack se declare « jamais charge » alors que ses entites sont la — et un
 * rechargement les dupliquerait.
 *
 * Ce fichier prouve que la seconde migration converge tous les etats connus,
 * qu'elle ne perd rien au passage, et qu'elle refuse de deviner quand elle ne
 * peut pas trancher.
 */
class TASK1354ScenarioPackIdConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_01_170000_converge_scenario_pack_ids_to_their_final_identities.php';

    // =====================================================================
    // A. Base neuve : rien a converger
    // =====================================================================

    /** A. Une base sans aucun chargement passe sans erreur et n'ecrit rien. */
    public function test_a_fresh_database_converges_without_error_and_writes_nothing(): void
    {
        $this->assertSame(0, ScenarioPackLoad::query()->count());

        $this->migration()->up();

        $this->assertSame(0, ScenarioPackLoad::query()->count());
    }

    // =====================================================================
    // B a E. Chaque etat connu rejoint son identite finale
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function convergences(): array
    {
        return [
            // B — etat historique, jamais renomme.
            'en dogfooding historique' => ['artscilab-en-dogfooding', 'artscilab-en-tests'],
            // C — l'etat INTERMEDIAIRE, celui qui a motive cette migration.
            'en test singulier intermediaire' => ['artscilab-en-test', 'artscilab-en-tests'],
            // D — le pack historique FR.
            'test20260822 dogfooding' => ['test20260822-dogfooding', 'test20260822'],
            // E — le pack de demonstration, qui portait un prenom.
            'roger demo' => ['artscilab-roger-demo', 'artscilab-demo-test'],
        ];
    }

    #[DataProvider('convergences')]
    public function test_every_known_state_reaches_its_final_identity(string $from, string $to): void
    {
        $load = $this->seedLoad($from);

        $this->migration()->up();

        $this->assertSame($to, $load->fresh()->pack_id);
    }

    // =====================================================================
    // F et G. Idempotence
    // =====================================================================

    /** F. Une base DEJA finale n'est pas touchee. */
    public function test_an_already_final_database_is_a_no_op(): void
    {
        $load = $this->seedLoad('artscilab-en-tests');
        $before = $load->fresh()->getRawOriginal();

        $this->migration()->up();

        $this->assertSame($before, $load->fresh()->getRawOriginal());
    }

    /** G. Deux passages consecutifs : le second ne change rien. */
    public function test_a_second_up_changes_nothing(): void
    {
        $load = $this->seedLoad('artscilab-en-test');

        $this->migration()->up();
        $after = $load->fresh()->getRawOriginal();

        $this->migration()->up();

        $this->assertSame($after, $load->fresh()->getRawOriginal());
        $this->assertSame('artscilab-en-tests', $load->fresh()->pack_id);
    }

    // =====================================================================
    // H. Collision : fail closed, et RIEN de modifie
    // =====================================================================

    /**
     * H. Ancien ET nouveau pour la MEME organisation : la migration leve, et
     * n'a rien ecrit du tout.
     *
     * C'est le point le plus important du fichier. L'index unique
     * (organization_id, pack_id) rend la convergence impossible, et aucune
     * resolution automatique n'est honnete : fusionner perdrait des entites,
     * supprimer perdrait un chargement, choisir au hasard perdrait la confiance.
     * Le preflight leve donc AVANT toute ecriture — une migration qui echoue a
     * mi-chemin laisserait exactement l'etat partiel qu'on repare.
     */
    public function test_a_collision_fails_closed_without_touching_a_single_row(): void
    {
        $organization = Organization::factory()->create(['slug' => 'org-collision']);

        $old = $this->seedLoad('artscilab-en-test', $organization);
        $new = $this->seedLoad('artscilab-en-tests', $organization);

        // Un autre pack, convergeable, dans une AUTRE organisation : il ne doit
        // pas non plus bouger. La migration echoue en bloc, pas a moitie.
        $untouched = $this->seedLoad('test20260822-dogfooding');

        $before = [
            $old->fresh()->getRawOriginal(),
            $new->fresh()->getRawOriginal(),
            $untouched->fresh()->getRawOriginal(),
        ];

        try {
            $this->migration()->up();
            $this->fail('La migration devait lever sur collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('collision', $exception->getMessage());
            $this->assertStringContainsString('artscilab-en-test', $exception->getMessage());
            $this->assertStringContainsString((string) $organization->id, $exception->getMessage());
        }

        $this->assertSame($before, [
            $old->fresh()->getRawOriginal(),
            $new->fresh()->getRawOriginal(),
            $untouched->fresh()->getRawOriginal(),
        ], 'Aucune ligne ne doit avoir bouge.');
    }

    /** H bis. La contrainte unique reste intacte apres une convergence normale. */
    public function test_the_unique_constraint_still_holds_after_convergence(): void
    {
        $organization = Organization::factory()->create(['slug' => 'org-unique']);
        $this->seedLoad('artscilab-en-test', $organization);

        $this->migration()->up();

        $this->expectException(QueryException::class);
        $this->seedLoad('artscilab-en-tests', $organization);
    }

    // =====================================================================
    // I a K. Ce que la convergence ne perd pas
    // =====================================================================

    /** I, J, K. Identite de ligne, provenance, et entites rattachees. */
    public function test_convergence_preserves_the_load_its_provenance_and_its_entities(): void
    {
        $organization = Organization::factory()->create(['slug' => 'org-preserve']);

        $load = $this->seedLoad('artscilab-en-test', $organization, createdByPack: true);
        $entity = ScenarioPackEntity::create([
            'scenario_pack_load_id' => $load->id,
            'organization_id' => $organization->id,
            'entity_type' => 'user',
            'internal_key' => 'membre-1',
            'entity_model' => User::class,
            'entity_id' => (string) Str::uuid(),
            'sequence' => 1,
            'ownership' => 'created',
        ]);

        $this->migration()->up();

        $fresh = $load->fresh();

        // I — meme ligne, meme identifiant.
        $this->assertSame($load->id, $fresh->id);
        // Meme version de pack : la convergence deplace une chaine, pas un etat.
        $this->assertSame($load->pack_version, $fresh->pack_version);
        // J — la provenance qui autorise le retrait a supprimer l'organisation.
        $this->assertTrue($fresh->organization_created_by_pack);
        // K — l'entite pointe toujours le meme chargement.
        $this->assertSame($load->id, $entity->fresh()->scenario_pack_load_id);
        $this->assertSame(1, ScenarioPackEntity::query()->where('scenario_pack_load_id', $fresh->id)->count());
    }

    // =====================================================================
    // L. down()
    // =====================================================================

    /**
     * L. `down()` restaure les identites canoniques HISTORIQUES, et jamais
     * l'etat intermediaire.
     *
     * `artscilab-en-test` n'etait pas une identite : c'etait un accident de
     * sequencement entre deux arbitrages. Y revenir recreerait le defaut que
     * cette migration corrige.
     */
    public function test_down_restores_historical_identities_and_never_the_intermediate_one(): void
    {
        $fromHistorical = $this->seedLoad('artscilab-en-dogfooding');
        $fromIntermediate = $this->seedLoad('artscilab-en-test');
        $fr = $this->seedLoad('test20260822-dogfooding');
        $demo = $this->seedLoad('artscilab-roger-demo');

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        // Les deux origines convergent vers UNE seule identite historique.
        $this->assertSame('artscilab-en-dogfooding', $fromHistorical->fresh()->pack_id);
        $this->assertSame('artscilab-en-dogfooding', $fromIntermediate->fresh()->pack_id);
        $this->assertSame('test20260822-dogfooding', $fr->fresh()->pack_id);
        $this->assertSame('artscilab-roger-demo', $demo->fresh()->pack_id);

        // Et l'etat intermediaire n'existe plus nulle part.
        $this->assertSame(0, ScenarioPackLoad::query()->where('pack_id', 'artscilab-en-test')->count());
    }

    /** L bis. `down()` ne connait que trois retours, et c'est ecrit dans le code. */
    public function test_down_never_declares_the_intermediate_identity_as_a_target(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION));

        $this->assertIsString($source);

        $reversals = (new \ReflectionClass($this->migration()))
            ->getReflectionConstant('REVERSALS')
            ->getValue();

        $this->assertNotContains('artscilab-en-test', $reversals);
        $this->assertSame(
            ['artscilab-demo-test', 'artscilab-en-tests', 'test20260822'],
            array_keys($reversals),
        );
    }

    // =====================================================================
    // M. Le nom d'organisation modifie par un humain
    // =====================================================================

    /** M. Un nom personnalise n'est JAMAIS ecrase. */
    public function test_a_human_edited_organization_name_is_never_overwritten(): void
    {
        $custom = Organization::factory()->create([
            'slug' => 'artscilab-en',
            'name' => 'ArtSciLab — mon nom a moi',
        ]);

        $this->migration()->up();

        $this->assertSame('ArtSciLab — mon nom a moi', $custom->fresh()->name);
    }

    /** M bis. Une variante historique CONNUE, elle, est normalisee. */
    public function test_a_known_historical_organization_name_is_normalised(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'artscilab-en',
            'name' => 'ArtSciLab — English dogfooding',
        ]);

        $this->migration()->up();

        $this->assertSame('ArtSciLab — Test anglais', $organization->fresh()->name);
    }

    /** M ter. Aucun slug d'organisation n'est modifie — TASK dediee. */
    public function test_no_organization_slug_is_ever_modified(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'artscilab-en',
            'name' => 'ArtSciLab — English dogfooding',
        ]);

        $migration = $this->migration();
        $migration->up();
        $this->assertSame('artscilab-en', $organization->fresh()->slug);

        $migration->down();
        $this->assertSame('artscilab-en', $organization->fresh()->slug);

        $source = file_get_contents(base_path(self::MIGRATION));
        $this->assertStringNotContainsString("'slug' =>", (string) $source);
    }

    // =====================================================================
    // Perimetre
    // =====================================================================

    /** La migration ne touche NI les entites, NI le moteur Scenario Pack. */
    public function test_the_migration_touches_neither_entities_nor_the_engine(): void
    {
        $source = (string) file_get_contents(base_path(self::MIGRATION));

        // Le seul critere qui vaille : quelles tables la migration ATTAQUE
        // reellement. Chercher le mot « scenario_pack_entities » dans le
        // fichier serait faux — il apparait legitimement dans le docblock qui
        // explique justement qu'on n'y touche pas.
        $this->assertSame(
            ['organizations', 'scenario_pack_loads'],
            collect(preg_match_all("/DB::table\('([a-z_]+)'\)/", $source, $m) ? $m[1] : [])
                ->unique()->sort()->values()->all(),
        );

        // Et aucune classe du moteur Scenario Pack n'est importee ni referencee
        // en code : une migration historique ne depend pas d'un code mutable.
        $this->assertStringNotContainsString('use App\\Support\\ScenarioPacks', $source);
        $this->assertStringNotContainsString('ScenarioPackEntity', $source);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function seedLoad(string $packId, ?Organization $organization = null, bool $createdByPack = false): ScenarioPackLoad
    {
        return ScenarioPackLoad::create([
            'pack_id' => $packId,
            'pack_version' => '1.0.0',
            'organization_id' => ($organization ?? Organization::factory()->create())->id,
            'loaded_at' => now(),
            'organization_created_by_pack' => $createdByPack,
        ]);
    }

    private function migration(): Migration
    {
        $migration = require base_path(self::MIGRATION);

        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
