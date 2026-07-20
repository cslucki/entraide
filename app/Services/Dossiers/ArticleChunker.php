<?php

namespace App\Services\Dossiers;

use InvalidArgumentException;

class ArticleChunker
{
    /**
     * MVP approximation: one token is estimated as one Unicode word.
     *
     * @return array<int, array{chunk_index: int, content: string, content_hash: string, token_count: int}>
     */
    public function chunk(string $text, int $targetSize = 500, int $overlap = 50): array
    {
        $this->validateWindow($targetSize, $overlap);

        $words = $this->words($text);

        if ($words === []) {
            return [];
        }

        $chunks = [];
        $step = $targetSize - $overlap;
        $wordCount = count($words);

        for ($offset = 0, $index = 0; $offset < $wordCount; $offset += $step, $index++) {
            $chunkWords = array_slice($words, $offset, $targetSize);
            $content = trim(implode(' ', $chunkWords));

            if ($content === '') {
                continue;
            }

            $chunks[] = [
                'chunk_index' => $index,
                'content' => $content,
                'content_hash' => hash('sha256', $content),
                'token_count' => count($chunkWords),
            ];
        }

        return $chunks;
    }

    private function validateWindow(int $targetSize, int $overlap): void
    {
        if ($targetSize <= 0) {
            throw new InvalidArgumentException('Chunk target size must be strictly positive.');
        }

        if ($overlap < 0) {
            throw new InvalidArgumentException('Chunk overlap must be zero or positive.');
        }

        if ($overlap >= $targetSize) {
            throw new InvalidArgumentException('Chunk overlap must be lower than target size.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function words(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return [];
        }

        preg_match_all('/\S+/u', $text, $matches);

        return $matches[0] ?? [];
    }
}
