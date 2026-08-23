<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * TASK-1280 (P0) — tripwire : la facade Http est le SEUL transport sortant.
 *
 * La garde d'isolation reseau des tests (`Http::preventStrayRequests()` dans
 * Tests\TestCase::setUp) ne couvre que la facade Http de Laravel. Le
 * recensement TASK-1280 a etabli que TOUT l'egress de `app/` passe par elle :
 * zero SDK provider, zero Guzzle instancie directement, zero curl. Ce test
 * fige ce constat. S'il devient rouge, un transport contournant la facade a
 * ete introduit : la garde ne le bloquerait PAS et un test pourrait appeler
 * un provider reel par accident — exactement l'incident d'origine.
 *
 * Remede attendu : utiliser la facade Http. Si un jour un transport hors
 * facade devient indispensable, il faut AUSSI etendre la garde de
 * Tests\TestCase pour le neutraliser en testing — jamais assouplir ce test
 * sans cette contrepartie.
 */
class HttpTransportIsolationTest extends TestCase
{
    /**
     * Motifs interdits dans app/ : transports qui echapperaient a
     * Http::preventStrayRequests(). Libelles exacts -> raison.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN_PATTERNS = [
        '/\bGuzzleHttp\b/' => 'Guzzle direct (client, exception ou promesse) — passer par la facade Http',
        '/\bcurl_(init|exec|setopt|multi_init)\s*\(/' => 'curl natif — passer par la facade Http',
        '/\bfsockopen\s*\(/' => 'socket brut — passer par la facade Http',
        '/\bstream_socket_client\s*\(/' => 'socket brut — passer par la facade Http',
        '/\bfile_get_contents\s*\(\s*[\'"]https?:/' => 'file_get_contents vers une URL — passer par la facade Http',
        '/\bnew\s+\\\\?(Illuminate\\\\Http\\\\Client\\\\)?PendingRequest\s*\(/' => 'PendingRequest instancie hors factory — echappe a preventStrayRequests()',
        '/\bnew\s+\\\\?Illuminate\\\\Http\\\\Client\\\\Factory\s*\(/' => 'Factory Http parallele — echappe a preventStrayRequests()',
        '/\bOpenAI\\\\(Client|Factory)\b/' => 'SDK OpenAI PHP — transport propre non couvert par la garde',
        '/\bAnthropic\\\\/' => 'SDK Anthropic — transport propre non couvert par la garde',
    ];

    public function test_app_code_has_no_http_transport_outside_the_facade(): void
    {
        $appDir = dirname(__DIR__, 3).'/app';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $lines = file($file->getPathname());

            if ($lines === false) {
                continue;
            }

            foreach ($lines as $number => $line) {
                foreach (self::FORBIDDEN_PATTERNS as $pattern => $reason) {
                    if (preg_match($pattern, $line)) {
                        $violations[] = sprintf(
                            '%s:%d — %s — %s',
                            str_replace($appDir.'/', 'app/', $file->getPathname()),
                            $number + 1,
                            trim($line),
                            $reason,
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Transport HTTP hors facade detecte dans app/ — la garde d'isolation "
            ."reseau des tests (TASK-1280) ne le couvrirait pas :\n"
            .implode("\n", $violations),
        );
    }
}
