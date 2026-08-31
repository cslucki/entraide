<?php

namespace Tests\Feature;

use App\Models\AdminAiPrompt;
use Database\Seeders\AiPromptSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1350 — provisionnement de la v3 du prompt `clarify_help_request`.
 *
 * La v3 est ce qui rend `interaction_fit` autoritaire. Elle doit donc s'activer
 * sur les installations existantes, SANS jamais ecraser une decision
 * administrative. La regle retenue est prouvable, et c'est tout l'objet de ce
 * fichier : la v3 s'active si — et seulement si — le prompt actuellement actif
 * est la v2 telle que la migration du 15/08 l'a ecrite, OCTET POUR OCTET. Des
 * qu'un humain a touche a ce texte, choisi une autre version, ou laisse une
 * situation ambigue, la v3 arrive INACTIVE et attend un clic dans
 * `/admin/ai-prompts`.
 *
 * Le precedent est {@see TASK1211ClarifyPromptProvisioningTest} : meme style,
 * meme exigence, memes preuves par `getRawOriginal()`.
 */
class TASK1350ClarifyPromptV3ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_V2 = 'database/migrations/2026_08_15_111450_provision_clarify_help_request_v2_admin_ai_prompt.php';

    private const MIGRATION_V3 = 'database/migrations/2026_08_31_220000_provision_clarify_help_request_v3_admin_ai_prompt.php';

    /**
     * 1. Le cas nominal : la v2 provisionnee par le code, jamais editee, est
     * remplacee par la v3 — et n'est pas detruite pour autant.
     */
    public function test_an_untouched_code_provisioned_v2_hands_authority_to_v3(): void
    {
        $this->deleteClarifyPrompts();
        $this->migration(self::MIGRATION_V2)->up();

        $v2Text = $this->clarifyVersion(2)->prompt_text;
        $this->assertTrue($this->clarifyVersion(2)->is_active);

        $this->migration(self::MIGRATION_V3)->up();

        $v3 = $this->clarifyVersion(3);
        $this->assertTrue($v3->is_active, 'La v3 doit prendre l\'autorite.');
        $this->assertTrue(Str::isUuid($v3->id));
        $this->assertStringContainsString('interaction_fit', $v3->prompt_text);

        // La v2 est desactivee, pas reecrite ni supprimee : l'historique reste.
        $v2 = $this->clarifyVersion(2);
        $this->assertFalse($v2->is_active);
        $this->assertSame($v2Text, $v2->prompt_text);
    }

    /**
     * 2. La garde essentielle : un texte v2 EDITE par un administrateur n'est ni
     * remplace, ni desactive. La v3 existe, inactive, et attend un humain.
     */
    public function test_an_admin_edited_active_v2_keeps_its_authority(): void
    {
        $this->deleteClarifyPrompts();
        $v2 = $this->createPrompt(version: 2, active: true, text: 'TEXTE V2 REECRIT PAR UN ADMIN');
        $before = $v2->fresh()->getRawOriginal();

        $this->migration(self::MIGRATION_V3)->up();

        $this->assertSame($before, $v2->fresh()->getRawOriginal(), 'Une decision admin ne s\'ecrase pas.');
        $this->assertFalse($this->clarifyVersion(3)->is_active);
    }

    /** 3. Une v2 dont seul un espace a change n'est PAS la copie du code. */
    public function test_a_v2_differing_by_a_single_character_is_not_replaced(): void
    {
        $this->deleteClarifyPrompts();
        $this->migration(self::MIGRATION_V2)->up();

        $v2 = $this->clarifyVersion(2);
        $v2->forceFill(['prompt_text' => $v2->prompt_text.' '])->save();

        $this->migration(self::MIGRATION_V3)->up();

        $this->assertFalse($this->clarifyVersion(3)->is_active);
        $this->assertTrue($this->clarifyVersion(2)->fresh()->is_active);
    }

    /** 4. Une version active autre que la v2 garde l'autorite. */
    public function test_an_active_version_other_than_v2_keeps_authority(): void
    {
        $this->deleteClarifyPrompts();
        $v1 = $this->createPrompt(version: 1, active: true, text: 'ADMIN V1 ACTIVE');
        $before = $v1->fresh()->getRawOriginal();

        $this->migration(self::MIGRATION_V3)->up();

        $this->assertSame($before, $v1->fresh()->getRawOriginal());
        $this->assertFalse($this->clarifyVersion(3)->is_active);
    }

    /** 5. Deux lignes actives = situation ambigue : on ne tranche pas a la place d'un humain. */
    public function test_an_ambiguous_double_activation_is_left_alone(): void
    {
        $this->deleteClarifyPrompts();
        $this->migration(self::MIGRATION_V2)->up();
        $extra = $this->createPrompt(version: 5, active: true, text: 'AUTRE ACTIF');

        $this->migration(self::MIGRATION_V3)->up();

        $this->assertFalse($this->clarifyVersion(3)->is_active);
        $this->assertTrue($this->clarifyVersion(2)->is_active);
        $this->assertTrue($extra->fresh()->is_active);
    }

    /**
     * 6. Aucune ligne active : la capability est volontairement eteinte. On
     * n'invente pas un prompt actif — c'est l'arbitrage explicite de MASTER.
     */
    public function test_a_deliberately_disabled_capability_is_not_switched_back_on(): void
    {
        $this->deleteClarifyPrompts();
        $v2 = $this->createPrompt(version: 2, active: false, text: 'V2 DESACTIVEE PAR ADMIN');

        $this->migration(self::MIGRATION_V3)->up();

        $this->assertFalse($v2->fresh()->is_active);
        $this->assertFalse($this->clarifyVersion(3)->is_active);
    }

    /** 7. Une v3 deja presente est une donnee admin : la migration n'y retouche jamais. */
    public function test_an_existing_v3_is_never_touched_and_never_duplicated(): void
    {
        $this->deleteClarifyPrompts();
        $v3 = $this->createPrompt(version: 3, active: false, text: 'V3 ADMINISTREE');
        $before = $v3->fresh()->getRawOriginal();

        $this->migration(self::MIGRATION_V3)->up();
        $this->migration(self::MIGRATION_V3)->up();

        $this->assertSame($before, $v3->fresh()->getRawOriginal());
        $this->assertSame(1, AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 3)
            ->count());
    }

    /** 8. Deux executions logiques ne dupliquent ni ne rejouent l'activation. */
    public function test_a_second_execution_is_idempotent(): void
    {
        $this->deleteClarifyPrompts();
        $this->migration(self::MIGRATION_V2)->up();

        $this->migration(self::MIGRATION_V3)->up();
        $before = $this->clarifyVersion(3)->getRawOriginal();
        $this->migration(self::MIGRATION_V3)->up();

        $this->assertSame($before, $this->clarifyVersion(3)->getRawOriginal());
        $this->assertSame(1, AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 3)
            ->count());
    }

    /** 9. `down()` est conservateur : il ne detruit aucune donnee admin. */
    public function test_down_is_conservative(): void
    {
        $this->deleteClarifyPrompts();
        $this->migration(self::MIGRATION_V2)->up();

        $migration = $this->migration(self::MIGRATION_V3);
        $migration->up();
        $before = $this->clarifyVersion(3)->getRawOriginal();

        $migration->down();

        $this->assertSame($before, $this->clarifyVersion(3)->getRawOriginal());
    }

    /** 10. Migration et seeder portent EXACTEMENT le meme texte v3. */
    public function test_the_migration_and_the_seeder_keep_the_same_v3_text(): void
    {
        $migrationPrompt = (new \ReflectionClass($this->migration(self::MIGRATION_V3)))
            ->getReflectionConstant('PROMPT')
            ->getValue();

        $this->deleteClarifyPrompts();

        $this->seed(AiPromptSeeder::class);

        $this->assertSame($migrationPrompt, $this->clarifyVersion(3)->prompt_text);
    }

    /**
     * 11. La copie de reference de la v2 embarquee dans la migration v3 est
     * EXACTEMENT celle de la migration v2. Sans cela, la comparaison
     * « texte jamais edite » ne prouverait rien.
     */
    public function test_the_embedded_v2_reference_matches_the_v2_migration(): void
    {
        $v2 = (new \ReflectionClass($this->migration(self::MIGRATION_V2)))
            ->getReflectionConstant('PROMPT')
            ->getValue();

        $embedded = (new \ReflectionClass($this->migration(self::MIGRATION_V3)))
            ->getReflectionConstant('V2_PROMPT')
            ->getValue();

        $this->assertSame($v2, $embedded);
    }

    /** 12. Aucune dependance a un modele mutable : meme regle que la migration v2. */
    public function test_the_migration_has_no_mutable_model_dependency(): void
    {
        $source = file_get_contents(base_path(self::MIGRATION_V3));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('App\\Models', $source);
        $this->assertStringNotContainsString('AdminAiPrompt::', $source);
    }

    /** 13. Le seeder ne laisse qu'une version active, et c'est la plus haute. */
    public function test_the_seeder_leaves_exactly_one_active_version(): void
    {
        $this->deleteClarifyPrompts();

        $this->seed(AiPromptSeeder::class);

        $actives = AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('is_active', true)
            ->get();

        $this->assertCount(1, $actives);
        $this->assertSame(3, (int) $actives->first()->version);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function deleteClarifyPrompts(): void
    {
        AdminAiPrompt::query()->where('scenario_id', 'clarify_help_request')->delete();
    }

    private function clarifyVersion(int $version): AdminAiPrompt
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', $version)
            ->first();

        $this->assertInstanceOf(AdminAiPrompt::class, $prompt, "La v{$version} doit exister.");

        return $prompt;
    }

    /** @param  array<string, mixed>|null  $metadata */
    private function createPrompt(int $version, bool $active, string $text, ?array $metadata = null): AdminAiPrompt
    {
        return AdminAiPrompt::create([
            'scenario_id' => 'clarify_help_request',
            'name' => 'Prompt admin v'.$version,
            'description' => 'Cree par un administrateur.',
            'prompt_text' => $text,
            'version' => $version,
            'is_active' => $active,
            'metadata' => $metadata,
        ]);
    }

    private function migration(string $path): Migration
    {
        $migration = require base_path($path);

        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
