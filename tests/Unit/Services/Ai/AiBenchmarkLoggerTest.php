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
        $this->tmpDir = sys_get_temp_dir().'/ai-benchmark-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir.'/*');
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
            'input_tokens' => 150,
            'output_tokens' => 50,
            'latency_ms' => 123.45,
            'cost_usd' => 0.0015,
            'content_length' => 42,
            'status' => 'success',
        ]);

        $filePath = $this->tmpDir.'/test_scenario.jsonl';
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

        $lines = file($this->tmpDir.'/append_test.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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

        $this->assertFileExists($this->tmpDir.'/.._.._etc_passwd.jsonl');
    }

    public function test_logger_uses_unknown_for_missing_scenario_id(): void
    {
        $logger = new AiBenchmarkLogger($this->tmpDir);
        $logger->log(['model' => 'test']);

        $this->assertFileExists($this->tmpDir.'/unknown.jsonl');
    }

    public function test_logger_does_not_persist_input_content_field(): void
    {
        $logger = new AiBenchmarkLogger($this->tmpDir);
        $logger->log([
            'scenario_id' => 'safe_scenario',
            'input_content' => 'SHOULD NOT BE LOGGED',
            'content' => 'ALSO SHOULD NOT BE LOGGED',
            'output' => ['should_not' => 'be_here'],
            'input_tokens' => 100,
        ]);

        $lines = file($this->tmpDir.'/safe_scenario.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $decoded = json_decode($lines[0], true);

        $this->assertArrayNotHasKey('input_content', $decoded);
        $this->assertArrayNotHasKey('content', $decoded);
        $this->assertArrayNotHasKey('output', $decoded);
    }

    public function test_metrics_only_fields_are_present(): void
    {
        $logger = new AiBenchmarkLogger($this->tmpDir);
        $logger->log([
            'timestamp' => '2026-06-10T22:00:00+02:00',
            'scenario_id' => 'metrics_check',
            'model' => 'gpt-4o-mini',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'latency_ms' => 200.5,
            'cost_usd' => 0.001,
            'content_length' => 42,
            'status' => 'success',
        ]);

        $lines = file($this->tmpDir.'/metrics_check.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $decoded = json_decode($lines[0], true);

        $this->assertSame(100, $decoded['input_tokens']);
        $this->assertSame(50, $decoded['output_tokens']);
        $this->assertSame(200.5, $decoded['latency_ms']);
        $this->assertSame(0.001, $decoded['cost_usd']);
        $this->assertSame(42, $decoded['content_length']);
        $this->assertSame('success', $decoded['status']);
    }
}
