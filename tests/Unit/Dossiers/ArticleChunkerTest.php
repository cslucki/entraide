<?php

namespace Tests\Unit\Dossiers;

use App\Services\Dossiers\ArticleChunker;
use InvalidArgumentException;
use Tests\TestCase;

class ArticleChunkerTest extends TestCase
{
    private ArticleChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chunker = new ArticleChunker;
    }

    public function test_empty_text_returns_no_chunks(): void
    {
        $this->assertSame([], $this->chunker->chunk(''));
        $this->assertSame([], $this->chunker->chunk(" \n\t "));
    }

    public function test_short_text_returns_one_chunk_with_hash_and_token_count(): void
    {
        $chunks = $this->chunker->chunk('Alpha beta gamma.', targetSize: 10, overlap: 2);

        $this->assertCount(1, $chunks);
        $this->assertSame(0, $chunks[0]['chunk_index']);
        $this->assertSame('Alpha beta gamma.', $chunks[0]['content']);
        $this->assertSame(hash('sha256', 'Alpha beta gamma.'), $chunks[0]['content_hash']);
        $this->assertSame(3, $chunks[0]['token_count']);
    }

    public function test_text_is_split_into_stable_chunks_with_exact_overlap(): void
    {
        $chunks = $this->chunker->chunk('one two three four five six seven eight nine ten', targetSize: 4, overlap: 1);

        $this->assertSame([0, 1, 2, 3], array_column($chunks, 'chunk_index'));
        $this->assertSame('one two three four', $chunks[0]['content']);
        $this->assertSame('four five six seven', $chunks[1]['content']);
        $this->assertSame('seven eight nine ten', $chunks[2]['content']);
        $this->assertSame('ten', $chunks[3]['content']);
        $this->assertSame('four', $this->lastWord($chunks[0]['content']));
        $this->assertSame('four', $this->firstWord($chunks[1]['content']));
    }

    public function test_overlap_zero_advances_by_target_size(): void
    {
        $chunks = $this->chunker->chunk('one two three four five', targetSize: 2, overlap: 0);

        $this->assertSame(['one two', 'three four', 'five'], array_column($chunks, 'content'));
    }

    public function test_target_size_one_is_supported_when_overlap_is_zero(): void
    {
        $chunks = $this->chunker->chunk('one two three', targetSize: 1, overlap: 0);

        $this->assertSame(['one', 'two', 'three'], array_column($chunks, 'content'));
        $this->assertSame([1, 1, 1], array_column($chunks, 'token_count'));
    }

    public function test_chunks_are_never_empty_and_hashes_match_final_content(): void
    {
        $chunks = $this->chunker->chunk(" one   two\nthree   four ", targetSize: 2, overlap: 1);

        foreach ($chunks as $chunk) {
            $this->assertNotSame('', $chunk['content']);
            $this->assertSame(hash('sha256', $chunk['content']), $chunk['content_hash']);
            $this->assertSame(count(preg_split('/\s+/u', $chunk['content'], -1, PREG_SPLIT_NO_EMPTY)), $chunk['token_count']);
        }
    }

    public function test_chunking_is_deterministic(): void
    {
        $text = 'Alpha beta gamma delta epsilon zeta eta theta.';

        $this->assertSame(
            $this->chunker->chunk($text, targetSize: 3, overlap: 1),
            $this->chunker->chunk($text, targetSize: 3, overlap: 1)
        );
    }

    public function test_unicode_and_punctuation_are_preserved(): void
    {
        $chunks = $this->chunker->chunk('Équipe café, naïve! Привет мир. 世界 test?', targetSize: 4, overlap: 1);

        $this->assertStringContainsString('Équipe café, naïve!', $chunks[0]['content']);
        $this->assertStringContainsString('Привет мир.', implode(' ', array_column($chunks, 'content')));
        $this->assertStringContainsString('世界 test?', implode(' ', array_column($chunks, 'content')));
    }

    public function test_invalid_target_size_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->chunker->chunk('text', targetSize: 0);
    }

    public function test_negative_overlap_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->chunker->chunk('text', targetSize: 5, overlap: -1);
    }

    public function test_overlap_greater_than_or_equal_to_target_size_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->chunker->chunk('text', targetSize: 5, overlap: 5);
    }

    public function test_very_long_text_progresses_without_infinite_loop(): void
    {
        $text = implode(' ', array_map(fn (int $number) => 'word'.$number, range(1, 1000)));
        $chunks = $this->chunker->chunk($text, targetSize: 100, overlap: 10);

        $this->assertCount(12, $chunks);
        $this->assertSame(0, $chunks[0]['chunk_index']);
        $this->assertSame(11, $chunks[11]['chunk_index']);
        $this->assertSame('word1', $this->firstWord($chunks[0]['content']));
        $this->assertSame('word1000', $this->lastWord($chunks[11]['content']));
    }

    public function test_reconstruction_requires_removing_documented_overlap(): void
    {
        $chunks = $this->chunker->chunk('one two three four five six', targetSize: 3, overlap: 1);

        $this->assertSame(['one two three', 'three four five', 'five six'], array_column($chunks, 'content'));

        $reconstructed = $chunks[0]['content'].' four five six';

        $this->assertSame('one two three four five six', $reconstructed);
    }

    private function firstWord(string $text): string
    {
        return preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY)[0];
    }

    private function lastWord(string $text): string
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $words[array_key_last($words)];
    }
}
