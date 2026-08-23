<?php

namespace Tests\Feature;

use App\Livewire\AiAgentChat;
use App\Models\AiProviderInvocation;
use App\Models\Category;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\ScenarioPackEntity;
use App\Models\Skill;
use App\Models\User;
use App\Services\Ai\MemberProfileAgentResponder;
use App\Support\ScenarioPacks\Packs\Test20260822DogfoodingDataset;
use App\Support\ScenarioPacks\Packs\Test20260822DogfoodingPack;
use App\Support\ScenarioPacks\ScenarioPackCatalog;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use App\Support\ScenarioPacks\ScenarioPackRemover;
use App\Support\ScenarioPacks\ScenarioPackResetter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1274 — socle dataset FR de `test20260822` : les 4 personas
 * utilisables (profil humain complet, locale FR, coordonnees DEMO jamais
 * affichees), referentiels de l'Organization (6 categories, 37 skills issus
 * des CV), points de bienvenue par le mecanisme canonique (double ecriture
 * `points_balance` + `point_ledger`), 4 profils IA publies en francais que
 * le responder existant sait utiliser. Le tout idempotent au rejeu.
 *
 * Fixture source minimale (les 10 repertoires declares, 1 fichier) : la
 * partie corpus est le contrat de TASK-1269, deja couvert.
 *
 * Balance / ledger (corrections T1274, section G) : le ledger est la source
 * de verite, `points_balance` en est la somme — a chaque etape `load`,
 * `load` repete, `reset`, `reset` repete, `delete`, et pour un persona
 * `reused` dont l'historique anterieur ne doit jamais etre touche.
 */
