<?php

namespace App\Support\ScenarioManifest;

/**
 * Phase 7 (spec 9.1 et 9.3) : le contenu est-il sanitizable sans PERTE ?
 *
 * La spec est explicite : "La validation ne corrige pas silencieusement un
 * contenu." Un document dont le HTML serait nettoye a l'affichage n'est pas un
 * document valide, parce que l'humain approuverait alors un digest dont le
 * rendu ne correspond pas au texte approuve.
 *
 * Le controle est donc une DETECTION, pas une reecriture : on refuse tout ce
 * que le sanitizer retirerait, au lieu de comparer une entree a une sortie
 * re-serialisee. Comparer deux serialisations rendrait le verdict dependant du
 * formatage de libxml (ordre des attributs, entites, `<br>` vs `<br/>`) et
 * ferait echouer du contenu parfaitement sur — exactement le faux positif
 * qu'un Validator deterministe ne doit pas produire.
 *
 * Aucune URL n'est visitee, aucune entite externe n'est chargee : la
 * validation n'emet aucun appel reseau (spec 9.1).
 */
final class ManifestContentPolicy
{
    /** Allowlist HTML V1, spec 9.3. */
    public const ALLOWED_ELEMENTS = [
        'p', 'br', 'strong', 'em', 'ul', 'ol', 'li',
        'blockquote', 'code', 'pre', 'h1', 'h2', 'h3', 'h4', 'a',
    ];

    /** Seuls attributs permis, et uniquement sur `a`. */
    public const ALLOWED_ANCHOR_ATTRIBUTES = ['href', 'title', 'rel'];

    /** Schemes admis pour un `href`. */
    public const ALLOWED_URL_SCHEMES = ['https://', 'mailto:'];

    /**
     * `plain` ne passe par aucun sanitizer : un message plain est affiche tel
     * quel, jamais interprete. Son seul controle est l'absence de caractere de
     * controle, deja fait par le validateur de forme.
     */
    public static function check(string $content, string $format, string $path, ManifestErrorBag $errors): void
    {
        if ($format === 'plain') {
            return;
        }

        $scannable = $content;

        if ($format === 'markdown') {
            self::checkMarkdown($content, $path, $errors);

            // Un autolink Markdown legitime — `<https://example.test>` — est
            // relu comme une balise par un parseur HTML, qui la refuserait
            // comme element `<https:>`. Les autolinks DEJA valides ci-dessus
            // sont donc retires avant le pass HTML ; ceux qui ne le sont pas
            // restent dans le texte et sont signales deux fois pour une seule
            // erreur, la deduplication (code, path) s'en chargeant.
            $scannable = (string) preg_replace(
                '/<(?:https|mailto):[^>\s]*>/i',
                '',
                $content,
            );
        }

        // Le HTML brut contenu dans du Markdown suit EXACTEMENT les memes
        // regles que le HTML (spec 9.3) : le meme controle DOM s'applique aux
        // deux formats.
        self::checkHtml($scannable, $path, $errors);
    }

    private static function checkMarkdown(string $content, string $path, ManifestErrorBag $errors): void
    {
        if (self::containsMarkdownImage($content)) {
            self::reject($path, $errors, 'Markdown images are not allowed.');
        }

        // Autolinks `<...>` : seuls https:// et mailto: sont admis.
        if (preg_match_all('/<([A-Za-z][A-Za-z0-9+.-]*:[^>\s]*)>/', $content, $matches) > 0) {
            foreach ($matches[1] as $url) {
                if (! self::isAllowedUrl($url)) {
                    self::reject($path, $errors, 'Only https:// and mailto: links are allowed.');
                }
            }
        }

        // Destinations de liens inline `[texte](url)` et de definitions
        // `[ref]: url`.
        $destinations = [];

        if (preg_match_all('/\[[^\]]*\]\(\s*([^)\s]+)/', $content, $inline) > 0) {
            $destinations = array_merge($destinations, $inline[1]);
        }

        if (preg_match_all('/^[ \t]*\[[^\]]+\]:[ \t]*(\S+)/m', $content, $definitions) > 0) {
            $destinations = array_merge($destinations, $definitions[1]);
        }

        foreach ($destinations as $destination) {
            if (! self::isAllowedUrl(trim($destination, '<>'))) {
                self::reject($path, $errors, 'Only https:// and mailto: links are allowed.');
            }
        }
    }

