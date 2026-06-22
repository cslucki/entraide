<?php

namespace Tests\Unit\Services;

use App\Services\UrlPreviewService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UrlPreviewServiceTest extends TestCase
{
    private UrlPreviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UrlPreviewService;
    }

    public function test_extract_first_url_from_text(): void
    {
        $this->assertSame(
            'https://example.com/article',
            UrlPreviewService::extractFirstUrl('Check this out https://example.com/article it is cool')
        );
    }

    public function test_extract_first_url_returns_null_when_no_url(): void
    {
        $this->assertNull(UrlPreviewService::extractFirstUrl('Just a normal message without any URL'));
    }

    public function test_extract_first_url_ignores_email(): void
    {
        $this->assertNull(UrlPreviewService::extractFirstUrl('Contact me at user@example.com'));
    }

    public function test_extract_first_url_handles_trailing_punctuation(): void
    {
        $this->assertSame(
            'https://example.com/page',
            UrlPreviewService::extractFirstUrl('Visit https://example.com/page, it is great!')
        );
    }

    public function test_extract_first_url_returns_first_of_many(): void
    {
        $this->assertSame(
            'https://first.com',
            UrlPreviewService::extractFirstUrl('https://first.com and https://second.com')
        );
    }

    public function test_localhost_is_blocked(): void
    {
        Http::fake();

        $result = $this->service->fetchPreview('http://localhost:8080/page');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_127_dot_0_dot_0_dot_1_is_blocked(): void
    {
        Http::fake();

        $result = $this->service->fetchPreview('http://127.0.0.1/page');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_10_dot_x_is_blocked(): void
    {
        Http::fake();

        $result = $this->service->fetchPreview('http://10.0.0.5/page');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_172_dot_16_dot_x_is_blocked(): void
    {
        Http::fake();

        $result = $this->service->fetchPreview('http://172.16.0.50/page');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_192_dot_168_dot_x_is_blocked(): void
    {
        Http::fake();

        $result = $this->service->fetchPreview('http://192.168.1.1/page');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_dangerous_schemes_are_blocked(): void
    {
        Http::fake();

        $this->assertNull($this->service->fetchPreview('file:///etc/passwd'));
        $this->assertNull($this->service->fetchPreview('ftp://files.example.com/file'));
        $this->assertNull($this->service->fetchPreview('javascript:alert(1)'));
        $this->assertNull($this->service->fetchPreview('data:text/plain,hello'));

        Http::assertNothingSent();
    }

    public function test_empty_or_invalid_host_is_blocked(): void
    {
        Http::fake();

        $this->assertNull($this->service->fetchPreview(''));
        $this->assertNull($this->service->fetchPreview('not-a-url'));

        Http::assertNothingSent();
    }

    public function test_parse_og_title_description_and_image(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta property="og:title" content="Test Article Title" />
<meta property="og:description" content="This is a test article description for the preview." />
<meta property="og:image" content="https://example.com/image.jpg" />
<title>Fallback Title</title>
</head>
<body></body>
</html>
HTML;

        Http::fake([
            '*example.com*' => Http::response($html, 200),
        ]);

        $result = $this->service->fetchPreview('https://example.com/article');

        $this->assertNotNull($result);
        $this->assertSame('https://example.com/article', $result['url']);
        $this->assertSame('example.com', $result['domain']);
        $this->assertSame('Test Article Title', $result['title']);
        $this->assertSame('This is a test article description for the preview.', $result['description']);
        $this->assertSame('https://example.com/image.jpg', $result['image']);
        $this->assertNotEmpty($result['fetched_at']);
    }

    public function test_fallback_to_title_when_no_og_title(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<title>The HTML Title</title>
</head>
<body></body>
</html>
HTML;

        Http::fake([
            '*example.com*' => Http::response($html, 200),
        ]);

        $result = $this->service->fetchPreview('https://example.com');

        $this->assertNotNull($result);
        $this->assertSame('The HTML Title', $result['title']);
        $this->assertNull($result['description']);
        $this->assertNull($result['image']);
    }

    public function test_og_image_not_https_is_ignored(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta property="og:title" content="HTTP Image" />
<meta property="og:image" content="http://example.com/image.jpg" />
</head>
<body></body>
</html>
HTML;

        Http::fake([
            '*example.com*' => Http::response($html, 200),
        ]);

        $result = $this->service->fetchPreview('https://example.com');

        $this->assertNotNull($result);
        $this->assertNull($result['image']);
    }

    public function test_og_image_private_ip_is_ignored(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta property="og:title" content="Private Image" />
<meta property="og:image" content="https://192.168.1.1/image.jpg" />
</head>
<body></body>
</html>
HTML;

        Http::fake([
            '*example.com*' => Http::response($html, 200),
        ]);

        $result = $this->service->fetchPreview('https://example.com');

        $this->assertNotNull($result);
        $this->assertNull($result['image']);
    }

    public function test_redirect_to_private_ip_is_blocked(): void
    {
        Http::fake([
            'http://example.com' => Http::response('', 302, ['Location' => 'http://192.168.1.1/secret']),
            'http://192.168.1.1/*' => Http::response('Internal', 200),
        ]);

        $result = $this->service->fetchPreview('http://example.com');

        $this->assertNull($result);
    }

    public function test_timeout_returns_null(): void
    {
        Http::fake([
            '*example.com*' => function () {
                throw new ConnectionException('Timeout');
            },
        ]);

        $result = $this->service->fetchPreview('http://example.com');

        $this->assertNull($result);
    }

    public function test_http_error_returns_null(): void
    {
        Http::fake([
            '*example.com*' => Http::response('Not Found', 404),
        ]);

        $result = $this->service->fetchPreview('http://example.com/notfound');

        $this->assertNull($result);
    }

    public function test_resolve_relative_og_image(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta property="og:image" content="/images/og.jpg" />
</head>
<body></body>
</html>
HTML;

        Http::fake([
            '*example.com*' => Http::response($html, 200, ['Content-Type' => 'text/html']),
        ]);

        $result = $this->service->fetchPreview('https://example.com/article');

        $this->assertNotNull($result);
        $this->assertSame('https://example.com/images/og.jpg', $result['image']);
    }

    public function test_http_client_not_sent_for_https_images(): void
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta property="og:image" content="https://cdn.example.com/img.jpg" />
</head>
<body></body>
</html>
HTML;

        Http::fake([
            '*example.com*' => Http::response($html, 200),
        ]);

        $result = $this->service->fetchPreview('https://example.com');

        $this->assertNotNull($result);
        $this->assertSame('https://cdn.example.com/img.jpg', $result['image']);
    }
}
