<?php

namespace App\Services\Blog;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Le filtre HTML du blog, partage par l'editeur d'articles, la restauration
 * d'une version (`BlogSnapshotController`), l'import RSS et le Flux
 * (`FeedPost::nettoyerContenu`). Le contenu filtre est rendu BRUT
 * (`{!! $postContent !!}`) : ce filtre est la seule barriere.
 *
 * ## TASK-1906 — une liste blanche sur un vrai arbre HTML
 *
 * Jusqu'ici : `strip_tags()` puis des expressions regulieres. Elles lisaient
 * le HTML autrement qu'un navigateur, et laissaient passer
 * `<img src=x onerror=…>` (sans guillemets), `<iframe srcdoc=…>`,
 * `<iframe src='…'>` vers n'importe quel site, `jav&#x61;script:`, et tout
 * attribut Alpine (`x-init`, `@click`) — execute sur une page qui charge Alpine.
 *
 * Desormais le HTML est lu par l'analyseur HTML5 de PHP (`Dom\HTMLDocument`,
 * le meme algorithme que les navigateurs), puis chaque element et chaque
 * attribut doit figurer sur une liste blanche. Les valeurs d'URL sont
 * controlees APRES decodage des entites, comme le navigateur les lira.
 *
 * Pourquoi pas `symfony/html-sanitizer` : il reecrit le texte en entites
 * (`'` -> `&#039;`, `@` -> `&#64;`), ce qui aurait modifie presque chaque
 * article francais et les `@mentions` du Flux. Le serialiseur HTML5 natif
 * n'echappe que `&`, `<`, `>` et l'espace insecable : le texte ressort tel quel.
 *
 * Le comportement visible est conserve : memes balises, memes iframes video,
 * memes annotations, et `style` toujours retire hors tableaux et videos
 * (voir `TASK1906BlogHtmlSanitizerTest`).
 */
class BlogHtmlSanitizer
{
    public const ALLOWED_HTML_TAGS = [
        // h1 added by TASK-1084. Its absence was silent and hard to see: the
        // editor produced a proper <h1>, and strip_tags() removed the tag while
        // keeping its text — so a level-1 title came back as an ordinary
        // paragraph after saving, with nothing to indicate why. Documents
        // pasted from an LLM almost always open with one.
        'h1', 'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li',
        'img', 'b', 'i', 'strong', 'em', 'u', 'br', 'a', 'code', 'pre',
        'table', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot',
        'caption', 'col', 'colgroup',
        'div', 'iframe',
    ];

    public const ALLOWED_IFRAME_DOMAINS = [
        'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'vimeo.com', 'player.vimeo.com',
        'dailymotion.com', 'www.dailymotion.com',
    ];

    /**
     * Attributs permis, par balise. Tout le reste disparait — `on*`, `style`
     * (sauf ci-dessous), `srcdoc`, `x-*`, `@*`, `:*`, `id`, `class`…
     * Releve du contenu reel (jumeau PROD 1.905, 27/11) : liens
     * `href/target/rel/class`, tableaux `colspan/rowspan` et `col style`,
     * video `div data-media-embed style` + iframe, images `src/alt/width/height`.
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel', 'title', 'class'],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'data-resized'],
        'iframe' => ['src', 'width', 'height', 'frameborder', 'allowfullscreen', 'allow', 'title'],
        'div' => ['data-media-embed', 'style'],
        'table' => ['data-borderless'],
        'td' => ['colspan', 'rowspan', 'colwidth'],
        'th' => ['colspan', 'rowspan', 'colwidth'],
        'col' => ['style', 'span'],
        'colgroup' => ['style', 'span'],
        'ol' => ['start'],
        'code' => ['class'],
    ];

    /** Elements supprimes AVEC leur contenu (le reste des inconnus est deballe). */
    private const DROPPED_WITH_CONTENT = [
        'script', 'style', 'template', 'noscript', 'textarea', 'select', 'option',
        'object', 'embed', 'applet', 'svg', 'math', 'title', 'head', 'frame',
        'frameset', 'audio', 'video', 'canvas', 'iframe', 'base', 'link', 'meta',
    ];

    /** Proprietes CSS permises, par balise (valeur controlee par motif). */
    private const ALLOWED_STYLES = [
        'col' => ['width' => self::CSS_LENGTH, 'min-width' => self::CSS_LENGTH],
        'colgroup' => ['width' => self::CSS_LENGTH, 'min-width' => self::CSS_LENGTH],
        'div' => ['aspect-ratio' => '/^\d+(\.\d+)?\s*\/\s*\d+(\.\d+)?$/'],
    ];

    private const CSS_LENGTH = '/^\d+(\.\d+)?(px|%|em|rem)$/';

    private const ANNOTATION_ID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** Balises permises pour l'appel en cours (liste reduite possible). */
    private array $allowedTags = self::ALLOWED_HTML_TAGS;

    /**
     * @param  list<string>|null  $allowedTags  liste REDUITE pour une surface plus etroite
     *                                          (document racine d'une Boucle) ; attributs et URL
     *                                          restent ceux de ce filtre.
     */
    public function sanitizeHtml(string $html, ?array $allowedTags = null): string
    {
        $this->allowedTags = $allowedTags ?? self::ALLOWED_HTML_TAGS;

        // Conserves de l'ancien filtre : ni PHP ni moustaches Blade/Alpine.
        $html = preg_replace('/<\?php|<\%|<\%\=|<\?xml/i', '', $html) ?? '';
        $html = preg_replace('/\{\{.*?\}\}/s', '', $html) ?? '';

        if (trim($html) === '') {
            return $html;
        }

        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>'.$html.'</body></html>',
            LIBXML_NOERROR,
            'UTF-8',
        );
        $body = $document->body;
        if ($body === null) {
            return '';
        }

