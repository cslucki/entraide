<?php

namespace Tests\Feature;

use App\Models\AdminAiPrompt;
use App\Services\Ai\LoopMultiAiOrchestrator;
use Database\Seeders\AiPromptSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1627 — le prompt `loop_multi_ai` v8 est provisionne par MIGRATION.
 *
 * Le trou mesure en PRODUCTION le 2026-09-23 : `scenario_id = loop_multi_ai`
 * comptait ZERO ligne, alors que 26 autres scenarios etaient servis. Le module
 * « Pour / Contre » rendait donc « La reponse documentaire n'est pas configuree
 * (prompt administrable absent). » a la place d'un debat.
 *
 * Ce banc ne mesure PAS le prompt : il mesure le CHEMIN DE LIVRAISON. Aucun
 * appel provider, aucun `db:seed` dans le chemin nominal — c'est precisement
 * ce que la migration doit rendre inutile, puisque Laravel Cloud deploie avec
 * `php artisan migrate --force` et ne seede jamais.
 */
class TASK1627LoopMultiAiPromptProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_09_23_190000_provision_loop_multi_ai_v8_admin_ai_prompt.php';

    private const SCENARIO = 'loop_multi_ai';

    private const VERSION = 8;

    // ── LE TROU : ROUGE AVANT, VERT APRES ──────────────────────────────────

    public function test_un_environnement_sans_le_scenario_refuse_avant_la_migration(): void
    {
        $this->supprimerLeScenario();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('loops.knowledge_prompt_missing'));

        $this->instructionsDuMoteur();
    }

    public function test_apres_la_migration_le_moteur_obtient_un_prompt_actif_et_non_vide(): void
    {
        $this->supprimerLeScenario();

        $this->migration()->up();

        $v8 = $this->version(self::VERSION);
        $this->assertTrue($v8->is_active, 'v8 doit etre active a l issue de la migration');
        $this->assertNotSame('', trim((string) $v8->prompt_text), 'un prompt vide leve la meme exception qu une ligne absente');
        $this->assertTrue(Str::isUuid($v8->id), 'la migration pose elle-meme un UUID');

        // Le moteur ne se contente pas de trouver UNE ligne : il doit rendre
        // EXACTEMENT le texte de v8. Une v7 restee active rendrait un autre
        // socle sans qu aucune exception ne le signale.
        $this->assertSame($v8->prompt_text, $this->instructionsDuMoteur());
    }

    public function test_aucune_execution_du_seeder_n_est_requise(): void
    {
        $this->supprimerLeScenario();

        $this->migration()->up();

        $this->assertSame($this->version(self::VERSION)->prompt_text, $this->instructionsDuMoteur());

        // Et la migration ne doit pas DEPENDRE du seeder : sur Laravel Cloud,
        // `Database\Seeders` n est meme pas dans l autoload de production.
        // On mesure le CODE, pas la prose : le docbloc explique justement
        // pourquoi le seeder n est pas un chemin de livraison, et cette
        // explication a sa place la.
        $source = $this->sourceDeLaMigration();
        $this->assertStringNotContainsString('Database\\Seeders', $source);
        $this->assertStringNotContainsString('Seeder::class', $source);
        $this->assertStringNotContainsString('Artisan::', $source);
        $this->assertStringNotContainsString('->call(', $source);
    }

    // ── A. IDEMPOTENCE ─────────────────────────────────────────────────────

    public function test_un_second_passage_ne_cree_aucun_doublon_et_ne_retouche_rien(): void
    {
        $this->supprimerLeScenario();
        $migration = $this->migration();

        $migration->up();
        $avant = $this->version(self::VERSION)->getRawOriginal();

        $migration->up();

        $this->assertSame(1, AdminAiPrompt::query()
            ->where('scenario_id', self::SCENARIO)
            ->where('version', self::VERSION)
            ->count(), 'la cle logique (scenario_id, version) interdit le doublon');
        $this->assertSame($avant, $this->version(self::VERSION)->getRawOriginal(),
            'le second passage ne doit meme pas toucher updated_at');
    }

    // ── B. ISOLATION ───────────────────────────────────────────────────────

    public function test_aucune_ligne_d_un_autre_scenario_n_est_touchee(): void
    {
        $this->supprimerLeScenario();

        // Des voisins reels du depot, plus un intrus volontairement ACTIF sur
        // une version haute : si la desactivation debordait de son scenario,
        // c est lui qui tomberait en premier.
        $intrus = AdminAiPrompt::create([
            'scenario_id' => 'loop_hybrid_answer',
            'name' => 'Voisin hors scope',
            'description' => 'Ne doit pas changer.',
            'prompt_text' => 'TEXTE HORS SCOPE',
            'version' => 99,
            'is_active' => true,
            'metadata' => ['protected' => true],
        ]);

        $avant = $this->empreinteDesAutresScenarios();
        $this->assertNotEmpty($avant, 'le banc doit reellement porter des voisins, sinon il ne mesure rien');

        $this->migration()->up();

        $this->assertSame($avant, $this->empreinteDesAutresScenarios());
        $this->assertTrue($intrus->fresh()->is_active, 'un voisin actif en version 99 reste actif');
    }

    // ── C. COEXISTENCE HISTORIQUE ──────────────────────────────────────────

    public function test_v8_devient_la_seule_version_active_de_son_scenario(): void
    {
        $this->supprimerLeScenario();

        // Un poste local seede porte v1..v7. Plusieurs peuvent etre actives :
        // le service retiendrait la plus haute, mais l ecran d administration
        // afficherait plusieurs « actives » sans dire laquelle s applique.
        foreach (range(1, 7) as $version) {
            $this->creerVersion($version, active: true, texte: "HISTORIQUE V{$version}");
        }

        $this->migration()->up();

        $actives = AdminAiPrompt::query()
            ->where('scenario_id', self::SCENARIO)
            ->where('is_active', true)
            ->pluck('version')
            ->all();

        $this->assertSame([self::VERSION], $actives);

        // Eteintes, jamais supprimees ni reecrites : l historique reste lisible.
        $this->assertSame(8, AdminAiPrompt::query()->where('scenario_id', self::SCENARIO)->count());
        $this->assertSame('HISTORIQUE V7', $this->version(7)->prompt_text);
    }

    // ── D. ENVIRONNEMENT DEJA PROVISIONNE ──────────────────────────────────

    public function test_une_v8_administree_n_est_jamais_ecrasee(): void
    {
        $this->supprimerLeScenario();
        $v8 = $this->creerVersion(self::VERSION, active: true, texte: 'V8 REECRITE PAR L ADMIN', metadata: ['owner' => 'admin']);
        $avant = $v8->fresh()->getRawOriginal();

        $this->migration()->up();

        $this->assertSame($avant, $v8->fresh()->getRawOriginal());
    }

    public function test_une_v8_desactivee_par_un_admin_n_est_jamais_rallumee(): void
    {
        $this->supprimerLeScenario();
        $v8 = $this->creerVersion(self::VERSION, active: false, texte: 'V8 ETEINTE VOLONTAIREMENT');
        $v7 = $this->creerVersion(7, active: true, texte: 'V7 REMISE EN SERVICE PAR L ADMIN');

        $this->migration()->up();

        // Le no-op est TOTAL : ni reactivation de v8, ni extinction de v7.
        // Sortir avant la desactivation des soeurs est un choix, pas un oubli
        // — sur un environnement deja provisionne, la migration ne decide rien.
        $this->assertFalse($v8->fresh()->is_active);
        $this->assertTrue($v7->fresh()->is_active);
        $this->assertSame('V8 ETEINTE VOLONTAIREMENT', $v8->fresh()->prompt_text);
    }

    // ── E. FIDELITE DU TEXTE ───────────────────────────────────────────────

    public function test_la_migration_et_le_seeder_portent_le_meme_texte_v8(): void
    {
        $constante = (new ReflectionClass($this->migration()))
            ->getReflectionConstant('PROMPT')
            ->getValue();

        $this->supprimerLeScenario();
        $this->seed(AiPromptSeeder::class);

        // La source de verite du texte est le code deja merge, pas un rapport.
        // Si le socle evolue un jour cote seeder sans migration, ce test
        // rougit — c est exactement ce qu on veut lui faire dire.
        $this->assertSame($this->version(self::VERSION)->prompt_text, $constante);
    }

    // ── F. DOWN CONSERVATEUR ───────────────────────────────────────────────

    public function test_le_down_ne_detruit_aucune_donnee_administrable(): void
    {
        $this->supprimerLeScenario();
        $migration = $this->migration();
        $migration->up();
        $avant = $this->version(self::VERSION)->getRawOriginal();
        $voisins = $this->empreinteDesAutresScenarios();

        $migration->down();

        $this->assertSame($avant, $this->version(self::VERSION)->getRawOriginal());
        $this->assertSame($voisins, $this->empreinteDesAutresScenarios());
    }

    // ── G. LA MIGRATION NE DEPEND D AUCUN MODELE ───────────────────────────

    public function test_la_migration_ne_depend_d_aucun_modele_eloquent(): void
    {
        $source = $this->sourceDeLaMigration();

        $this->assertStringNotContainsString('App\\Models', $source);
        $this->assertStringNotContainsString('AdminAiPrompt::', $source);
    }

    // ── Outils ─────────────────────────────────────────────────────────────

    /**
     * Le vrai lecteur du prompt, atteint sans provider ni HTTP : c est lui qui
     * leve `loops.knowledge_prompt_missing`, donc c est lui qu il faut mesurer.
     */
    private function instructionsDuMoteur(): string
    {
        $methode = new ReflectionMethod(LoopMultiAiOrchestrator::class, 'capabilityInstructions');
        $methode->setAccessible(true);

        return $methode->invoke(app(LoopMultiAiOrchestrator::class), self::SCENARIO);
    }

    private function supprimerLeScenario(): void
    {
        AdminAiPrompt::query()->where('scenario_id', self::SCENARIO)->delete();
    }

    /** @return array<string, array<string, mixed>> */
    private function empreinteDesAutresScenarios(): array
    {
        return AdminAiPrompt::query()
            ->where('scenario_id', '!=', self::SCENARIO)
            ->orderBy('scenario_id')
            ->orderBy('version')
            ->get()
            ->mapWithKeys(fn (AdminAiPrompt $p): array => [
                $p->scenario_id.'#'.$p->version => $p->getRawOriginal(),
            ])
            ->all();
    }

    private function creerVersion(int $version, bool $active, string $texte, ?array $metadata = null): AdminAiPrompt
    {
        return AdminAiPrompt::create([
            'scenario_id' => self::SCENARIO,
            'name' => 'Pour / Contre v'.$version,
            'description' => 'Prompt administre avant migration.',
            'prompt_text' => $texte,
            'version' => $version,
            'is_active' => $active,
            'metadata' => $metadata,
        ]);
    }

    private function version(int $version): AdminAiPrompt
    {
        return AdminAiPrompt::query()
            ->where('scenario_id', self::SCENARIO)
            ->where('version', $version)
            ->firstOrFail();
    }

    private function sourceDeLaMigration(): string
    {
        $source = file_get_contents(base_path(self::MIGRATION));
        $this->assertIsString($source);

        return $source;
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }
}
