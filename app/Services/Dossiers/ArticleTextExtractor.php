<?php

namespace App\Services\Dossiers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class ArticleTextExtractor
{
    /**
     * Extract deterministic plain text from article HTML without executing it.
     */
    public function extract(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = $this->parseHtml($html);
        $this->removeUnsafeNodes($document);

        $body = $document->getElementsByTagName('body')->item(0);
        $text = $body ? $this->collectText($body) : $document->textContent;

        return $this->normalizeText($text);
    }

    private function parseHtml(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$html.'</body></html>',
                LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
            );
            libxml_clear_errors();

            return $document;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    private function removeUnsafeNodes(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//script|//style|//noscript|//template');

        if ($nodes === false) {
            return;
        }

        foreach (iterator_to_array($nodes) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function collectText(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return $node->textContent;
        }

        if (! $node instanceof DOMElement) {
            return $this->collectChildrenText($node);
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'br') {
            return "\n";
        }

        $text = '';

        if ($this->isBlockElement($tag)) {
            $text .= "\n";
        }

        $text .= $this->collectChildrenText($node);

        if ($this->isBlockElement($tag)) {
            $text .= "\n";
        }

        return $text;
    }

    private function collectChildrenText(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= $this->collectText($child);
        }

        return $text;
    }

    private function isBlockElement(string $tag): bool
    {
        return in_array($tag, [
            'address', 'article', 'aside', 'blockquote', 'dd', 'div', 'dl', 'dt',
            'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3',
            'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre',
            'section', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
        ], true);
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;

        $lines = array_map('trim', explode("\n", $text));
        $text = implode("\n", $lines);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
