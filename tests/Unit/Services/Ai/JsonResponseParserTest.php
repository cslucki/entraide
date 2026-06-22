<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Exceptions\SupervisionException;
use App\Services\Ai\JsonResponseParser;
use PHPUnit\Framework\TestCase;

class JsonResponseParserTest extends TestCase
{
    public function test_parse_valid_json(): void
    {
        $json = json_encode([
            'summary' => 'Test summary',
            'risk_level' => 'medium',
            'category' => ['slug' => 'design', 'label' => 'Design'],
            'skills' => [['slug' => 'test', 'label' => 'Test']],
            'unmatched_terms' => ['foo'],
            'needs_human_category_review' => true,
            'category_review_reason' => 'reason',
            'recommendations' => ['rec1'],
            'moderation_flag' => false,
            'notes' => 'note',
        ]);

        $result = JsonResponseParser::parseSupervisionResult($json);

        $this->assertSame('Test summary', $result['summary']);
        $this->assertSame('medium', $result['risk_level']);
        $this->assertSame(['slug' => 'design', 'label' => 'Design'], $result['category']);
        $this->assertSame([['slug' => 'test', 'label' => 'Test']], $result['skills']);
        $this->assertSame(['foo'], $result['unmatched_terms']);
        $this->assertTrue($result['needs_human_category_review']);
        $this->assertSame('reason', $result['category_review_reason']);
        $this->assertSame(['rec1'], $result['recommendations']);
        $this->assertFalse($result['moderation_flag']);
        $this->assertSame('note', $result['notes']);
    }

    public function test_extract_json_from_markdown_fence(): void
    {
        $text = "Voici le résultat :\n\n```json\n".json_encode(['summary' => 'OK'])."\n```\nMerci.";

        $result = JsonResponseParser::parseSupervisionResult($text);

        $this->assertSame('OK', $result['summary']);
    }

    public function test_extract_json_without_markdown_fence(): void
    {
        $text = 'Some preamble '.json_encode(['summary' => 'OK']).' some postamble';

        $result = JsonResponseParser::parseSupervisionResult($text);

        $this->assertSame('OK', $result['summary']);
    }

    public function test_throws_on_invalid_json(): void
    {
        $this->expectException(SupervisionException::class);
        $this->expectExceptionMessage('Sortie JSON non décodable');

        JsonResponseParser::parseSupervisionResult('not json at all');
    }

    public function test_defaults_for_missing_fields(): void
    {
        $json = json_encode(['summary' => 'Only summary']);

        $result = JsonResponseParser::parseSupervisionResult($json);

        $this->assertSame('Only summary', $result['summary']);
        $this->assertSame('low', $result['risk_level']);
        $this->assertSame(['slug' => 'autre', 'label' => 'Autre'], $result['category']);
        $this->assertSame([], $result['skills']);
        $this->assertSame([], $result['unmatched_terms']);
        $this->assertFalse($result['needs_human_category_review']);
        $this->assertSame('', $result['category_review_reason']);
        $this->assertSame([], $result['recommendations']);
        $this->assertFalse($result['moderation_flag']);
        $this->assertSame('', $result['notes']);
    }

    public function test_coerces_types(): void
    {
        $json = json_encode([
            'summary' => 123,
            'risk_level' => 456,
            'needs_human_category_review' => 1,
            'moderation_flag' => 0,
            'unmatched_terms' => 'not-array',
            'skills' => 'not-array',
        ]);

        $result = JsonResponseParser::parseSupervisionResult($json);

        $this->assertSame('123', $result['summary']);
        $this->assertSame('456', $result['risk_level']);
        $this->assertTrue($result['needs_human_category_review']);
        $this->assertFalse($result['moderation_flag']);
        $this->assertSame([], $result['unmatched_terms']);
        $this->assertSame([], $result['skills']);
    }

    public function test_normalizes_category_with_empty_slug(): void
    {
        $json = json_encode([
            'category' => ['slug' => '', 'label' => 'Empty'],
        ]);

        $result = JsonResponseParser::parseSupervisionResult($json);

        $this->assertSame(['slug' => 'autre', 'label' => 'Empty'], $result['category']);
    }

    public function test_skips_invalid_skill_entries(): void
    {
        $json = json_encode([
            'skills' => [
                ['slug' => 'valid', 'label' => 'Valid'],
                'not-an-array',
                ['slug' => '', 'label' => 'Empty slug'],
            ],
        ]);

        $result = JsonResponseParser::parseSupervisionResult($json);

        $this->assertSame([['slug' => 'valid', 'label' => 'Valid']], $result['skills']);
    }

    public function test_parse_empty_string_throws_no_response_exception(): void
    {
        $this->expectException(SupervisionException::class);
        $this->expectExceptionMessage('Aucune réponse JSON reçue');

        JsonResponseParser::parseSupervisionResult('');
    }

    public function test_parse_truncated_json_throws_specific_error(): void
    {
        $this->expectException(SupervisionException::class);
        $this->expectExceptionMessage('Réponse JSON tronquée ou incomplète');

        JsonResponseParser::parseSupervisionResult('{"summary": "test", "risk_level": ');
    }
}
