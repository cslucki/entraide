<?php

namespace App\Support\Ai;

final class AiMarkdownSanitizer
{
    public static function sanitize(string $text, ?int $limit = null): string
    {
        $blocks = [];
        $text = self::protectFencedBlocks($text, $blocks);

        $text = self::stripScriptBlocks($text);
        $text = self::stripHtmlTags($text);
        $text = self::stripPhpTags($text);
        $text = self::stripBlade($text);
        $text = self::normalizeHeadings($text);
        $text = self::neutralizeUnsafeUrls($text);

        $text = self::collapseWhitespace($text);
        $text = self::restoreFencedBlocks($text, $blocks);

        if ($limit !== null && $limit > 0) {
            $text = self::truncate($text, $limit);
        }

        return $text;
    }

    private static function protectFencedBlocks(string $text, array &$blocks): string
    {
        return preg_replace_callback(
            '/```[a-z0-9_+-]*[ \t]*\r?\n.*?```[ \t]*/is',
            function (array $match) use (&$blocks): string {
                $blocks[] = $match[0];
                $index = count($blocks) - 1;

                return '[[AI_CODE_BLOCK:'.$index.']]';
            },
            $text,
        ) ?? $text;
    }

    private static function restoreFencedBlocks(string $text, array $blocks): string
    {
        foreach ($blocks as $index => $block) {
            $text = str_replace('[[AI_CODE_BLOCK:'.$index.']]', $block, $text);
        }

        return $text;
    }

    private static function stripScriptBlocks(string $text): string
    {
        return preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $text) ?? $text;
    }

    private static function stripHtmlTags(string $text): string
    {
        return preg_replace('/<\/?[a-zA-Z][^>]*>/', '', $text) ?? $text;
    }

    private static function stripPhpTags(string $text): string
    {
        $text = preg_replace('/<\?(?:php|=|xml)?[\s\S]*?\?>/i', '', $text) ?? $text;
        $text = preg_replace('/<%[\s\S]*?%>/', '', $text) ?? $text;
        $text = preg_replace('/<\?(?:php|=|xml)?|\/\?>/', '', $text) ?? $text;
        $text = preg_replace('/<\/?%/', '', $text) ?? $text;

        return $text;
    }

    private static function stripBlade(string $text): string
    {
        $text = preg_replace('/\{!!.*?!!\}/s', '', $text) ?? $text;
        $text = preg_replace('/\{\{.*?\}\}/s', '', $text) ?? $text;

        return $text;
    }

    private static function normalizeHeadings(string $text): string
    {
        return preg_replace_callback(
            '/^([ ]{0,3})(#{1,6})([ \t]+)/m',
            function (array $match): string {
                $level = strlen($match[2]);
                $level = $level === 1 ? 2 : ($level > 3 ? 3 : $level);

                return $match[1].str_repeat('#', $level).$match[3];
            },
            $text,
        ) ?? $text;
    }

    private static function neutralizeUnsafeUrls(string $text): string
    {
        return preg_replace_callback(
            '/!?\[([^\]]*)\]\(((?:[^()]|\([^)]*\))*)\)/',
            function (array $match): string {
                $isImage = str_starts_with($match[0], '!');
                $label = $match[1];
                $destination = trim($match[2]);
                $url = explode(' ', $destination, 2)[0];

                if (self::isSafeUrl($url)) {
                    return $match[0];
                }

                return $label;
            },
            $text,
        ) ?? $text;
    }

    private static function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        return in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);
    }

    private static function collapseWhitespace(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\h*\n\h*/', "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim((string) $text);
    }

    private static function truncate(string $text, int $limit): string
    {
        $cut = mb_substr($text, 0, $limit);
        $cut = rtrim($cut, "#*_`>~- \t");

        if (substr_count($cut, '```') % 2 === 1) {
            $cut .= "\n```";
        }

        return $cut;
    }
}
