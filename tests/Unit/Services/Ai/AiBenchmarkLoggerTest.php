<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Logging\AiBenchmarkLogger;
use Tests\TestCase;

class AiBenchmarkLoggerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/ai-benchmark-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_logger_creates_directory_and_writes_jsonl(): void
    {
        $logger = new AiBenchmarkLogger($this->tmpDir);
        $logger->log([
            'timestamp' => '2026-06-10T22:00:00+02:00',
            'scenario_id' => 'test_scenario',
            'model' => 'gpt-4o-mini',
            'content' => 'Hello',
            'output' => ['result' => 'ok'],
        ]);

        $filePath = $this->tmpDir . '/test_scenario.jsonl';
        $this->assertFileExists($filePath);

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        $this->assertSame('test_scenario', $decoded['scenario_id']);
        $this->assertSame('gpt-4o-mini', $decoded['model']);
    }

    public function test_logger_appends_to_existing_file(): void
    {
        $logger = new AiBenchmarkLogger($this->tmpDir);
        $logger->log(['scenario_id' => 'append_test', 'run' => 1]);
        $logger->log(['scenario_id' => 'append_test', 'run' => 2]);

        $lines = file($this->tmpDir . '/append_test.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(2, $lines);
    }

    public function test_logger_does_not_throw_on_unwritable_directory(): void
    {
        $logger = new AiBenchmarkLogger('/root/cannot-write-here');
        $logger->log(['scenario_id' => 'test', 'data' => 'should not crash']);
        $this->assertTrue(true);
    }

    public function test_logger_filters_path_traversal_in_scenario_id(): void
    {
        $logger = new AiBenchmarkLogger($this->tmpDir);
        $logger->log(['scenario_id' => '../../etc/passwd', 'data' => 'test']);

        $this->assertFileExists($this->tmpDir . '/.._.._etc_passwd.jsonl');
    }

    public function test_logger_uses_unknown_for_missing_scenario_id(): void
    {
        $logger = new AiBenchmarkLogger($this->tmpDir);
        $logger->log(['model' => 'test']);

        $this->assertFileExists($this->tmpDir . '/unknown.jsonl');
    }
}