#[Group('ai')]
class TASK1274SocleDatasetFrTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private string $source;

    /** @var array<string, User> */
    private array $personas = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake(Test20260822DogfoodingPack::DISK);

        // Organization plateforme (autorite economique) + tenant de dogfooding.
        Organization::factory()->create(['is_default' => true, 'is_active' => true, 'slug' => 'plateforme-1274']);
        $this->organization = Organization::factory()->create([
            'slug' => Test20260822DogfoodingPack::ORGANIZATION_SLUG,
            'name' => 'test20260822',
            'loops_enabled' => true,
            'ai_profiles_enabled' => true,
            'transactions_naming' => 'b2c',
            'welcome_points' => 100,
            'membership_enabled' => false,
        ]);

        // Etat reel du 2026-08-23 : comptes crees par le SuperAdmin, 5 champs
        // sur 6 a NULL, points_balance 0, aucune ligne de ledger.
        foreach (Test20260822DogfoodingPack::PERSONA_EMAILS as $key => $email) {
            $this->personas[$key] = User::factory()->create([
                'email' => $email,
                'organization_id' => $this->organization->id,
                'name' => 'Test '.ucfirst(substr($key, 5)),
                'first_name' => null,
                'phone' => null,
                'city' => null,
                'country_code' => null,
                'bio' => null,
                'preferred_locale' => null,
                'points_balance' => 0,
            ]);
        }
        $this->organization->update(['admin_id' => $this->personas['test_cyril']->id]);

        $this->source = sys_get_temp_dir().'/task1274-'.uniqid();
        // T1274 : le pack exige ses 10 repertoires declares ; un seul fichier,
        // dans le premier (la partie corpus est le contrat de TASK-1269).
        foreach (Test20260822DogfoodingPack::LOOP_DIRECTORIES as $name) {
            File::makeDirectory($this->source.'/'.$name, 0755, true);
        }
        File::put($this->source.'/'.Test20260822DogfoodingPack::LOOP_DIRECTORIES[0].'/01-note.md', "# Note\n\nTexte.\n");

        config([
            'scenario_packs.allowed_organizations' => [Test20260822DogfoodingPack::ORGANIZATION_SLUG, 'artscilab-demo'],
            'scenario_packs.definitions' => [Test20260822DogfoodingPack::PACK_ID => Test20260822DogfoodingPack::class],
            Test20260822DogfoodingPack::SOURCE_CONFIG_KEY => $this->source,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    private function load(): void
    {
        $pack = app(ScenarioPackCatalog::class)->get(Test20260822DogfoodingPack::PACK_ID);
        app(ScenarioPackLoader::class)->load($pack, $this->organization);
    }

    /** @return list<string> tous les noms de skills des referentiels (les seuls autorises). */
    private function allSkillNames(): array
    {
        return array_merge(...array_values(Test20260822DogfoodingDataset::SKILLS));
    }

    private function servicesCreateUrl(): string
    {
        return '/org/'.Test20260822DogfoodingPack::ORGANIZATION_SLUG.'/services/create';
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

    private function ledgerSum(User $user): int
    {
        return (int) PointLedger::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $this->organization->id)
            ->sum('delta');
    }

    private function welcomeLines(User $user): int
    {
        return PointLedger::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $this->organization->id)
            ->where('reason', 'welcome_bonus')
            ->count();
    }

    /**
     * L'invariant non negociable : pour chaque persona,
     * `points_balance == SUM(delta)` du ledger dans cette Organization.
     *
     * @param  array<string, int>  $expectedBalances  cle persona -> balance attendue
     */
    private function assertBalancesDeriveFromLedger(array $expectedBalances, string $step): void
    {
        foreach ($this->personas as $key => $user) {
            $user = $user->fresh();
            $this->assertSame($this->ledgerSum($user), $user->points_balance, "{$step} / {$key} : points_balance == SUM(ledger)");
            $this->assertSame($expectedBalances[$key], $user->points_balance, "{$step} / {$key} : balance attendue");
        }
    }

    // =====================================================================
    // A. Profils humains
    // =====================================================================

    public function test_before_load_the_four_personas_are_blocked_by_profile_complete(): void
    {
        foreach ($this->personas as $key => $user) {
            $this->actingAs($user)
                ->get($this->servicesCreateUrl())
                ->assertRedirect(route('profile.edit'));
        }
    }

    public function test_load_completes_the_four_human_profiles_in_french_with_demo_phones_never_shown(): void
    {
        $this->load();

        foreach (Test20260822DogfoodingDataset::HUMAN_PROFILES as $key => $expected) {
            $user = $this->personas[$key]->fresh();

            foreach ($expected as $attribute => $value) {
                $this->assertSame($value, $user->{$attribute}, "{$key}.{$attribute}");
            }

            $this->assertSame('fr', $user->preferred_locale, $key);
            $this->assertFalse($user->show_phone, "{$key} : le telephone DEMO n'est jamais affiche");
            $this->assertFalse($user->show_email, $key);
            $this->assertStringContainsString('(DEMO)', $user->phone, "{$key} : aucune coordonnee reelle");
            $this->assertLessThanOrEqual(30, strlen($user->phone), "{$key} : validation ProfileController max:30");
            $this->assertLessThanOrEqual(500, mb_strlen($user->bio), "{$key} : bio <= 500");
            $this->assertNull($user->address_line1, $key);
            $this->assertNull($user->address_line2, $key);
            $this->assertNull($user->postal_code, $key);
            $this->assertNull($user->membership_value, $key);
            $this->assertNull($user->location, "{$key} : champ mort, jamais renseigne");
            $this->assertSame(Test20260822DogfoodingPack::PERSONA_EMAILS[$key], $user->email, "{$key} : l'email ne bouge pas");
        }

        $this->assertSame('Slucki', $this->personas['test_cyril']->fresh()->name);
        $this->assertSame('Marseille', $this->personas['test_cyril']->fresh()->city);
        $this->assertSame('https://www.leonardo.info', $this->personas['test_roger']->fresh()->website);
        $this->assertNull($this->personas['test_kiran']->fresh()->website);
    }

    public function test_after_load_each_persona_reaches_a_profile_complete_page_without_being_redirected_to_profile_edit(): void
    {
        $this->load();

        foreach ($this->personas as $key => $user) {
            $response = $this->actingAs($user->fresh())->get($this->servicesCreateUrl());

            $response->assertOk();
            $this->assertStringNotContainsString('/profile/edit', $response->headers->get('Location') ?? '', $key);
        }
    }

    // =====================================================================
    // B. Referentiels
    // =====================================================================

    public function test_load_creates_the_six_categories_with_b2c_and_b2b_names(): void
    {
        $this->load();

        $categories = Category::query()->where('organization_id', $this->organization->id)->get()->keyBy('slug');

        $this->assertCount(6, $categories);
        foreach (Test20260822DogfoodingDataset::CATEGORIES as $slug => $expected) {
            $this->assertTrue($categories->has($slug), $slug);
            $this->assertSame($expected['name_b2c'], $categories[$slug]->name_b2c);
            $this->assertSame($expected['name_b2b'], $categories[$slug]->name_b2b);
            $this->assertSame($expected['color'], $categories[$slug]->color);
        }
        $this->assertSame('Outils numériques', $categories['numerique-outils']->name_b2c);
        $this->assertSame('Accompagnement des parcours', $categories['emploi-transitions']->name_b2b);
    }

    public function test_load_creates_exactly_the_cv_skills_attached_to_their_category_and_nothing_else(): void
    {
        $this->load();

        $skills = Skill::query()->where('organization_id', $this->organization->id)->with('category')->get();
        $expectedNames = $this->allSkillNames();

        $this->assertCount(37, $skills);
        $this->assertCount(37, array_unique($expectedNames), 'Le referentiel du brief n\'a aucun doublon.');
        $this->assertEqualsCanonicalizing($expectedNames, $skills->pluck('name')->all(), 'Aucun skill invente, aucun skill manquant.');

        foreach (Test20260822DogfoodingDataset::SKILLS as $categorySlug => $names) {
            foreach ($names as $name) {
                $skill = $skills->firstWhere('name', $name);
                $this->assertNotNull($skill, $name);
                $this->assertSame($categorySlug, $skill->category->slug, $name);
                $this->assertSame(Str::slug($name), $skill->slug, $name);
            }
        }

        $this->assertSame(0, Category::query()->where('organization_id', '!=', $this->organization->id)->count());
        $this->assertSame(0, Skill::query()->where('organization_id', '!=', $this->organization->id)->count());
    }

    // =====================================================================
    // C. Points de bienvenue — mecanisme canonique (double ecriture)
    // =====================================================================

    public function test_load_credits_100_welcome_points_with_the_matching_ledger_line_for_each_persona(): void
    {
        $this->load();

        foreach ($this->personas as $key => $user) {
            $user = $user->fresh();
            $lines = PointLedger::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $this->organization->id)
                ->get();

            $this->assertSame(100, $user->points_balance, $key);
            $this->assertCount(1, $lines, "{$key} : exactement une ligne de ledger");
            $this->assertSame('welcome_bonus', $lines[0]->reason, $key);
            $this->assertSame(100, $lines[0]->delta, $key);
            $this->assertNull($lines[0]->transaction_id, $key);
            $this->assertSame((int) $lines->sum('delta'), $user->points_balance, "{$key} : jamais points_balance sans le ledger correspondant");
        }

        $this->assertSame(4, PointLedger::query()->count());
    }

    public function test_a_persona_already_credited_is_neither_re_credited_nor_given_a_second_ledger_line(): void
    {
        $roger = $this->personas['test_roger'];
        $roger->update(['points_balance' => 250]);
        PointLedger::create([
            'user_id' => $roger->id,
            'transaction_id' => null,
            'delta' => 250,
            'organization_id' => $this->organization->id,
            'reason' => 'welcome_bonus',
        ]);

        $this->load();

        $this->assertSame(250, $roger->fresh()->points_balance);
        $this->assertSame(1, PointLedger::query()->where('user_id', $roger->id)->count());
        $this->assertSame(100, $this->personas['test_cyril']->fresh()->points_balance, 'Les autres sont credites normalement.');
        $this->assertSame(4, PointLedger::query()->count());

        // La ligne preexistante est referencee `reused` : le retrait du pack
        // ne la supprime pas et ne touche pas la balance de Roger.
        $row = ScenarioPackEntity::query()->where('entity_type', 'point_ledger')->where('internal_key', 'test_roger:welcome_bonus')->firstOrFail();
        $this->assertSame(ScenarioPackEntity::OWNERSHIP_REUSED, $row->ownership);

        $this->remove();

        $this->assertSame(250, $roger->fresh()->points_balance);
        $this->assertSame(1, PointLedger::query()->where('user_id', $roger->id)->count());
        $this->assertSame(0, $this->personas['test_cyril']->fresh()->points_balance, 'La ligne created de Cyril est purgee et sa balance realignee.');
    }

    // =====================================================================
    // D. Profils IA
    // =====================================================================

    public function test_load_publishes_four_french_ai_profiles_traceable_to_the_cvs(): void
    {
        $this->load();

        $profiles = MemberAiProfile::query()->where('organization_id', $this->organization->id)->get()->keyBy('user_id');
        $allowedSkills = $this->allSkillNames();
        $tones = config('member_ai_profile.tones');
        $contacts = config('member_ai_profile.contact_options');

        $this->assertCount(4, $profiles);

        foreach (Test20260822DogfoodingDataset::AI_PROFILES as $key => $expected) {
            $profile = $profiles[$this->personas[$key]->id] ?? null;
            $this->assertNotNull($profile, $key);

            $this->assertSame(MemberAiProfile::STATUS_PUBLISHED, $profile->status, $key);
            $this->assertTrue($profile->isPublished(), $key);
            $this->assertSame('fr', $profile->locale, $key);
            $this->assertNotNull($profile->published_at, $key);
            $this->assertNotNull($profile->validated_at, $key);
            $this->assertNull($profile->disabled_at, $key);

            foreach (['member_profile_summary', 'service_scope', 'experience_context', 'tone', 'preferred_contact_action', 'generated_summary'] as $text) {
                $this->assertSame($expected[$text], $profile->{$text}, "{$key}.{$text}");
            }
            foreach (['skills', 'problems_helped', 'help_types', 'target_audience', 'boundaries', 'good_request_examples', 'bad_request_examples'] as $json) {
                $this->assertSame($expected[$json], $profile->{$json}, "{$key}.{$json}");
                $this->assertNotEmpty($profile->{$json}, "{$key}.{$json}");
            }

            $this->assertLessThanOrEqual(500, mb_strlen($profile->member_profile_summary), $key);
            $this->assertLessThanOrEqual(500, mb_strlen($profile->service_scope), $key);
            $this->assertLessThanOrEqual(1000, mb_strlen($profile->experience_context), $key);
            $this->assertLessThanOrEqual(10, count($profile->skills), "{$key} : skills max 10 (validation wizard)");
            $this->assertLessThanOrEqual(3, count($profile->good_request_examples), $key);
            $this->assertLessThanOrEqual(3, count($profile->bad_request_examples), $key);
            $this->assertContains($profile->tone, $tones, "{$key} : ton du referentiel produit");
            $this->assertContains($profile->preferred_contact_action, $contacts, $key);
            $this->assertSame([], array_diff($profile->skills, $allowedSkills), "{$key} : aucun skill absent des CV");

            $structured = $profile->structured_profile;
            $this->assertIsArray($structured, $key);
            foreach (['summary', 'service_scope', 'experience_context', 'skills', 'help_types', 'target_audience', 'problems_helped', 'boundaries', 'preferred_contact_action', 'tone'] as $k) {
                $this->assertArrayHasKey($k, $structured, "{$key}.structured_profile.{$k}");
            }
            $this->assertIsString($structured['target_audience'], "{$key} : concatene tel quel par le responder");
            $this->assertIsString($structured['problems_helped'], "{$key} : concatene tel quel par le responder");
            $this->assertSame($expected['tone_label'], $structured['tone'], $key);
            $this->assertSame('TASK-1274', $profile->metadata['task'] ?? null, $key);
        }

        $this->assertStringContainsString('CyberWorkers', $profiles[$this->personas['test_cyril']->id]->experience_context);
        $this->assertStringContainsString('ArtSciLab', $profiles[$this->personas['test_roger']->id]->member_profile_summary);
        $this->assertContains('Pas de conseil juridique', $profiles[$this->personas['test_kiran']->id]->boundaries);
        $this->assertContains('SQL', $profiles[$this->personas['test_sana']->id]->skills);
    }

    // =====================================================================
    // E. Idempotence, registre, retrait
    // =====================================================================

    public function test_replaying_load_duplicates_nothing_and_re_credits_nobody(): void
    {
        $this->load();
        $publishedAt = MemberAiProfile::query()->pluck('published_at', 'user_id')->map(fn ($d) => $d?->toIso8601String())->all();
        $snapshot = fn () => [
            Category::query()->count(), Skill::query()->count(), PointLedger::query()->count(),
            MemberAiProfile::query()->count(), ScenarioPackEntity::query()->count(),
            User::query()->where('organization_id', $this->organization->id)->sum('points_balance'),
        ];
        $before = $snapshot();

        $this->load();
        $this->load();

        $this->assertSame($before, $snapshot());
        // 4 personas + 4 lignes de ledger + 6 categories + 37 skills + 4 profils IA
        // + fixture corpus (10 loops, 10 membres, 10 dossiers, 10 documents racines, 1 fichier).
        $this->assertSame([6, 37, 4, 4, 4 + 4 + 6 + 37 + 4 + 41, 400], $before);
        $this->assertSame(
            $publishedAt,
            MemberAiProfile::query()->pluck('published_at', 'user_id')->map(fn ($d) => $d?->toIso8601String())->all(),
            'Le rejeu ne re-publie pas.'
        );
        $this->assertTrue(MemberAiProfile::query()->get()->every(fn ($p) => $p->isPublished()));
    }

    public function test_registry_holds_personas_as_reused_and_the_new_referentials_and_profiles_as_created(): void
    {
        $this->load();

        $entities = ScenarioPackEntity::query()->get();

        $this->assertSame(4, $entities->where('entity_type', 'persona')->count());
        $this->assertTrue($entities->where('entity_type', 'persona')->every(fn ($e) => $e->ownership === ScenarioPackEntity::OWNERSHIP_REUSED));

        foreach (['category' => 6, 'skill' => 37, 'ai_profile' => 4] as $type => $count) {
            $this->assertSame($count, $entities->where('entity_type', $type)->count(), $type);
            $this->assertTrue($entities->where('entity_type', $type)->every(fn ($e) => $e->ownership === ScenarioPackEntity::OWNERSHIP_CREATED), "{$type} doit etre created");
        }

        // Les categories sont inscrites AVANT les skills : purge FK-safe
        // (ordre inverse d'inscription) meme sans cascade.
        $this->assertLessThan(
            $entities->where('entity_type', 'skill')->min('sequence'),
            $entities->where('entity_type', 'category')->max('sequence'),
        );

        // T1274-FIX : la ligne welcome_bonus ecrite par le pack est inscrite
        // `created` (purgeable, avec realignement de la balance).
        $this->assertSame(4, $entities->where('entity_type', 'point_ledger')->count());
        $this->assertTrue($entities->where('entity_type', 'point_ledger')->every(fn ($e) => $e->ownership === ScenarioPackEntity::OWNERSHIP_CREATED));
        $this->assertSame(
            array_map(fn (string $key) => "{$key}:welcome_bonus", array_keys(Test20260822DogfoodingPack::PERSONA_EMAILS)),
            $entities->where('entity_type', 'point_ledger')->sortBy('sequence')->pluck('internal_key')->values()->all(),
        );
    }

    public function test_reset_keeps_everything_and_removal_purges_referentials_profiles_and_welcome_lines_but_keeps_accounts_and_human_profiles(): void
    {
        $this->load();

        $this->reset();
        $this->assertSame(6, Category::query()->count());
        $this->assertSame(37, Skill::query()->count());
        $this->assertSame(4, MemberAiProfile::query()->count());
        $this->assertSame(400, (int) User::query()->where('organization_id', $this->organization->id)->sum('points_balance'));

        $this->remove();

        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Skill::query()->count());
        $this->assertSame(0, MemberAiProfile::query()->count());

        foreach ($this->personas as $key => $user) {
            $user = $user->fresh();
            $this->assertNotNull($user, "{$key} : le compte reste");
            $this->assertSame('fr', $user->preferred_locale, "{$key} : le profil humain reste (reused, pas de snapshot/restore)");
            // T1274-FIX : la ligne created par le pack est purgee ET la
            // balance realignee — jamais l'une sans l'autre.
            $this->assertSame(0, $this->welcomeLines($user), "{$key} : la ligne welcome_bonus du pack est retiree");
            $this->assertSame(0, $user->points_balance, "{$key} : balance realignee sur le ledger restant (vide)");
        }
    }

    // =====================================================================
    // G. Balance / ledger — l'invariant a chaque etape du cycle de vie
    // =====================================================================

    private const ALL_100 = ['test_cyril' => 100, 'test_roger' => 100, 'test_kiran' => 100, 'test_sana' => 100];

    private const ALL_0 = ['test_cyril' => 0, 'test_roger' => 0, 'test_kiran' => 0, 'test_sana' => 0];

    public function test_ledger_g1_load_writes_four_welcome_lines_and_balances_equal_the_ledger(): void
    {
        $this->assertBalancesDeriveFromLedger(self::ALL_0, 'avant');

        $this->load();

        $this->assertSame(4, PointLedger::query()->where('reason', 'welcome_bonus')->count());
        $this->assertBalancesDeriveFromLedger(self::ALL_100, 'load');
        foreach ($this->personas as $key => $user) {
            $this->assertSame(1, $this->welcomeLines($user), $key);
        }
    }

    public function test_ledger_g2_load_repeated_duplicates_no_line_and_credits_nobody_twice(): void
    {
        $this->load();
        $ids = PointLedger::query()->orderBy('user_id')->pluck('id')->all();

        $this->load();
        $this->load();

        $this->assertSame($ids, PointLedger::query()->orderBy('user_id')->pluck('id')->all(), 'Les memes 4 lignes, pas une seconde.');
        $this->assertBalancesDeriveFromLedger(self::ALL_100, 'load bis');
        $this->assertSame(4, ScenarioPackEntity::query()->where('entity_type', 'point_ledger')->count());
    }

    public function test_ledger_g3_reset_after_load_equals_a_single_clean_load(): void
    {
        $this->load();
        $ids = PointLedger::query()->orderBy('user_id')->pluck('id')->all();

        $this->reset();

        $this->assertSame($ids, PointLedger::query()->orderBy('user_id')->pluck('id')->all());
        $this->assertSame(4, PointLedger::query()->count());
        $this->assertBalancesDeriveFromLedger(self::ALL_100, 'reset');
        $this->assertSame(4, ScenarioPackEntity::query()->where('entity_type', 'point_ledger')->where('ownership', ScenarioPackEntity::OWNERSHIP_CREATED)->count());
    }

    public function test_ledger_g4_reset_repeated_is_idempotent(): void
    {
        $this->load();
        $this->reset();
        $ids = PointLedger::query()->orderBy('user_id')->pluck('id')->all();

        $this->reset();
        $this->reset();

        $this->assertSame($ids, PointLedger::query()->orderBy('user_id')->pluck('id')->all());
        $this->assertSame(4, PointLedger::query()->count());
        $this->assertBalancesDeriveFromLedger(self::ALL_100, 'reset bis');
    }

    public function test_ledger_g5_delete_purges_the_pack_lines_and_realigns_balances_to_the_remaining_ledger(): void
    {
        $this->load();
        $this->assertBalancesDeriveFromLedger(self::ALL_100, 'load');

        $this->remove();

        $this->assertSame(0, PointLedger::query()->where('organization_id', $this->organization->id)->count());
        $this->assertBalancesDeriveFromLedger(self::ALL_0, 'delete');
        $this->assertSame(0, ScenarioPackEntity::query()->count());

        // Rechargeable proprement apres retrait : un nouveau cycle complet.
        $this->load();
        $this->assertBalancesDeriveFromLedger(self::ALL_100, 'load apres delete');
    }

    /**
     * Le test decisif : un persona `reused` avec un historique ANTERIEUR au
     * pack (+40 `adjustment`) le conserve integralement a chaque etape ; le
     * pack n'ajoute que sa ligne et ne retire que sa ligne. C'est lui qui
     * prouve que l'exception bornee a POLICY_BLOCK (purge d'une ligne
     * `created` uniquement) ne deborde jamais sur un ledger `reused`.
     */
    public function test_ledger_g6_a_reused_persona_keeps_its_prior_history_through_load_reset_and_delete(): void
    {
        $kiran = $this->personas['test_kiran'];
        $prior = PointLedger::create([
            'user_id' => $kiran->id,
            'transaction_id' => null,
            'delta' => 40,
            'organization_id' => $this->organization->id,
            'reason' => 'adjustment',
        ]);
        $kiran->update(['points_balance' => 40]);
        $expected = ['test_cyril' => 100, 'test_roger' => 100, 'test_kiran' => 140, 'test_sana' => 100];

        $this->load();
        $this->assertBalancesDeriveFromLedger($expected, 'load');
        $this->assertSame(2, PointLedger::query()->where('user_id', $kiran->id)->count(), '+40 anterieur, +100 du pack');

        $this->load();
        $this->reset();
        $this->reset();
        $this->assertBalancesDeriveFromLedger($expected, 'load bis / reset / reset bis');
        $this->assertSame(2, PointLedger::query()->where('user_id', $kiran->id)->count());
        $this->assertNull(ScenarioPackEntity::query()->where('entity_model', PointLedger::class)->where('entity_id', $prior->id)->first(), 'La ligne anterieure n\'est jamais inscrite au registre.');

        $this->remove();

        $this->assertTrue(PointLedger::query()->whereKey($prior->id)->exists(), 'Le ledger anterieur (reused) n\'est JAMAIS supprime.');
        $this->assertSame(0, $this->welcomeLines($kiran), 'Seule la ligne du pack est retiree.');
        $this->assertBalancesDeriveFromLedger(['test_cyril' => 0, 'test_roger' => 0, 'test_kiran' => 40, 'test_sana' => 0], 'delete');
        $this->assertSame(40, $kiran->fresh()->points_balance);
    }

    // =====================================================================
    // F. Le responder existant sait utiliser un profil publie
    // =====================================================================

    public function test_rule_based_responder_answers_from_the_published_profile_without_any_provider(): void
    {
        config(['ai.openrouter.enabled' => false, 'ai.openai.supervision_enabled' => false, 'ai.ollama.enabled' => false]);
        $this->load();

        $responder = app(MemberProfileAgentResponder::class);

        $cyril = MemberAiProfile::query()->where('user_id', $this->personas['test_cyril']->id)->firstOrFail();
        $answer = $responder->answerRuleBased($cyril, 'Quelles sont tes compétences ?');
        $this->assertSame('rule_based', $answer['provider']);
        $this->assertStringContainsString('Télétravail', $answer['response']);

        $sana = MemberAiProfile::query()->where('user_id', $this->personas['test_sana']->id)->firstOrFail();
        $answer = $responder->answerRuleBased($sana, 'Quelles sont tes limites ?');
        $this->assertStringContainsString('Pas d\'astrophysique', $answer['response']);

        Http::assertNothingSent();
    }

    public function test_visitor_chat_sends_the_published_profile_to_the_provider_and_bills_one_ledger_line(): void
    {
        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'openrouter' => ['router/catalogued' => ['input_per_1m' => 2.0, 'output_per_1m' => 2.0]],
                'rule_based' => ['*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true]],
            ],
            'ai.supervision_resolver.economic_guard.monthly_budget_usd' => 2.00,
            'ai.supervision_resolver.economic_guard.monthly_unknown_limit' => 10,
            'ai.default_provider' => 'openrouter',
            'ai.default_model' => null,
            'ai.openrouter.enabled' => true,
            'ai.openrouter.api_key' => 'platform-openrouter-key',
            'ai.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'ai.openrouter.model' => 'router/catalogued',
            'ai.openai.supervision_enabled' => false,
            'ai.ollama.enabled' => false,
        ]);
        $this->load();

        Http::fake([
            'openrouter.test/*' => Http::response([
                'model' => 'router/catalogued',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Cyril aide à structurer le télétravail d\'une équipe.']]],
                'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 50],
            ]),
        ]);

        app()->instance('current_organization', $this->organization);
        app()->setLocale('fr');

        $cyril = $this->personas['test_cyril']->fresh();
        $sana = $this->personas['test_sana']->fresh();

        Livewire::actingAs($sana)
            ->test(AiAgentChat::class, ['user' => $cyril])
            ->set('question', 'Peux-tu m\'aider à structurer le télétravail de mon équipe ?')
            ->call('sendMessage')
            ->assertSet('error', null)
            ->assertSee('structurer le télétravail');

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $system = collect($request['messages'] ?? [])->firstWhere('role', 'system')['content'] ?? '';

            return str_contains($system, 'Télétravail')
                && str_contains($system, 'Fondateur de CyberWorkers')
                && str_contains($system, 'Direct, concret, orienté action')
                && str_contains($system, 'Pas de développement logiciel');
        });

        $this->assertSame(1, AiProviderInvocation::query()->count(), 'Un appel facture = une ligne de ledger IA.');
    }
}
