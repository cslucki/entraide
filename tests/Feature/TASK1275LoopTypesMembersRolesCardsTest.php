<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\LoopTypeSetting;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\User;
use App\Services\LoopGovernanceService;
use App\Services\Loops\LoopCardCompositionService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Loops\LoopTypeRegistry;
use App\Support\ScenarioPacks\Packs\Test20260822DogfoodingDataset;
use App\Support\ScenarioPacks\Packs\Test20260822DogfoodingPack;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1275 — les 10 Boucles de `test20260822` deviennent reellement
 * differentes et multi-personas : 7 types, membres et roles selon le mapping
 * valide par Cyril, Cards du preset (plus `core.dossiers`, garde partout)
 * et rien d'autre, outils principaux coherents. Le tout idempotent (load,
 * load bis, reset, reset bis, delete), tracke au registre, sans aucun usage
 * simule des Cards (T1277), sans job, sans IA.
 *
 * Fixture source minimale (les 10 repertoires declares, 1 fichier) : la
 * partie corpus est le contrat de TASK-1269, deja couvert.
 *
 * Composition attendue par Boucle (preset du type, `config/loop_types.php`,
 * + `core.dossiers`) — ecrite EN CLAIR ici plutot que derivee du code, pour
 * qu'un preset qui bouge se voie.
 */
#[Group('ai')]
class TASK1275LoopTypesMembersRolesCardsTest extends TestCase
{
    use RefreshDatabase;

    private const ORG = Test20260822DogfoodingPack::ORGANIZATION_SLUG;

    private const UT_DALLAS = '09-UT Dallas';

    private const PROTOCOLE = "08-Protocole d'emergence";

    private const PLAN_262 = '07-Plan-262 Définition boucles et IA';

    /** @var array<string, list<string>> Boucle -> Cards ACTIVES attendues (tous placements) */
    private const EXPECTED_ACTIVE = [
        '01-COMMUNICATION' => ['core.manifesto', 'core.members', 'core.article', 'core.roadmap', 'core.dossiers'],
        '02-DESIGN' => ['core.ai_summary', 'core.manifesto', 'core.roadmap', 'core.decisions', 'core.dossiers', 'core.members'],
        '03-Post LinkedIN' => ['core.manifesto', 'core.members', 'core.article', 'core.roadmap', 'core.dossiers'],
        '04-Screens' => ['core.ai_summary', 'core.manifesto', 'core.roadmap', 'core.decisions', 'core.dossiers', 'core.members'],
        '05-Pour-la-beta1' => ['core.ai_summary', 'core.roadmap', 'core.journal', 'core.members', 'core.dossiers'],
        '06-Pour_Boucles' => ['core.manifesto', 'core.members', 'core.polls', 'core.events', 'core.dossiers'],
        '07-Plan-262 Définition boucles et IA' => ['core.manifesto', 'core.members', 'training.course_material', 'training.progression', 'training.assignments', 'core.dossiers'],
        "08-Protocole d'emergence" => ['core.manifesto', 'core.members', 'core.roadmap', 'core.journal', 'core.polls', 'core.dossiers'],
        '09-UT Dallas' => ['core.manifesto', 'core.members', 'core.marketplace', 'core.roadmap', 'core.events', 'core.dossiers'],
        '10-Aria projet européen' => ['core.ai_summary', 'core.manifesto', 'core.roadmap', 'core.decisions', 'core.dossiers', 'core.members'],
    ];

    /** @var array<string, list<string>> Boucle -> Cards ETEINTES attendues (ligne presente, enabled = false) */
    private const EXPECTED_OFF = [
        '01-COMMUNICATION' => ['core.polls', 'core.events'],
        '02-DESIGN' => ['core.polls', 'core.events'],
        '03-Post LinkedIN' => ['core.polls', 'core.events'],
        '04-Screens' => ['core.polls', 'core.events'],
        '05-Pour-la-beta1' => ['core.manifesto', 'core.polls', 'core.events'],
        '06-Pour_Boucles' => [],
        '07-Plan-262 Définition boucles et IA' => ['core.polls', 'core.events'],
        "08-Protocole d'emergence" => ['core.events'],
        '09-UT Dallas' => ['core.polls'],
        '10-Aria projet européen' => ['core.polls', 'core.events'],
    ];

