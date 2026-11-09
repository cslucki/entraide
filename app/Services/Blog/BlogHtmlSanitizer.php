<?php

namespace App\Services\Blog;

/**
 * TASK-1785 — la sanitation HTML du blog, extraite pour etre REUTILISABLE.
 *
 * ## Pourquoi une extraction, et pas une seconde sanitation
 *
 * Ce code vivait en `private` dans `BlogController`. L'importateur RSS doit
 * assainir du HTML venu d'un site tiers, et le CDC demande de « reutiliser la
 * sanitation HTML existante » : depuis un service, une methode privee de
 * controleur est inatteignable.
 *
 * Le choix etait donc entre extraire et DUPLIQUER. Dupliquer aurait donne deux
 * sanitations qui divergent — et le jour ou l'une se durcit sans l'autre, c'est
 * la plus faible qui decide de ce qui entre en base. Un XSS vivrait alors dans
 * la copie oubliee.
 *
 * ## Deplacement FIDELE, et comment on le sait
 *
 * Rien n'a ete reecrit : les quatre methodes et les deux listes sont copiees a
 * l'identique, et `BlogController` DELEGUE desormais au lieu de porter le code.
 * La preuve n'est pas une relecture a l'oeil — c'est la suite blog existante,
 * qui couvre deja le comportement de cette sanitation et doit rester verte.
 *
 * Les commentaires d'origine sont conserves tels quels : ils portent des
 * decisions (TASK-1084 sur `h1`, la liste d'iframes approuvees) qui valent
 * autant ici qu'avant.
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

    public function sanitizeHtml(string $html): string
    {
        // Step 1 — extract valid annotation spans as safe placeholders
        $annotationSpans = [];
        $html = $this->extractAnnotationSpans($html, $annotationSpans);

        // Step 2 — standard sanitization (span is NOT in allowed tags)
        $allowed = self::ALLOWED_HTML_TAGS;
        $html = strip_tags($html, '<'.implode('><', $allowed).'>');

        $html = preg_replace('/<(\w+)\s[^>]*on\w+\s*=\s*["\'][^"\']*["\']/i', '<$1', $html);
        $html = preg_replace('/<(\w+)\s[^>]*javascript\s*:\s*[^"\'>\s]+/i', '<$1', $html);
        $html = preg_replace('/<(\w+)\s[^>]*data\s*:\s*[^"\'>\s]+/i', '<$1', $html);
        $html = preg_replace('/<\?php|<\%|<\%\=|<\?xml/i', '', $html);
        $html = preg_replace('/\{\{.*?\}\}/s', '', $html);
        $html = $this->stripStyleAttribute($html);

        // Remove iframes pointing to non-approved domains
        if (str_contains($html, 'iframe')) {
            $html = $this->filterIframeDomains($html);
        }

        // Step 3 — restore annotation spans from placeholders
        foreach ($annotationSpans as $key => $safeSpan) {
            $html = str_replace($key, $safeSpan, $html);
        }

        return $html;
    }

    private function stripStyleAttribute(string $html): string
    {
        return preg_replace_callback(
            '/<(\w+)\b([^>]*)>/i',
            function (array $match): string {
                $tag = strtolower($match[1]);
                $attrs = $match[2];
                if (in_array($tag, ['col', 'colgroup', 'div'], true)) {
                    return $match[0];
                }
                $attrs = preg_replace('/\s+style\s*=\s*"[^"]*"/i', '', $attrs);
                $attrs = preg_replace("/\s+style\s*=\s*'[^']*'/i", '', $attrs);

                return '<'.$match[1].$attrs.'>';
            },
            $html
        );
    }

    private function filterIframeDomains(string $html): string
    {
        return preg_replace_callback(
            '/(<iframe\b[^>]*>)(.*?)(<\/iframe>)/is',
            function (array $match): string {
                $openTag = $match[1];
                $inner = $match[2];
                $closeTag = $match[3];

                if (preg_match('/\bsrc\s*=\s*"([^"]*)"/i', $openTag, $srcMatch)) {
                    if (! $this->isAllowedIframeDomain($srcMatch[1])) {
                        return '';
                    }
                }

                return $openTag.$inner.$closeTag;
            },
            $html
        );
    }

    private function isAllowedIframeDomain(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }
        $host = strtolower($host);

        return in_array($host, self::ALLOWED_IFRAME_DOMAINS, true);
    }

    private function extractAnnotationSpans(string $html, array &$protected): string
    {
        $previous = null;

        while ($previous !== $html) {
            $previous = $html;
            $html = preg_replace_callback(
                '/<span\b([^>]*)>((?:(?!<\/?span\b).)*)<\/span>/is',
                function (array $match) use (&$protected): string {
                    $attrs = $match[1];
                    $inner = $match[2];

                    if (preg_match('/data-annotation-id\s*=\s*"([^"]+)"/i', $attrs, $idMatch)) {
                        $id = $idMatch[1];
                        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
                            $classMatch = [];
                            $hasClass = preg_match('/class\s*=\s*"([^"]*)"/i', $attrs, $classMatch);
                            $classes = $hasClass ? preg_split('/\s+/', trim($classMatch[1])) : [];
                            if (in_array('bp-annotation-mark', $classes, true)) {
                                $openKey = '@@@BPAO_'.count($protected).'@@@';
                                $closeKey = '@@@BPAC_'.count($protected).'@@@';
                                $protected[$openKey] = '<span data-annotation-id="'.$id.'" class="bp-annotation-mark">';
                                $protected[$closeKey] = '</span>';

                                return $openKey.$inner.$closeKey;
                            }
                        }
                    }

                    return $inner;
                },
                $html
            );
        }

        return $html;
    }
}
