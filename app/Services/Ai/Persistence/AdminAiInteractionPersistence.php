<?php

namespace App\Services\Ai\Persistence;

use App\Models\AdminAiInteraction;
use Illuminate\Support\Facades\Log;

class AdminAiInteractionPersistence
{
    public function persist(array $data): void
    {
        try {
            $record = [
                'organization_id' => $this->resolveOrganizationId(),
                'user_id' => $this->resolveUserId(),
                'scenario_id' => $data['scenario_id'] ?? 'unknown',
                'provider' => $data['provider'] ?? null,
                'model' => $data['model'] ?? null,
                'status' => $data['status'] ?? 'success',
                'input_excerpt' => $this->sanitizeExcerpt($data['input_excerpt'] ?? ($data['content'] ?? null)),
                'input_hash' => $data['input_hash'] ?? $this->hashInput($data['content'] ?? ($data['input_excerpt'] ?? null)),
                'input_length' => $data['input_length'] ?? ($data['content_length'] ?? 0),
                'result_summary' => $this->sanitizeExcerpt($data['result_summary'] ?? null, 2000),
                'result_payload' => $this->sanitizePayload($data['result_payload'] ?? null),
                'metadata' => $this->sanitizeMetadata($data['metadata'] ?? null),
                'input_tokens' => $data['input_tokens'] ?? 0,
                'output_tokens' => $data['output_tokens'] ?? 0,
                'latency_ms' => $data['latency_ms'] ?? null,
                'cost_usd' => $data['cost_usd'] ?? 0.0,
            ];

            AdminAiInteraction::create($record);
        } catch (\Throwable $e) {
            Log::warning('AdminAiInteractionPersistence: failed to persist interaction.', [
                'error' => $e->getMessage(),
                'scenario_id' => $data['scenario_id'] ?? 'unknown',
            ]);
        }
    }

    private function resolveOrganizationId(): ?string
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if (! $org && auth()->check()) {
            $org = auth()->user()?->organization;
        }

        return $org?->id;
    }

    private function resolveUserId(): ?string
    {
        return auth()->check() ? auth()->id() : null;
    }

    private function sanitizeExcerpt(?string $input, int $maxLength = 500): ?string
    {
        if ($input === null) {
            return null;
        }

        $cleaned = mb_substr(strip_tags($input), 0, $maxLength);

        return $cleaned;
    }

    private function hashInput(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        return hash('sha256', $input);
    }

    private function sanitizePayload(mixed $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $array = is_array($payload) ? $payload : json_decode(json_encode($payload), true);

        if (! is_array($array)) {
            return null;
        }

        $forbiddenKeys = [
            'input_content', 'content', 'output', 'prompt', 'response',
            'raw_response', 'system_prompt', 'user_prompt', 'api_key',
            'bearer', 'authorization', 'secret', 'token', 'password',
        ];

        return array_diff_key($array, array_flip($forbiddenKeys));
    }

    private function sanitizeMetadata(mixed $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $array = is_array($metadata) ? $metadata : json_decode(json_encode($metadata), true);

        if (! is_array($array)) {
            return null;
        }

        $forbiddenKeys = [
            'api_key', 'bearer', 'authorization', 'secret', 'token', 'password',
            'raw_response', 'system_prompt', 'user_prompt', 'input_content',
        ];

        return array_diff_key($array, array_flip($forbiddenKeys));
    }
}