    /** @var array<string, list<string>> Boucle -> outils PRINCIPAUX attendus, dans l'ordre de la barre */
    private const EXPECTED_PRIMARY = [
        '01-COMMUNICATION' => ['core.roadmap', 'core.dossiers', 'core.article'],
        '02-DESIGN' => ['core.roadmap', 'core.decisions', 'core.dossiers'],
        '03-Post LinkedIN' => ['core.roadmap', 'core.dossiers', 'core.article'],
        '04-Screens' => ['core.roadmap', 'core.decisions', 'core.dossiers'],
        '05-Pour-la-beta1' => ['core.roadmap', 'core.dossiers', 'core.journal'],
        '06-Pour_Boucles' => ['core.polls', 'core.events', 'core.dossiers'],
        '07-Plan-262 Définition boucles et IA' => ['training.course_material', 'training.progression', 'training.assignments'],
        "08-Protocole d'emergence" => ['core.roadmap', 'core.polls', 'core.journal'],
        '09-UT Dallas' => ['core.roadmap', 'core.marketplace', 'core.events'],
        '10-Aria projet européen' => ['core.roadmap', 'core.decisions', 'core.dossiers'],
    ];

    private Organization $organization;

    private string $source;

    /** @var array<string, User> */
    private array $personas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake(Test20260822DogfoodingPack::DISK);

        Organization::factory()->create(['is_default' => true, 'is_active' => true, 'slug' => 'plateforme-1275']);
        $this->organization = Organization::factory()->create([
            'slug' => self::ORG,
            'name' => 'test20260822',
            'loops_enabled' => true,
            'ai_profiles_enabled' => true,
            'transactions_naming' => 'b2c',
            'welcome_points' => 100,
            'membership_enabled' => false,
            // Etat reel : la composition par le proprietaire est verrouillee.
            'loop_composition_policy' => Organization::COMPOSITION_LOCKED,
        ]);

        foreach (Test20260822DogfoodingPack::PERSONA_EMAILS as $key => $email) {
            $this->personas[$key] = User::factory()->create([
                'email' => $email,
                'organization_id' => $this->organization->id,
                'name' => 'Test '.ucfirst(substr($key, 5)),
                'preferred_locale' => 'fr',
                'points_balance' => 0,
            ]);
        }
        $this->organization->update(['admin_id' => $this->personas['test_cyril']->id]);

        $this->source = sys_get_temp_dir().'/task1275-'.uniqid();
        foreach (Test20260822DogfoodingPack::LOOP_DIRECTORIES as $name) {
            File::makeDirectory($this->source.'/'.$name, 0755, true);
        }
        File::put($this->source.'/'.Test20260822DogfoodingPack::LOOP_DIRECTORIES[0].'/01-note.md', "# Note\n\nTexte.\n");

