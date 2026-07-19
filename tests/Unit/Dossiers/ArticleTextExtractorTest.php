<?php

namespace Tests\Unit\Dossiers;

use App\Services\Dossiers\ArticleTextExtractor;
use Tests\TestCase;

class ArticleTextExtractorTest extends TestCase
{
    private ArticleTextExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extractor = new ArticleTextExtractor;
    }

    public function test_empty_html_returns_empty_text(): void
    {
        $this->assertSame('', $this->extractor->extract(''));
        $this->assertSame('', $this->extractor->extract('   '));
        $this->assertSame('', $this->extractor->extract('<div><span></span></div>'));
    }

    public function test_plain_text_without_tags_is_preserved(): void
    {
        $this->assertSame('Plain article text.', $this->extractor->extract('Plain article text.'));
    }

    public function test_simple_paragraph_is_extracted(): void
    {
        $this->assertSame('A simple paragraph.', $this->extractor->extract('<p>A simple paragraph.</p>'));
    }

    public function test_headings_and_paragraphs_keep_logical_separation(): void
    {
        $text = $this->extractor->extract('<h1>Title</h1><p>First paragraph.</p><h2>Subtitle</h2><p>Second paragraph.</p>');

        $this->assertStringContainsString('Title', $text);
        $this->assertStringContainsString('First paragraph.', $text);
        $this->assertStringContainsString('Subtitle', $text);
        $this->assertStringContainsString('Second paragraph.', $text);
        $this->assertGreaterThanOrEqual(3, substr_count($text, "\n"));
    }

    public function test_ordered_and_unordered_lists_keep_items(): void
    {
        $text = $this->extractor->extract('<ul><li>Alpha</li><li>Beta</li></ul><ol><li>First</li><li>Second</li></ol>');

        $this->assertStringContainsString('Alpha', $text);
        $this->assertStringContainsString('Beta', $text);
        $this->assertStringContainsString('First', $text);
        $this->assertStringContainsString('Second', $text);
        $this->assertGreaterThanOrEqual(3, substr_count($text, "\n"));
    }

    public function test_blockquote_is_extracted_with_separation(): void
    {
        $text = $this->extractor->extract('<p>Intro</p><blockquote>Important quote.</blockquote><p>Outro</p>');

        $this->assertStringContainsString('Intro', $text);
        $this->assertStringContainsString('Important quote.', $text);
        $this->assertStringContainsString('Outro', $text);
        $this->assertStringContainsString("\nImportant quote.\n", $text);
    }

    public function test_html_entities_are_decoded(): void
    {
        $text = $this->extractor->extract('<p>Tom &amp; Jerry&nbsp;&quot;test&quot;</p>');

        $this->assertSame('Tom & Jerry "test"', $text);
    }

    public function test_accents_and_unicode_are_preserved(): void
    {
        $text = $this->extractor->extract('<p>Équipe café naïve — Привет 世界</p>');

        $this->assertStringContainsString('Équipe café naïve', $text);
        $this->assertStringContainsString('Привет 世界', $text);
    }

    public function test_spaces_and_excessive_line_breaks_are_normalized(): void
    {
        $text = $this->extractor->extract("<p>  Alpha\t\t Beta  </p>\n\n\n<p>Gamma</p>");

        $this->assertStringContainsString('Alpha Beta', $text);
        $this->assertStringContainsString('Gamma', $text);
        $this->assertStringNotContainsString("\n\n\n", $text);
    }

    public function test_script_style_noscript_and_template_contents_are_removed(): void
    {
        $html = <<<'HTML'
<article>
    <p>Visible text.</p>
    <script>window.evil = 'javascript content';</script>
    <style>.secret { color: red; }</style>
    <noscript>Noscript fallback</noscript>
    <template>Template content</template>
</article>
HTML;

        $text = $this->extractor->extract($html);

        $this->assertStringContainsString('Visible text.', $text);
        $this->assertStringNotContainsString('javascript content', $text);
        $this->assertStringNotContainsString('color: red', $text);
        $this->assertStringNotContainsString('Noscript fallback', $text);
        $this->assertStringNotContainsString('Template content', $text);
    }

    public function test_malformed_html_nested_tags_and_determinism(): void
    {
        $html = '<h1>Title<p>Nested <strong>bold</strong><em>accentué</em><ul><li>Item one<li>Item two</ul>';

        $first = $this->extractor->extract($html);
        $second = $this->extractor->extract($html);

        $this->assertSame($first, $second);
        $this->assertStringContainsString('Title', $first);
        $this->assertStringContainsString('Nested boldaccentué', $first);
        $this->assertStringContainsString('Item one', $first);
        $this->assertStringContainsString('Item two', $first);
        $this->assertStringNotContainsString('<', $first);
        $this->assertStringNotContainsString('>', $first);
    }
}