        $this->cleanChildren($body);

        return $body->innerHTML;
    }

    private function cleanChildren(Node $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof Element) {
                $this->cleanElement($node);
            } elseif (! $node instanceof Text) {
                // Commentaires, CDATA, instructions : retires, comme strip_tags().
                $node->remove();
            }
        }
    }

    private function cleanElement(Element $element): void
    {
        $tag = strtolower($element->localName);

        if ($tag === 'iframe') {
            if (! in_array('iframe', $this->allowedTags, true)) {
                $element->remove();

                return;
            }
            $src = $element->getAttribute('src');
            if ($src === null || ! $this->isAllowedIframeSrc($src)) {
                $element->remove();

                return;
            }
        } elseif (in_array($tag, self::DROPPED_WITH_CONTENT, true)) {
            $element->remove();

            return;
        }

        if ($tag === 'span' && $this->keepAnnotationSpan($element)) {
            $this->cleanChildren($element);

            return;
        }

        if (! in_array($tag, $this->allowedTags, true)) {
            // Balise inconnue (span, mark, blockquote…) : on garde son texte,
            // exactement comme strip_tags() le faisait.
            $this->cleanChildren($element);
            $element->replaceWith(...iterator_to_array($element->childNodes));

            return;
        }

        $this->cleanAttributes($element, $tag);
        $this->cleanChildren($element);
    }

    private function cleanAttributes(Element $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            $keep = in_array($name, $allowed, true) && $this->isAllowedValue($tag, $name, $value, $element);

            if (! $keep) {
                $element->removeAttribute($attribute->name);
            }
        }
    }

    private function isAllowedValue(string $tag, string $name, string $value, Element $element): bool
    {
        return match ($name) {
            'href' => $this->isSafeUrl($value, ['http', 'https', 'mailto', 'tel']),
            'src' => $tag === 'iframe' ? $this->isAllowedIframeSrc($value) : $this->isSafeUrl($value, ['http', 'https']),
            'target' => $value === '_blank',
            'rel' => (bool) preg_match('/^[a-z ]*$/i', $value),
            'class' => $this->isAllowedClass($tag, $value),
            'style' => $this->keepAllowedStyle($tag, $value, $element),
            'width', 'height' => (bool) preg_match('/^\d+(\.\d+)?(px|%)?$/', $value),
            'colspan', 'rowspan', 'span', 'start', 'frameborder', 'data-resized' => (bool) preg_match('/^\d+$/', $value),
            'colwidth' => (bool) preg_match('/^\d+(,\d+)*$/', $value),
            'allow' => (bool) preg_match('/^[a-z\-; ]*$/i', $value),
            'allowfullscreen', 'data-borderless', 'data-media-embed', 'alt', 'title' => true,
            default => false,
        };
    }

    /**
     * Le navigateur ignore les caracteres de controle et les blancs d'une URL
     * (`java\tscript:`) : on les retire AVANT de lire le schema. La valeur
     * recue est deja decodee (`&#x61;` -> `a`) par l'analyseur.
     */
    private function isSafeUrl(string $url, array $schemes): bool
    {
        $normalized = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalized, $match)) {
            return in_array(strtolower($match[1]), $schemes, true);
        }

        // Relative (`/storage/…`, `#ancre`, `?q=`) : meme site.
        return true;
    }

    private function isAllowedIframeSrc(string $url): bool
    {
        $normalized = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';

        if (strtolower((string) parse_url($normalized, PHP_URL_SCHEME)) !== 'https') {
            return false;
        }

        $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));

        return $host !== '' && in_array($host, self::ALLOWED_IFRAME_DOMAINS, true);
    }

    private function isAllowedClass(string $tag, string $value): bool
    {
        $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            $ok = match ($tag) {
                'a' => $token === 'article-editor-link',
                'code' => (bool) preg_match('/^language-[a-z0-9+#\-]+$/i', $token),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Garde la valeur d'origine si TOUTES ses declarations sont permises ; sinon
     * la reecrit avec les seules declarations permises (ou la retire).
     */
    private function keepAllowedStyle(string $tag, string $value, Element $element): bool
    {
        $rules = self::ALLOWED_STYLES[$tag] ?? [];
        if ($rules === []) {
            return false;
        }

        $kept = [];
        $all = true;
        foreach (array_filter(array_map('trim', explode(';', $value))) as $declaration) {
            [$property, $propertyValue] = array_pad(array_map('trim', explode(':', $declaration, 2)), 2, '');
            $property = strtolower($property);
            if (isset($rules[$property]) && preg_match($rules[$property], $propertyValue)) {
                $kept[] = $property.': '.$propertyValue;
            } else {
                $all = false;
            }
        }

        if ($kept === []) {
            return false;
        }
        if (! $all) {
            $element->setAttribute('style', implode('; ', $kept));
        }

        return true;
    }

    /**
     * Un span ne survit que s'il porte une annotation valide (TASK-980) ; il
     * est alors reecrit avec ses deux seuls attributs, dans cet ordre.
     */
    private function keepAnnotationSpan(Element $span): bool
    {
        $id = (string) $span->getAttribute('data-annotation-id');
        $classes = preg_split('/\s+/', trim((string) $span->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (! preg_match(self::ANNOTATION_ID, $id) || ! in_array('bp-annotation-mark', $classes, true)) {
            return false;
        }

        foreach (iterator_to_array($span->attributes) as $attribute) {
            $span->removeAttribute($attribute->name);
        }
        $span->setAttribute('data-annotation-id', $id);
        $span->setAttribute('class', 'bp-annotation-mark');

        return true;
    }
}