    /**
     * "Les images Markdown sont rejetees" (spec 9.3) — TOUTES, pas seulement
     * la forme inline.
     *
     * CommonMark connait quatre facons d'ecrire une image, et elles partagent
     * exactement une chose : le prefixe `![`.
     *
     *     ![alt](https://x.test/a.png)   inline
     *     ![alt][ref]                    reference complete
     *     ![alt][]                       reference repliee
     *     ![alt]                         reference raccourcie
     *
     * Les trois dernieres tirent leur URL d'une definition `[ref]: <url>` qui
     * peut vivre n'importe ou dans le document, y compris tres loin du `![`.
     * Ne chercher que `![...](` — ce que faisait la version precedente —
     * laissait donc passer trois images sur quatre : la definition ressemble a
     * un lien parfaitement legitime, et c'est le `!` qui, seul, transforme la
     * reference en requete sortante depuis le navigateur d'un membre.
     *
     * La regle retenue est donc PLATE : tout `![` non echappe est refuse. Elle
     * sur-refuse un `![texte]` litteral sans definition correspondante, qui ne
     * serait pas une image ; c'est assume. Une regle que le producteur peut
     * enoncer depuis la seule spec — "pas de `![` dans un manifeste" — vaut
     * mieux qu'une regle exacte dont la correction depend de la resolution des
     * definitions de reference, c'est-a-dire de l'endroit precis ou le defaut
     * corrige ici etait ne.
     *
     * Un `!` echappe (`\\![texte](url)`) n'ouvre pas une image mais un lien :
     * les sequences d'echappement sont retirees avant la recherche, pour ne
     * pas refuser ce lien-la.
     */
    private static function containsMarkdownImage(string $content): bool
    {
        $unescaped = (string) preg_replace('/\\\\./su', '', $content);

        return str_contains($unescaped, '![');
    }

    private static function checkHtml(string $content, string $path, ManifestErrorBag $errors): void
    {
        if (str_contains($content, '<') === false) {
            return;
        }

        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // LIBXML_NONET : aucune entite externe ne peut etre resolue par
        // reseau. Le wrapper `<div>` donne une racine unique a un fragment.
        $document->loadHTML(
            '<meta charset="utf-8"><div>'.$content.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($document->childNodes as $node) {
            self::inspectNode($node, $path, $errors);
        }
    }

    private static function inspectNode(\DOMNode $node, string $path, ManifestErrorBag $errors): void
    {
        if ($node instanceof \DOMComment) {
            self::reject($path, $errors, 'HTML comments are not allowed.');

            return;
        }

        if ($node instanceof \DOMProcessingInstruction || $node instanceof \DOMDocumentType) {
            self::reject($path, $errors, 'Processing instructions and doctypes are not allowed.');

            return;
        }

        if (! $node instanceof \DOMElement) {
            return;
        }

        $name = strtolower($node->nodeName);

        // Le wrapper et le `<meta charset>` ajoutes pour parser le fragment ne
        // viennent pas du document : ils ne sont pas juges, seuls leurs enfants
        // le sont.
        if ($name !== 'div' && $name !== 'meta') {
            if (! in_array($name, self::ALLOWED_ELEMENTS, true)) {
                self::reject($path, $errors, sprintf("HTML element '<%s>' is not allowed.", $name));

                return;
            }

            self::inspectAttributes($node, $name, $path, $errors);
        }

        foreach ($node->childNodes as $child) {
            self::inspectNode($child, $path, $errors);
        }
    }

    private static function inspectAttributes(\DOMElement $element, string $name, string $path, ManifestErrorBag $errors): void
    {
        foreach ($element->attributes ?? [] as $attribute) {
            $attributeName = strtolower($attribute->nodeName);

            if ($name !== 'a' || ! in_array($attributeName, self::ALLOWED_ANCHOR_ATTRIBUTES, true)) {
                self::reject($path, $errors, sprintf(
                    "HTML attribute '%s' is not allowed on '<%s>'.",
                    $attributeName,
                    $name,
                ));

                continue;
            }

            if ($attributeName === 'href' && ! self::isAllowedUrl($attribute->nodeValue ?? '')) {
                self::reject($path, $errors, 'Only https:// and mailto: links are allowed.');
            }
        }
    }

    private static function isAllowedUrl(string $url): bool
    {
        $candidate = ltrim($url);

        foreach (self::ALLOWED_URL_SCHEMES as $scheme) {
            if (stripos($candidate, $scheme) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function reject(string $path, ManifestErrorBag $errors, string $reason): void
    {
        $errors->add(
            ManifestErrorCode::UNSAFE_CONTENT,
            $path,
            sprintf('Content would be altered by the sanitizer: %s', $reason),
        );
    }
}
