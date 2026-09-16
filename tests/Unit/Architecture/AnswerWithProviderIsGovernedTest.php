<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * TASK-1578 / CDC-01 V0-K — « answerWithProvider rend des comptes ».
 *
 * Verdict de l'audit (FACT sur `a327609d`) : `MemberProfileAgentResponder::
 * answerWithProvider()` ne porte NI garde NI ledger — et n'a pas a les porter :
 * son unique appelant applicatif (`answerUnderEconomicAuthority()`) l'execute
 * dans `SupervisionEconomicAuthority::attempt()` (ledger, une tentative), apres
 * `authorize()` (garde). Le second chemin provider du fichier
 * (`chatWithSetupPrompt()`) suit la meme forme. Les appels HTTP bruts sont
 * `private`, PHP les enferme dans ce fichier.
 *
 * Le soupcon du CDC (§12 « sans garde ni ledger visibles ») venait d'une
 * lecture de la methode seule ; la gouvernance est chez l'appelant, comme
 * pour tout `attempt()`. Ce test fige ce FACT : le jour ou un second appel a
 * `answerWithProvider(` apparait hors d'`attempt()`, ou ou la methode devient
 * appelable ailleurs sans autorite, il rougit.
 *
 * Risque residuel documente : la methode est `public` (deux tests l'appellent
 * en direct, sous `Http::fake`, pour verifier le prompt — aucun ledger n'y est
 * attendu). Une visibilite reduite casserait ces tests sans rien gouverner de
 * plus : non retenue en V0-K.
 */
class AnswerWithProviderIsGovernedTest extends TestCase
{
    private const FILE = 'app/Services/Ai/MemberProfileAgentResponder.php';

    public function test_answer_with_provider_has_one_caller_in_app_and_it_runs_under_attempt(): void
    {
        $root = dirname(__DIR__, 3);
        $callers = [];

        foreach ($this->phpFiles($root.'/app') as $path) {
            $relative = 'app/'.str_replace($root.'/app/', '', $path);
            $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

            foreach ($lines as $i => $line) {
                if (str_contains($line, 'answerWithProvider(') && ! str_contains($line, 'function answerWithProvider(') && ! preg_match('/^\s*(\*|\/\/)/', $line)) {
                    $callers[] = [$relative, $i + 1, $lines];
                }
            }
        }

        $this->assertCount(1, $callers, 'answerWithProvider() doit avoir UN appelant applicatif : '.json_encode(array_map(fn (array $c): string => $c[0].':'.$c[1], $callers)));
        [$file, $line, $lines] = $callers[0];
        $this->assertSame(self::FILE, $file);

        // Dans les 6 lignes qui precedent l'appel : `->attempt(` (le ledger),
        // et plus haut dans la methode : `->authorize(` (la garde).
        $fenetre = implode("\n", array_slice($lines, max(0, $line - 7), 7));
        $this->assertStringContainsString('->attempt(', $fenetre, "l'appel a answerWithProvider() (:{$line}) n'est plus sous attempt()");

        $avant = implode("\n", array_slice($lines, max(0, $line - 40), 40));
        $this->assertStringContainsString('->authorize(', $avant, "aucune garde authorize() dans les 40 lignes avant l'appel (:{$line})");
    }

    public function test_every_raw_provider_call_of_the_responder_is_private(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/'.self::FILE);

        foreach (['callOllamaChat', 'callChatCompletions', 'callOllama', 'callOpenRouter', 'callOpenAiCompatible'] as $method) {
            $this->assertMatchesRegularExpression(
                '/private function '.$method.'\(/',
                $source,
                "{$method}() doit rester private : un appel HTTP brut ne sort de ce fichier que sous attempt()",
            );
        }

        // Et les deux portes publiques vers un provider passent chacune par attempt().
        $this->assertSame(2, preg_match_all('/->attempt\(/', $source), 'deux chemins provider, deux attempt()');
        $this->assertSame(2, preg_match_all('/->authorize\(/', $source), 'deux chemins provider, deux authorize()');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
