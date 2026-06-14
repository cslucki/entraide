<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class UrlPreviewService
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private const BLOCKED_HOSTS = [
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
        '[::1]',
        '::1',
    ];

    private const TIMEOUT = 5;

    private const MAX_BODY_SIZE = 2_000_000;

    private const MAX_REDIRECTS = 3;

    private const MAX_PARSE_SIZE = 100_000;

    public static function extractFirstUrl(string $text): ?string
    {
        if (preg_match('/https?:\/\/[^\s<>"\'\\\`()\[\]{}]+/u', $text, $matches)) {
            $url = trim($matches[0]);
            $url = rtrim($url, '.,;:!?)]}');

            return $url !== '' ? $url : null;
        }

        return null;
    }

    public function fetchPreview(string $url): ?array
    {
        $url = trim($url);

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host || $host === '') {
            return null;
        }

        $host = $this->normalizeHost($host);

        if (! $this->isHostAllowed($host)) {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => self::MAX_REDIRECTS,
                        'track_redirects' => true,
                    ],
                    'verify' => true,
                    'connect_timeout' => self::TIMEOUT,
                ])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; BoucleProBot/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $redirectHistory = $response->header('X-Guzzle-Redirect-History');
            if ($redirectHistory) {
                $redirectUrls = explode(', ', $redirectHistory);
                foreach ($redirectUrls as $redirectUrl) {
                    $redirectHost = $this->normalizeHost(parse_url(trim($redirectUrl), PHP_URL_HOST) ?? '');
                    if (! $this->isHostAllowed($redirectHost)) {
                        return null;
                    }
                }
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_BODY_SIZE) {
                $body = substr($body, 0, self::MAX_PARSE_SIZE);
            }

            return $this->parseHtml($body, $url, $host);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeHost(string $host): string
    {
        $host = trim($host);
        $host = rtrim($host, '.');

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return $host;
        }

        return $host;
    }

    private function isHostAllowed(string $host): bool
    {
        $lower = mb_strtolower($host);

        if (in_array($lower, self::BLOCKED_HOSTS, true)) {
            return false;
        }

        if (str_starts_with($lower, '[')) {
            return false;
        }

        $ip = null;
        if (filter_var($lower, FILTER_VALIDATE_IP)) {
            $ip = $lower;
        } else {
            $resolved = gethostbynamel($lower);
            if ($resolved !== false && count($resolved) > 0) {
                $ip = $resolved[0];
            }
        }

        if ($ip !== null) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }

            if ($this->isPrivateCidr($ip)) {
                return false;
            }
        }

        return true;
    }

    private function isPrivateCidr(string $ip): bool
    {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }

        $long = (float) sprintf('%u', $long);

        if ($long >= (float) sprintf('%u', ip2long('10.0.0.0')) && $long <= (float) sprintf('%u', ip2long('10.255.255.255'))) {
            return true;
        }

        if ($long >= (float) sprintf('%u', ip2long('172.16.0.0')) && $long <= (float) sprintf('%u', ip2long('172.31.255.255'))) {
            return true;
        }

        if ($long >= (float) sprintf('%u', ip2long('192.168.0.0')) && $long <= (float) sprintf('%u', ip2long('192.168.255.255'))) {
            return true;
        }

        return false;
    }

    private function parseHtml(string $html, string $url, string $host): array
    {
        $preview = [
            'url' => $url,
            'domain' => $host,
            'title' => null,
            'description' => null,
            'image' => null,
            'fetched_at' => now()->toIso8601String(),
        ];

        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $doc->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        $ogTitle = $this->getMetaContent($xpath, 'og:title');
        if ($ogTitle !== null) {
            $preview['title'] = $this->truncateText($ogTitle, 200);
        }

        $ogDescription = $this->getMetaContent($xpath, 'og:description');
        if ($ogDescription !== null) {
            $preview['description'] = $this->truncateText($ogDescription, 400);
        }

        if ($preview['title'] === null) {
            $titleNodes = $xpath->query('//title');
            if ($titleNodes !== false && $titleNodes->length > 0) {
                $preview['title'] = $this->truncateText(trim($titleNodes->item(0)->textContent), 200);
            }
        }

        $ogImage = $this->getMetaContent($xpath, 'og:image');
        if ($ogImage !== null) {
            $ogImage = $this->resolveUrl($ogImage, $url);
            if ($this->isImageUrlSafe($ogImage)) {
                $preview['image'] = $ogImage;
            }
        }

        return $preview;
    }

    private function getMetaContent(\DOMXPath $xpath, string $property): ?string
    {
        $nodes = $xpath->query("//meta[@property='".$property."']");
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query("//meta[@name='".$property."']");
        }
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $content = $nodes->item(0)->getAttribute('content');

        return $content !== '' ? trim($content) : null;
    }

    private function resolveUrl(string $maybeRelative, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $maybeRelative)) {
            return $maybeRelative;
        }

        if (str_starts_with($maybeRelative, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

            return $scheme.':'.$maybeRelative;
        }

        if (str_starts_with($maybeRelative, '/')) {
            $parts = parse_url($baseUrl);

            return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$maybeRelative;
        }

        return $maybeRelative;
    }

    private function isImageUrlSafe(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https') {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        $host = $this->normalizeHost($host);

        return $this->isHostAllowed($host);
    }

    private function truncateText(string $text, int $maxLength): string
    {
        $text = strip_tags($text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength);
    }
}
