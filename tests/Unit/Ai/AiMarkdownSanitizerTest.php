<?php

namespace Tests\Unit\Ai;

use App\Support\Ai\AiMarkdownSanitizer;
use Tests\TestCase;

class AiMarkdownSanitizerTest extends TestCase
{
    public function test_it_preserves_supported_markdown(): void
    {
        $input = "## Titre\n\n### Sous-titre\n\n"
            .'**gras** et *italique* et `code`'."\n\n"
            ."- Premier\n- Deuxième\n\n"
            ."1. un\n2. deux\n\n"
            ."> Citation\n\n"
            ."[lien](https://example.com)\n\n"
            ."![image](https://example.com/img.png)\n\n"
            ."```php\n\$ok = true;\n```";

        $this->assertSame($input, AiMarkdownSanitizer::sanitize($input));
    }

    public function test_it_normalizes_h1_and_over_deep_headings(): void
    {
        $output = AiMarkdownSanitizer::sanitize(
            "# Titre\n\n## Sous\n\n### Sous-sous\n\n#### Trop\n\n##### Encore\n\n###### Plus"
        );

        $this->assertSame(
            "## Titre\n\n## Sous\n\n### Sous-sous\n\n### Trop\n\n### Encore\n\n### Plus",
            $output
        );
    }

    public function test_it_does_not_touch_headings_inside_fenced_code(): void
    {
        $input = "```md\n# Titre en code\n## Autre\n```";

        $this->assertSame($input, AiMarkdownSanitizer::sanitize($input));
    }

    public function test_it_strips_html_scripts_php_and_blade(): void
    {
        $input = '<script>alert(1)</script>'
            .'<p>paragraphe</p>'
            .'<?php echo "x"; ?>'
            .'{{ $blade }}'
            .'{!! $raw !!}';

        $output = AiMarkdownSanitizer::sanitize($input);

        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('alert(1)', $output);
        $this->assertStringNotContainsString('<p>', $output);
        $this->assertStringNotContainsString('<?php', $output);
        $this->assertStringNotContainsString('{{', $output);
        $this->assertStringNotContainsString('{!!', $output);
        $this->assertStringContainsString('paragraphe', $output);
    }

    public function test_it_neutralizes_unsafe_urls(): void
    {
        $output = AiMarkdownSanitizer::sanitize(
            '[sûr](https://example.com) '
            .'[fiable](http://example.com/page) '
            .'[piège](javascript:alert(1)) '
            .'[mailto](mailto:a@b.c) '
            .'![alt](data:image/png;base64,AAA)'
        );

        $this->assertStringContainsString('[sûr](https://example.com)', $output);
        $this->assertStringContainsString('[fiable](http://example.com/page)', $output);
        $this->assertStringContainsString('piège', $output);
        $this->assertStringNotContainsString('javascript:', $output);
        $this->assertStringContainsString('mailto', $output);
        $this->assertStringNotContainsString('data:image', $output);
        $this->assertStringContainsString('alt', $output);
        $this->assertStringNotContainsString('![', $output);
    }

    public function test_plain_text_is_unchanged(): void
    {
        $input = 'Réponse simple avec des accents éàü et des chiffres 123.';

        $this->assertSame($input, AiMarkdownSanitizer::sanitize($input));
    }

    public function test_legacy_plain_text_without_markdown_syntax_is_unchanged(): void
    {
        $input = "Ancien message en texte brut.\n\nSecond paragraphe sans marqueur markdown.";

        $this->assertSame($input, AiMarkdownSanitizer::sanitize($input));
    }

    public function test_it_applies_the_limit_and_closes_an_open_fence(): void
    {
        $long = str_repeat('a', 100)."\n\n```php\n".str_repeat('b', 100)."\n```";

        $output = AiMarkdownSanitizer::sanitize($long, 50);

        $this->assertLessThanOrEqual(50, mb_strlen($output));
    }

    public function test_it_closes_an_open_fence_at_the_cutoff(): void
    {
        $long = "```php\n".str_repeat('b', 100);

        $output = AiMarkdownSanitizer::sanitize($long, 30);

        $this->assertSame("```php\n".str_repeat('b', 23)."\n```", $output);
    }
}
