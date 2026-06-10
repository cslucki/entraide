<?php

namespace App\Services\Ai\Logging;

use Illuminate\Support\Facades\Log;

class AiBenchmarkLogger
{
    private readonly string $basePath;

    public function __construct(
        string $basePath = '',
    ) {
        $this->basePath = $basePath ?: storage_path('logs/ai-benchmarks');
    }

    public function log(array $entry): void
    {
        try {
            $scenarioId = $entry['scenario_id'] ?? 'unknown';

            if (!is_dir($this->basePath)) {
                @mkdir($this->basePath, 0755, true);
            }

            $filename = basename(str_replace(['/', '\\'], '_', $scenarioId));
            $filePath = $this->basePath . DIRECTORY_SEPARATOR . $filename . '.jsonl';

            file_put_contents(
                $filePath,
                json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable $e) {
            Log::warning('AiBenchmarkLogger: failed to write log entry.', [
                'error' => $e->getMessage(),
                'scenario_id' => $entry['scenario_id'] ?? 'unknown',
            ]);
        }
    }
}