        config([
            'scenario_packs.allowed_organizations' => [self::ORG, 'artscilab-demo'],
            'scenario_packs.definitions' => [Test20260822DogfoodingPack::PACK_ID => Test20260822DogfoodingPack::class],
            Test20260822DogfoodingPack::SOURCE_CONFIG_KEY => $this->source,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function load(): void
    {
        $pack = app(ScenarioPackCatalog::class)->get(Test20260822DogfoodingPack::PACK_ID);
        app(ScenarioPackLoader::class)->load($pack, $this->organization);
    }

    private function reset(): void
    {
        app(ScenarioPackResetter::class)->reset(
            app(ScenarioPackCatalog::class)->get(Test20260822DogfoodingPack::PACK_ID),
            $this->organization,
        );
    }

    private function remove(): void
    {
        app(ScenarioPackRemover::class)->remove(Test20260822DogfoodingPack::PACK_ID, $this->organization);
    }

    private function loop(string $name): Loop
    {
        return Loop::query()->where('organization_id', $this->organization->id)->where('name', $name)->firstOrFail();
    }

    /** @return array<string, string> email -> role, membres ACTIFS, tries par email */
    private function activeMembers(Loop $loop): array
    {
        return LoopMember::query()
            ->where('loop_id', $loop->id)
            ->where('status', 'active')
            ->join('users', 'users.id', '=', 'loop_members.user_id')
            ->orderBy('users.email')
            ->pluck('loop_members.role', 'users.email')
            ->all();
    }

    /** @return array<string, string> email -> role attendu pour une Boucle, trie par email */
    private function expectedMembers(string $loopName): array
    {
        $expected = [];
        foreach (Test20260822DogfoodingDataset::LOOP_SETUP[$loopName]['members'] as $persona => $role) {
            $expected[Test20260822DogfoodingPack::PERSONA_EMAILS[$persona]] = $role;
        }
        ksort($expected);

        return $expected;
    }

    private function activeGrid(Loop $loop): array
    {
        return app(LoopCardRegistry::class)->activeGridKeysFor($loop->fresh());
    }

    /**
     * Photographie de tout ce que T1275 pose : types, membres/roles, Cards
     * (enabled + rang), registre. Deux photographies egales = idempotence.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $loops = Loop::query()->where('organization_id', $this->organization->id)->orderBy('name')->get();

        return [
            'types' => $loops->pluck('type', 'name')->all(),
            'members' => $loops->mapWithKeys(fn (Loop $l) => [$l->name => $this->activeMembers($l)])->all(),
            'cards' => $loops->mapWithKeys(fn (Loop $l) => [$l->name => LoopCard::query()
                ->where('loop_id', $l->id)->orderBy('card_key')
                ->get(['card_key', 'enabled', 'added_by_preset', 'primary_rank'])
                ->map(fn (LoopCard $c) => [$c->card_key, (bool) $c->enabled, $c->added_by_preset, $c->primary_rank])
                ->all()])->all(),
            'member_rows' => LoopMember::query()->whereIn('loop_id', $loops->pluck('id'))->count(),
            'registry' => ScenarioPackEntity::query()->where('organization_id', $this->organization->id)
                ->orderBy('entity_type')->orderBy('internal_key')
                ->get(['entity_type', 'internal_key', 'ownership'])
                ->map(fn (ScenarioPackEntity $e) => $e->entity_type.'|'.$e->internal_key.'|'.$e->ownership)
                ->all(),
        ];
    }

    /**
     * Ramener la base a l'etat v1.1.0 (T1274) mesure le 23/08 : 10 Boucles
     * `general`, Cyril seul `owner`, 5 Cards du preset `general` actives,
     * aucun rang, registre des membres limite a Cyril. Les Boucles restent
     * `created` au registre, comme en reel.
     */
    private function regressToVersion110State(): void
    {
        foreach (Loop::query()->where('organization_id', $this->organization->id)->get() as $loop) {
            $loop->update(['type' => 'general']);
            LoopMember::query()->where('loop_id', $loop->id)
                ->where('user_id', '!=', $this->personas['test_cyril']->id)->delete();
            LoopMember::query()->where('loop_id', $loop->id)->update(['role' => 'owner']);
            LoopCard::query()->where('loop_id', $loop->id)
                ->whereNotIn('card_key', ['core.manifesto', 'core.members', 'core.polls', 'core.events', 'core.dossiers'])->delete();
            LoopCard::query()->where('loop_id', $loop->id)
                ->update(['enabled' => true, 'added_by_preset' => 'general', 'primary_rank' => null]);
        }

        ScenarioPackEntity::query()->where('organization_id', $this->organization->id)
            ->where('entity_type', 'loop_member')->where('internal_key', 'not like', '%:test_cyril')->delete();
    }

    // =====================================================================
    // A. La declaration
    // =====================================================================

    public function test_the_setup_declares_the_ten_corpus_loops_the_seven_types_and_only_canonical_roles(): void
    {
        $setup = Test20260822DogfoodingDataset::LOOP_SETUP;

        $this->assertSame(Test20260822DogfoodingPack::LOOP_DIRECTORIES, array_keys($setup), 'LOOP_SETUP couvre exactement LOOP_DIRECTORIES, dans l\'ordre.');

        $types = array_column($setup, 'type');
        $this->assertSame(
            ['coaching' => 1, 'general' => 1, 'networking' => 1, 'peer_support' => 1, 'project' => 3, 'training' => 1, 'writing' => 2],
            collect($types)->countBy()->sortKeys()->all(),
        );
        $this->assertCount(7, array_unique($types), 'Les 7 types sont couverts.');

        $registry = app(LoopRoleRegistry::class);
        foreach ($setup as $name => $entry) {
            $this->assertNotEmpty($entry['members'], $name);
            $this->assertContains('owner', $entry['members'], "{$name} a un proprietaire.");
            foreach ($entry['members'] as $persona => $role) {
                $this->assertArrayHasKey($persona, Test20260822DogfoodingPack::PERSONA_EMAILS, "{$name} : {$persona}");
                $this->assertTrue($registry->isCanonical($role), "{$name} : {$persona} -> {$role} n'est pas canonique.");
                $this->assertNotSame('moderator', $role);
            }
            $this->assertSame(['core.dossiers'], $entry['kept_cards'], "{$name} garde core.dossiers.");
        }

        $this->assertSame(['test_roger' => 'owner', 'test_cyril' => 'facilitator', 'test_kiran' => 'member', 'test_sana' => 'member'], $setup[self::UT_DALLAS]['members']);
        $this->assertSame(['core.roadmap', 'core.polls', 'core.journal'], $setup[self::PROTOCOLE]['primary_cards']);

        // Le trio est declare partout ou la grille depasse 3 Cards, et la Card
        // qui definit le type y figure (decision Cyril : garder dossiers ET
        // promouvoir).
        $this->assertContains('training.assignments', $setup[self::PLAN_262]['primary_cards']);
        $this->assertContains('core.journal', $setup[self::PROTOCOLE]['primary_cards']);
        $this->assertContains('core.marketplace', $setup[self::UT_DALLAS]['primary_cards']);
        foreach ($setup as $name => $entry) {
            if ($entry['primary_cards'] !== null) {
                $this->assertCount(3, $entry['primary_cards'], $name);
            }
        }
    }

    // =====================================================================
    // B. Types
    // =====================================================================

    public function test_load_gives_each_loop_its_declared_type_read_back_from_the_database(): void
    {
        $this->load();

        $registry = app(LoopTypeRegistry::class);

        foreach (Test20260822DogfoodingDataset::LOOP_SETUP as $name => $entry) {
            // Relu en base, jamais depuis une instance : `LoopService::
            // resolveCreationType()` montre qu'un type peut retomber en
            // silence sur `general`.
            $stored = DB::table('loops')->where('organization_id', $this->organization->id)->where('name', $name)->value('type');

            $this->assertSame($entry['type'], $stored, "{$name} : type en base");
            $this->assertSame($entry['type'], $registry->resolve($stored), "{$name} : le registre ne le replie pas sur le defaut");
        }

        $this->assertSame(
            7,
            DB::table('loops')->where('organization_id', $this->organization->id)->distinct()->count('type'),
        );
    }

    public function test_load_needs_no_loop_type_settings_row_while_the_ui_reassignment_of_three_types_does(): void
    {
        // Environnement neuf : aucune des 3 lignes globales de bouclepro
        // (`writing`, `networking`, `peer_support` available = true).
        $this->assertSame(0, LoopTypeSetting::query()->count());

        $registry = app(LoopTypeRegistry::class);

        foreach (['writing', 'networking', 'peer_support'] as $type) {
            $this->assertTrue($registry->exists($type));
            $this->assertFalse($registry->isAvailable($type), "{$type} est ferme en config.");
            // FRAGILITE CONSIGNEE : le chemin UI (`LoopPresetConfigurator::
            // applyPreset`, `AdminLoopController`) refuse ces 3 types tant
            // que les lignes globales n'existent pas.
            $this->assertFalse($registry->isAssignableTo($type, 'general'), "{$type} n'est pas assignable par l'UI sans override.");
        }

        $this->load();

        $this->assertSame('writing', $this->loop('01-COMMUNICATION')->type);
        $this->assertSame('networking', $this->loop(self::UT_DALLAS)->type);
        $this->assertSame('peer_support', $this->loop(self::PROTOCOLE)->type);

        // Une Boucle garde un type ferme : le formulaire le propose encore,
        // le rendu le nomme.
        $this->assertArrayHasKey('writing', $registry->selectableFor('writing', $this->organization));
        $this->assertSame(__('loops.types.writing.label'), $registry->label('writing', $this->organization));
    }

    // =====================================================================
    // C. Membres et roles
    // =====================================================================

    public function test_load_gives_each_loop_exactly_its_declared_members_and_roles(): void
    {
        $this->load();

        foreach (Test20260822DogfoodingDataset::LOOP_SETUP as $name => $entry) {
            $this->assertSame($this->expectedMembers($name), $this->activeMembers($this->loop($name)), $name);
        }

        $this->assertSame(0, LoopMember::query()->where('role', 'moderator')->count());
        $this->assertSame(0, LoopMember::query()->where('status', '!=', 'active')->count());
        $this->assertSame(27, LoopMember::query()->whereIn('loop_id', Loop::query()->where('organization_id', $this->organization->id)->pluck('id'))->count());
    }

    public function test_roger_owns_ut_dallas_and_cyril_animates_it_because_the_reverse_order_is_refused(): void
    {
        // Temoin : sur une Boucle ou Cyril est seul proprietaire, le
        // retrograder est refuse par la gouvernance. C'est l'invariant que
        // le pack respecte en nommant Roger AVANT.
        $witness = app(LoopService::class)->createLoopForOrg($this->personas['test_cyril'], $this->organization->id, 'Temoin last_owner');
        $cyril = LoopMember::query()->where('loop_id', $witness->id)->where('user_id', $this->personas['test_cyril']->id)->firstOrFail();
        $this->assertSame(LoopGovernanceService::RESULT_LAST_OWNER, app(LoopGovernanceService::class)->changeRole($cyril, 'facilitator'));
        $this->assertSame('owner', $cyril->fresh()->role);
        $witness->delete();

        $this->load();

        $loop = $this->loop(self::UT_DALLAS);
        $governance = app(LoopGovernanceService::class);

        $this->assertSame(1, $governance->countActiveOwners($loop));
        $this->assertSame($this->personas['test_roger']->id, $governance->activeOwners($loop)->first()->user_id);

        $cyril = LoopMember::query()->where('loop_id', $loop->id)->where('user_id', $this->personas['test_cyril']->id)->firstOrFail();
        $this->assertSame('facilitator', $cyril->role);
        $this->assertSame('active', $cyril->status);

        // Et chaque Boucle garde un proprietaire actif.
        foreach (Loop::query()->where('organization_id', $this->organization->id)->get() as $each) {
            $this->assertGreaterThanOrEqual(1, $governance->countActiveOwners($each), $each->name);
        }

        // Vu par Roger : la Boucle s'ouvre, son role est lu comme proprietaire.
        $this->actingAs($this->personas['test_roger'])
            ->get('/org/'.self::ORG.'/loops/'.$loop->slug)
            ->assertOk()
            ->assertSee(self::UT_DALLAS);
        $this->assertTrue($this->personas['test_roger']->can('update', $loop), 'Roger, proprietaire, peut modifier 09-UT Dallas.');
    }

    public function test_load_over_the_version_110_state_retypes_recomposes_and_adds_members_without_ghost_or_duplicate_registry_rows(): void
    {
        $this->load();
        $this->regressToVersion110State();

        // Etat reel AVANT T1275, verifie ici avant de rejouer.
        $loops = Loop::query()->where('organization_id', $this->organization->id)->get();
        $this->assertSame(['general'], $loops->pluck('type')->unique()->values()->all());
        foreach ($loops as $loop) {
            $this->assertSame([Test20260822DogfoodingPack::CREATOR_EMAIL => 'owner'], $this->activeMembers($loop));
            $this->assertSame(['core.dossiers', 'core.events', 'core.manifesto', 'core.members', 'core.polls'], LoopCard::query()->where('loop_id', $loop->id)->orderBy('card_key')->pluck('card_key')->all());
        }
        $this->assertSame(10, ScenarioPackEntity::query()->where('entity_type', 'loop_member')->count());

        $this->load();

        foreach (Test20260822DogfoodingDataset::LOOP_SETUP as $name => $entry) {
            $loop = $this->loop($name);
            $this->assertSame($entry['type'], $loop->type, $name);
            $this->assertSame($this->expectedMembers($name), $this->activeMembers($loop), $name);
            $this->assertEqualsCanonicalizing(self::EXPECTED_ACTIVE[$name], app(LoopTypeRegistry::class)->activeCardsFor($loop), $name);
        }

        // Registre : une ligne par membre declare (27), jamais deux pour la
        // meme cle, aucune ligne sans membre actif derriere.
        $rows = ScenarioPackEntity::query()->where('entity_type', 'loop_member')->get();
        $this->assertCount(27, $rows);
        $this->assertSame(27, $rows->pluck('internal_key')->unique()->count());
        $this->assertSame(['created'], $rows->pluck('ownership')->unique()->values()->all());

        foreach ($rows as $row) {
            [$loopKey, $persona] = explode(':', $row->internal_key, 2);
            $member = LoopMember::query()->whereKey($row->entity_id)->firstOrFail();
            $this->assertSame('active', $member->status, $row->internal_key);
            $this->assertSame($this->personas[$persona]->id, $member->user_id, $row->internal_key);
            $this->assertSame($loopKey, Str::slug($member->loop->name), $row->internal_key);
        }

        // Les cles de T1269 pour Cyril sont reutilisees telles quelles.
        foreach (Test20260822DogfoodingPack::LOOP_DIRECTORIES as $name) {
            $this->assertSame(1, $rows->where('internal_key', Str::slug($name).':test_cyril')->count(), $name);
        }
    }

    // =====================================================================
    // D. Cards
    // =====================================================================

    public function test_load_activates_exactly_the_preset_plus_dossiers_and_switches_the_rest_off_without_deleting_any_row(): void
    {
        $this->load();

        $types = app(LoopTypeRegistry::class);

        foreach (Test20260822DogfoodingDataset::LOOP_SETUP as $name => $entry) {
            $loop = $this->loop($name);

            $this->assertEqualsCanonicalizing(self::EXPECTED_ACTIVE[$name], $types->activeCardsFor($loop), "{$name} : Cards actives");

            // Le preset du type est bien DANS la composition (additif tenu)…
            foreach ($types->cardsFor($entry['type'], $this->organization) as $presetKey) {
                $this->assertContains($presetKey, $types->activeCardsFor($loop), "{$name} : {$presetKey} du preset {$entry['type']}");
            }

            // … `core.dossiers` partout …
            $this->assertContains('core.dossiers', $types->activeCardsFor($loop), "{$name} : le Dossier reste accessible");

            // … et le reste est ETEINT, pas supprime.
            foreach (self::EXPECTED_OFF[$name] as $offKey) {
                $row = LoopCard::query()->where('loop_id', $loop->id)->where('card_key', $offKey)->first();
                $this->assertNotNull($row, "{$name} : la ligne {$offKey} existe encore");
                $this->assertFalse((bool) $row->enabled, "{$name} : {$offKey} est eteinte");
            }

            $this->assertSame(0, LoopCard::query()->where('loop_id', $loop->id)->where('enabled', true)
                ->whereNotIn('card_key', self::EXPECTED_ACTIVE[$name])->count(), "{$name} : rien d'actif hors attendu");
        }

        // Rien n'a ete ecrit pour les Boucles d'autres Organizations.
        $this->assertSame(0, LoopCard::query()->where('organization_id', '!=', $this->organization->id)->count());
    }

    public function test_no_active_card_lacks_its_requirements_and_training_rests_on_course_material(): void
    {
        $this->load();

        $cards = app(LoopCardRegistry::class);
        $types = app(LoopTypeRegistry::class);

        $plan = $this->loop(self::PLAN_262);
        $active = $types->activeCardsFor($plan);
        $this->assertContains('training.course_material', $active);
        $this->assertContains('training.progression', $active);
        $this->assertContains('training.assignments', $active);
        $this->assertSame(['training.course_material'], $cards->requirementsOf('training.progression'));
        $this->assertSame(['training.course_material', 'training.progression'], $cards->requirementsOf('training.assignments'));

        foreach (Loop::query()->where('organization_id', $this->organization->id)->get() as $loop) {
            $active = $types->activeCardsFor($loop);
            foreach ($active as $key) {
                $blockers = $cards->blockersFor($key, $active);
                $this->assertSame([], $blockers['missing'], "{$loop->name} : {$key} sans ses dependances");
                $this->assertSame([], $blockers['conflicting'], "{$loop->name} : {$key} en conflit");
            }
        }
    }

    public function test_primary_tools_are_the_preset_cards_and_the_fourth_active_card_is_reachable_not_hidden(): void
    {
        $this->load();

        $composition = app(LoopCardCompositionService::class);
        $registry = app(LoopCardRegistry::class);
        $cyril = $this->personas['test_cyril'];

        foreach (Test20260822DogfoodingDataset::LOOP_SETUP as $name => $entry) {
            $loop = $this->loop($name);

            $this->assertSame(self::EXPECTED_PRIMARY[$name], $composition->primaryKeysFor($loop), "{$name} : principaux");

            $grid = $this->activeGrid($loop);
            $secondary = $composition->secondaryKeysFor($loop);
            $this->assertEqualsCanonicalizing(array_diff($grid, self::EXPECTED_PRIMARY[$name]), $secondary, "{$name} : secondaires = le reste de la grille");

            // Le plafond de 3 ne masque rien (TASK-1124) : tout ce qui est
            // actif en grille est soit principal, soit secondaire.
            $rendered = $registry->primaryWorkspaceCardsFor($loop, $cyril)->pluck('key')
                ->concat($registry->secondaryWorkspaceCardsFor($loop, $cyril)->pluck('key'))->all();
            $this->assertEqualsCanonicalizing($grid, $rendered, "{$name} : aucune Card de grille n'est perdue au rendu");
        }

        // 07, 08, 09 : 4 Cards de grille, `dossiers` secondaire. La barre
        // (TASK-1128 : 5 outils visibles, principaux d'abord) rend les 4
        // directement, sans depliant : rien n'est masque, et l'ordre est
        // celui des principaux puis du reste.
        foreach ([self::PLAN_262, self::PROTOCOLE, self::UT_DALLAS] as $name) {
            $loop = $this->loop($name);
            $this->assertCount(4, $this->activeGrid($loop), $name);
            $this->assertSame(['core.dossiers'], $composition->secondaryKeysFor($loop), $name);

            $response = $this->actingAs($cyril)->get('/org/'.self::ORG.'/loops/'.$loop->slug)->assertOk();

            $this->assertSame(
                [...self::EXPECTED_PRIMARY[$name], 'core.dossiers'],
                collect($response->viewData('toolbarCards'))->pluck('key')->all(),
                "{$name} : la barre rend les 4 Cards, principales d'abord",
            );
            $this->assertCount(0, $response->viewData('toolbarOverflow'), "{$name} : aucun debordement");
            $response->assertDontSee(trans_choice('loops.tools_overflow_count', 1, ['count' => 1]));

            foreach ($this->activeGrid($loop) as $key) {
                $response->assertSee($registry->labelFor($loop, $key), false);
            }
        }

        // 08 : rangs EXPLICITES (roadmap, polls, journal) — l'ordre du
        // catalogue aurait mis `dossiers` (38) devant `journal` (41).
        $ranks = LoopCard::query()->where('loop_id', $this->loop(self::PROTOCOLE)->id)
            ->whereNotNull('primary_rank')->orderBy('primary_rank')->pluck('card_key')->all();
        $this->assertSame(['core.roadmap', 'core.polls', 'core.journal'], $ranks);

        // 07 et 09 : trio DECLARE (la Card du type — assignments, marketplace —
        // y est) mais obtenu par le mode derive du produit : aucun rang ecrit,
        // le pack verifie sans materialiser. Idem pour les Boucles a 3 Cards.
        $this->assertSame(['training.course_material', 'training.progression', 'training.assignments'], Test20260822DogfoodingDataset::LOOP_SETUP[self::PLAN_262]['primary_cards']);
        $this->assertSame(['core.roadmap', 'core.marketplace', 'core.events'], Test20260822DogfoodingDataset::LOOP_SETUP[self::UT_DALLAS]['primary_cards']);
        foreach ([self::PLAN_262, self::UT_DALLAS, '01-COMMUNICATION', '05-Pour-la-beta1'] as $name) {
            $this->assertSame(0, LoopCard::query()->where('loop_id', $this->loop($name)->id)->whereNotNull('primary_rank')->count(), "{$name} : mode derive, aucun rang");
        }
        $this->assertContains('training.assignments', $composition->primaryKeysFor($this->loop(self::PLAN_262)));
        $this->assertContains('core.marketplace', $composition->primaryKeysFor($this->loop(self::UT_DALLAS)));

        // Une Boucle a 3 Cards de grille : les 3 dans la barre, pas de depliant.
        $response = $this->actingAs($cyril)->get('/org/'.self::ORG.'/loops/'.$this->loop('01-COMMUNICATION')->slug)->assertOk();
        $this->assertSame(self::EXPECTED_PRIMARY['01-COMMUNICATION'], collect($response->viewData('toolbarCards'))->pluck('key')->all());
        $this->assertCount(0, $response->viewData('toolbarOverflow'));
        $response->assertSee(__('loops.cards.article.label'));
    }

    // =====================================================================
    // E. Visibilite par persona
    // =====================================================================

    public function test_each_persona_is_member_of_exactly_its_loops_and_the_index_says_so(): void
    {
        $this->load();

        $expected = [];
        foreach (Test20260822DogfoodingDataset::LOOP_SETUP as $name => $entry) {
            foreach (array_keys($entry['members']) as $persona) {
                $expected[$persona][] = $name;
            }
        }

        foreach ($this->personas as $persona => $user) {
            sort($expected[$persona]);

            $response = $this->actingAs($user)->get('/org/'.self::ORG.'/loops')->assertOk();

            $mine = collect($response->viewData('loops'))->where('is_member', true)->pluck('name')->sort()->values()->all();
            $this->assertSame($expected[$persona], $mine, "{$persona} : « Mes Boucles »");

            // Les 7 types sont nommes dans la page (onglets), pour chacun.
            foreach (['general', 'project', 'coaching', 'training', 'writing', 'networking', 'peer_support'] as $type) {
                $response->assertSee(app(LoopTypeRegistry::class)->label($type, $this->organization));
            }
        }

        $this->assertSame(['01-COMMUNICATION', '02-DESIGN', '03-Post LinkedIN', '04-Screens', '05-Pour-la-beta1', '06-Pour_Boucles', '07-Plan-262 Définition boucles et IA', "08-Protocole d'emergence", '09-UT Dallas', '10-Aria projet européen'], $expected['test_cyril']);
        $this->assertSame(['06-Pour_Boucles', "08-Protocole d'emergence", '09-UT Dallas', '10-Aria projet européen'], $expected['test_roger']);
        $this->assertSame(['01-COMMUNICATION', '02-DESIGN', '04-Screens', '06-Pour_Boucles', '07-Plan-262 Définition boucles et IA', '09-UT Dallas'], $expected['test_kiran']);
        $this->assertSame(['03-Post LinkedIN', '05-Pour-la-beta1', '06-Pour_Boucles', '07-Plan-262 Définition boucles et IA', "08-Protocole d'emergence", '09-UT Dallas', '10-Aria projet européen'], $expected['test_sana']);
    }

    // =====================================================================
    // F. Idempotence, registre, retrait
    // =====================================================================

    public function test_load_load_reset_reset_change_nothing_and_delete_purges_members_cards_and_loops_but_keeps_the_accounts(): void
    {
        $this->load();
        $first = $this->snapshot();

        $this->assertSame(27, $first['member_rows']);
        $this->assertCount(10, $first['types']);

        $this->load();
        $this->assertSame($first, $this->snapshot(), 'load bis');

        $this->reset();
        $this->assertSame($first, $this->snapshot(), 'reset');

        $this->reset();
        $this->assertSame($first, $this->snapshot(), 'reset bis');

        $this->assertSame('1.2.0', DB::table('scenario_pack_loads')->where('organization_id', $this->organization->id)->value('pack_version'));

        $loopIds = Loop::query()->where('organization_id', $this->organization->id)->pluck('id');
        $this->remove();

        $this->assertSame(0, Loop::query()->where('organization_id', $this->organization->id)->count());
        $this->assertSame(0, LoopMember::query()->whereIn('loop_id', $loopIds)->count());
        $this->assertSame(0, LoopCard::query()->whereIn('loop_id', $loopIds)->count());
        $this->assertSame(0, ScenarioPackEntity::query()->where('organization_id', $this->organization->id)->count());

        foreach ($this->personas as $user) {
            $this->assertNotNull($user->fresh(), 'Les comptes ne sont jamais supprimes.');
        }

        // Rechargeable apres retrait : memes types, memes roles.
        $this->load();
        $this->assertSame($first['types'], $this->snapshot()['types']);
        $this->assertSame($first['members'], $this->snapshot()['members']);
    }

    /**
     * TASK-1307 : `$this->load()` (re)cree 10 Boucles par la chaine
     * canonique ; chaque document racine dispatche desormais son indexation
     * a la creation, comme tout Article attache a un Dossier (avant
     * TASK-1307, un document racine n'etait jamais indexe avant sa premiere
     * edition humaine — un oubli corrige, pas une garantie du pack). Aucun
     * appel IA n'en decoule ici : le job reste en attente, aucun worker ne
     * le traite pendant ce test.
     */
    public function test_load_pushes_no_job_calls_no_ai_and_simulates_no_usage_of_any_card(): void
    {
        Queue::fake();

        $this->load();

        Queue::assertPushed(IndexDossierArticleChunks::class, count(Test20260822DogfoodingPack::LOOP_DIRECTORIES));
        $this->assertSame(0, AiProviderInvocation::query()->count());

        // T1277, pas T1275 : aucune donnee dans aucune Card.
        foreach (['loop_polls', 'loop_events', 'loop_decisions', 'loop_journal_entries', 'loop_roadmap_items', 'loop_messages'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
