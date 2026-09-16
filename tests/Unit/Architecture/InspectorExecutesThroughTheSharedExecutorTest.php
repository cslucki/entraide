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

    public function test_publish_false_seams_are_called_only_by_the_executor(): void
    {
        $root = dirname(__DIR__, 3);
        $appelants = [];

        foreach ($this->phpFiles($root.'/app') as $path) {
            $relative = 'app/'.str_replace($root.'/app/', '', $path);
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $i => $line) {
                if (preg_match('/->(answer|answerHybrid|respondInThread)\(.*publish:\s*false/', $line) && ! preg_match('/^\s*(\*|\/\/)/', $line)) {
                    $appelants[$relative][] = $i + 1;
                }
            }
        }

        $this->assertSame([self::EXECUTOR], array_keys($appelants), 'seul AiTurnExecutor appelle un seam publish:false : '.json_encode($appelants));
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
