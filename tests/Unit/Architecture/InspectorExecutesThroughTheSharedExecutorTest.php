<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * TASK-1585 — UNE seule voie d'execution observee : `AiTurnExecutor`.
 *
 *  - le controleur Inspector n'appelle jamais Artisan et n'appelle jamais les
 *    services de generation lui-meme ;
 *  - la CLI `ai:inspect-turn` n'appelle plus les services de generation
 *    directement : elle passe par l'executeur ;
 *  - `AiTurnExecutor` est le seul fichier hors services produit a appeler
 *    `answer(`/`answerHybrid(`/`respondInThread(` avec `publish: false`.
 *
 * Garde statique : si quelqu'un duplique le pipeline dans le web ou la CLI,
 * ce test le nomme.
 */
class InspectorExecutesThroughTheSharedExecutorTest extends TestCase
{
    private const CONTROLLER = 'app/Http/Controllers/Admin/AdminAiTurnController.php';

    private const CLI = 'app/Console/Commands/AiInspectTurnCommand.php';

    private const EXECUTOR = 'app/Support/Ai/AiTurnExecutor.php';

    public function test_the_controller_calls_neither_artisan_nor_the_generation_services(): void
    {
        $code = $this->code(self::CONTROLLER);

        $this->assertStringNotContainsString('Artisan::', $code);
        $this->assertStringNotContainsString('->call(', $code);
        $this->assertStringNotContainsString('LoopKnowledgeAnswerService', $code);
        $this->assertStringNotContainsString('ChatLoopAiService', $code);
        $this->assertStringContainsString('AiTurnExecutor', $code);
        $this->assertStringContainsString('->execute(', $code);
    }

    public function test_the_cli_no_longer_calls_the_generation_services_itself(): void
    {
        $code = $this->code(self::CLI);

        $this->assertStringContainsString('AiTurnExecutor', $code);
        $this->assertDoesNotMatchRegularExpression('/->(answer|answerHybrid|respondInThread)\(/', $code, 'la CLI doit passer par AiTurnExecutor');
    }

    /**
     * TASK-1591 (Option B, decision MASTER) — les seams `publish:` des services
     * de generation ne sont appeles avec un argument `publish` QUE par
     * `AiTurnExecutor::pipeline()` ; la valeur y est la variable privee
     * `$publish`, jamais un litteral hors de l'executeur.
     */
    public function test_publish_seams_are_called_only_by_the_executor(): void
    {
        $appelants = $this->lignes('/->(answer|answerHybrid|respondInThread)\(.*publish:\s*/');

        $this->assertSame([self::EXECUTOR], array_keys($appelants), 'seul AiTurnExecutor appelle un seam avec publish: '.json_encode($appelants));
        // Et dans l'executeur, jamais un `publish: true` litteral : la seule voie
        // vers la publication est `executeForLab()` qui pose `$publish = true`
        // dans le pipeline prive.
        $this->assertSame([], $this->lignes('/->(answer|answerHybrid|respondInThread)\(.*publish:\s*true/'), 'aucun seam publish: true litteral dans app/');
    }

    /**
     * TASK-1591 — `executeForLab()` : definie une fois dans l'executeur, appelee
     * UNIQUEMENT par le runner Lab. Inspector, CLI inspect, tout autre code
     * applicatif : jamais.
     */
    public function test_execute_for_lab_is_called_only_by_the_lab_runner(): void
    {
        $definitions = $this->lignes('/function executeForLab\(/');
        $this->assertSame([self::EXECUTOR], array_keys($definitions));

        $appels = $this->lignes('/->executeForLab\(/');
        $this->assertSame(['app/Support/AiLab/LabRunner.php'], array_keys($appels), 'seul le runner Lab publie : '.json_encode($appels));
        $this->assertStringNotContainsString('executeForLab', $this->code(self::CONTROLLER));
        $this->assertStringNotContainsString('executeForLab', $this->code(self::CLI));
        // La voie observee reste `execute()` — et elle ne connait pas la publication.
        $this->assertMatchesRegularExpression('/return \$this->pipeline\([^;]*,\s*null,\s*false\);/', $this->code(self::EXECUTOR), 'execute() passe publish=false, structurellement');
        $this->assertMatchesRegularExpression('/return \$this->pipeline\([^;]*AiTurnTrace::RUN_KIND_LAB,\s*\$labScenarioKey,\s*true\);/', $this->code(self::EXECUTOR), 'executeForLab() passe run_kind=lab et publish=true');
    }

    /** @return array<string, list<int>> fichier => lignes */
    private function lignes(string $regex): array
    {
        $root = dirname(__DIR__, 3);
        $trouves = [];

        foreach ($this->phpFiles($root.'/app') as $path) {
            $relative = 'app/'.str_replace($root.'/app/', '', $path);
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
                if (preg_match($regex, $line) && ! preg_match('/^\s*(\*|\/\/)/', $line)) {
                    $trouves[$relative][] = $i + 1;
                }
            }
        }
        ksort($trouves);

        return $trouves;
    }

    private function code(string $relative): string
    {
        $path = dirname(__DIR__, 3).'/'.$relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
