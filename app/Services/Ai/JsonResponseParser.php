<?php

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\SupervisionException;

/**
 * Robust JSON response parser for AI supervision providers.
 *
 * Handles:
 * - Markdown JSON fences (```json ... ```)
 * - Preambles/postambles around JSON
 * - Missing fields with sensible defaults
 * - Type coercion for common mismatches
 * - Detailed error messages for debugging
 */
class JsonResponseParser
{
    /**
     * Extract a JSON object string from raw text that may contain markdown,
     * preambles, or other wrapping content.
     */
    public static function extractJsonFromText(string $text): string
    {
        $text = trim($text);

        // Try to extract from markdown JSON fence
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches)) {
            $candidate = trim($matches[1]);
            if (self::looksLikeJsonObject($candidate)) {
                return $candidate;
            }
        }

        // Find the first JSON object {...} in the text
        if (preg_match('/\{[\s\S]*\}/', $text, $matches)) {
            $candidate = $matches[0];
            if (self::looksLikeJsonObject($candidate)) {
                return $candidate;
            }
        }

        // Fallback: return the text as-is and let json_decode fail with a clear error
        return $text;
    }

    /**
     * Parse a supervision result JSON string into a validated array.
     *
     * @throws SupervisionException if JSON is invalid or required fields are missing
     */
    public static function parseSupervisionResult(string $jsonText): array
    {
        $jsonText = self::extractJsonFromText($jsonText);

        $decoded = json_decode($jsonText, true);

        if (! is_array($decoded)) {
            $error = json_last_error_msg();
            throw new SupervisionException(
                sprintf('Sortie JSON non décodable : %s. Extrait brut : %.500s', $error, $jsonText)
            );
        }

        return self::validateAndNormalize($decoded);
    }

    /**
     * Validate and normalize supervision result fields.
     *
     * Ensures all required fields exist with correct types and sensible defaults.
     */
    public static function validateAndNormalize(array $data): array
    {
        $requiredFields = [
            'summary' => ['type' => 'string', 'default' => ''],
            'risk_level' => ['type' => 'string', 'default' => 'low'],
            'category' => ['type' => 'array', 'default' => ['slug' => 'autre', 'label' => 'Autre']],
            'skills' => ['type' => 'array', 'default' => []],
            'unmatched_terms' => ['type' => 'array', 'default' => []],
            'needs_human_category_review' => ['type' => 'bool', 'default' => false],
            'category_review_reason' => ['type' => 'string', 'default' => ''],
            'recommendations' => ['type' => 'array', 'default' => []],
            'moderation_flag' => ['type' => 'bool', 'default' => false],
            'notes' => ['type' => 'string', 'default' => ''],
        ];

        foreach ($requiredFields as $field => $meta) {
            if (! array_key_exists($field, $data)) {
                $data[$field] = $meta['default'];
                continue;
            }

            $value = $data[$field];

            switch ($meta['type']) {
                case 'string':
                    if (! is_string($value)) {
                        $data[$field] = is_scalar($value) ? (string) $value : $meta['default'];
                    }
                    break;

                case 'array':
                    if (! is_array($value)) {
                        $data[$field] = $meta['default'];
                    }
                    break;

                case 'bool':
                    if (! is_bool($value)) {
                        $data[$field] = (bool) $value;
                    }
                    break;
            }
        }

        // Normalize category sub-fields
        if (is_array($data['category'])) {
            $slug = (string) ($data['category']['slug'] ?? 'autre');
            $label = (string) ($data['category']['label'] ?? 'Autre');
            if ($slug === '') {
                $slug = 'autre';
            }
            $data['category'] = [
                'slug' => $slug,
                'label' => $label,
            ];
        }

        // Normalize skills array
        if (is_array($data['skills'])) {
            $data['skills'] = array_values(array_filter(
                array_map(
                    fn ($s) => is_array($s) ? [
                        'slug' => (string) ($s['slug'] ?? ''),
                        'label' => (string) ($s['label'] ?? ''),
                    ] : null,
                    $data['skills']
                ),
                fn ($s) => $s !== null && $s['slug'] !== ''
            ));
        }

        // Normalize unmatched_terms and recommendations to string arrays
        foreach (['unmatched_terms', 'recommendations'] as $field) {
            if (is_array($data[$field])) {
                $data[$field] = array_values(array_map('strval', array_filter($data[$field], 'is_scalar')));
            }
        }

        return $data;
    }

    /**
     * Check if a string looks like a JSON object (starts with { and ends with }).
     */
    private static function looksLikeJsonObject(string $text): bool
    {
        $text = trim($text);
        return str_starts_with($text, '{') && str_ends_with($text, '}');
    }
}
