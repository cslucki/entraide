<?php

namespace Tests\Unit\Blog;

use App\Services\Blog\BlogHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1906 — le contenu d'un article est rendu BRUT (`{!! $postContent !!}`).
 * Le filtre est donc la seule barriere : tout ce qui en sort doit etre inerte.
 *
 * Deux moities :
 *  - le HTML malveillant ne doit plus rien laisser d'executable ;
 *  - le HTML produit par l'editeur TipTap ressort EXACTEMENT comme avant
 *    (sorties figees le 27/11/2026 sur l'ancien filtre) : changer de moteur
 *    ne doit rien changer pour un auteur.
 */
class TASK1906BlogHtmlSanitizerTest extends TestCase
{
    private function nettoyer(string $html): string
    {
        return app(BlogHtmlSanitizer::class)->sanitizeHtml($html);
    }

    /**
     * Chaque entree porte un « temoin » : un fragment qui ne doit plus
     * apparaitre dans la sortie (insensible a la casse).
     */
    public static function htmlMalveillant(): array
    {
        return [
            'on* sans guillemets' => ['<img src=x onerror=alert(1)>', 'onerror'],
            'on* entre guillemets' => ['<img src="x" onerror="alert(1)">', 'onerror'],
            'on* apres un autre attribut sans guillemets' => ['<p title=a onclick=alert(1)>t</p>', 'onclick'],
            'iframe srcdoc' => ['<iframe srcdoc="&lt;script&gt;alert(document.domain)&lt;/script&gt;"></iframe>', 'srcdoc'],
            'iframe src entre apostrophes' => ["<iframe src='https://evil.example/'></iframe>", 'evil.example'],
            'iframe src sans guillemets' => ['<iframe src=https://evil.example/></iframe>', 'evil.example'],
            'iframe sous-domaine trompeur' => ['<iframe src="https://www.youtube.com.evil.example/embed/x"></iframe>', 'evil.example'],
            'iframe http non chiffre' => ['<iframe src="http://www.youtube.com/embed/x"></iframe>', 'http://'],
            'lien javascript' => ['<a href="javascript:alert(1)">x</a>', 'javascript'],
            'lien javascript encode' => ['<a href="jav&#x61;script:alert(1)">x</a>', 'script:'],
            'lien javascript precede d espaces' => ['<a href="  javascript:alert(1)">x</a>', 'javascript'],
            'lien data' => ['<a href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;">x</a>', 'data:'],
            'image data svg' => ['<img src="data:image/svg+xml,&lt;svg onload=alert(1)&gt;">', 'data:'],
            'div style recouvrant la page' => ['<div style="position:fixed;inset:0;background:#fff">faux</div>', 'position'],
            'balise script' => ['<p>a</p><script>alert(1)</script>', '<script'],
            'balise svg' => ['<svg><script>alert(1)</script></svg>', '<svg'],
            'attribut Alpine x-init' => ['<p x-data x-init="alert(1)">t</p>', 'x-init'],
            'attribut Alpine @click' => ['<p x-data @click="alert(1)">t</p>', '@click'],
            'classe Tailwind de recouvrement' => ['<p class="fixed inset-0 z-50">faux</p>', 'fixed'],
            'iframe sans src' => ['<iframe></iframe><p>ok</p>', '<iframe'],
            'formulaire' => ['<form action="https://evil.example"><input name="password"></form>', 'evil.example'],
        ];
    }

    #[DataProvider('htmlMalveillant')]
    public function test_le_html_malveillant_ne_laisse_rien_d_executable(string $html, string $temoin): void
    {
        $sortie = $this->nettoyer($html);

        $this->assertStringNotContainsStringIgnoringCase($temoin, $sortie, "Sortie : {$sortie}");
    }

    /**
     * Sorties figees sur l'ancien filtre (27/11/2026) : le HTML de l'editeur
     * ressort a l'identique, a une ecriture pres (`data-media-embed=""`).
     */
    public static function htmlDeLEditeur(): array
    {
        return [
            'texte' => [
                '<h1>Titre</h1><h2>Sous</h2><h3>S3</h3><h4>S4</h4><p>Un <strong>gras</strong>, <em>italique</em>, <u>souligne</u>, <b>b</b>, <i>i</i>, <code>code</code><br>ligne</p><pre><code>bloc</code></pre><ul><li><p>a</p></li></ul><ol><li><p>b</p></li></ol>',
                '<h1>Titre</h1><h2>Sous</h2><h3>S3</h3><h4>S4</h4><p>Un <strong>gras</strong>, <em>italique</em>, <u>souligne</u>, <b>b</b>, <i>i</i>, <code>code</code><br>ligne</p><pre><code>bloc</code></pre><ul><li><p>a</p></li></ul><ol><li><p>b</p></li></ol>',
            ],
            'liens' => [
                '<p><a href="https://exemple.org/page?x=1&amp;y=2" rel="noopener noreferrer" target="_blank">lien</a> et <a href="mailto:a@exemple.org">mail</a></p>',
                '<p><a href="https://exemple.org/page?x=1&amp;y=2" rel="noopener noreferrer" target="_blank">lien</a> et <a href="mailto:a@exemple.org">mail</a></p>',
            ],
            'image locale redimensionnee' => [
                '<p><img src="/storage/blog/images/o/u/abc.webp" alt="Une image" title="T" width="300" height="200" data-resized="50"></p>',
                '<p><img src="/storage/blog/images/o/u/abc.webp" alt="Une image" title="T" width="300" height="200" data-resized="50"></p>',
            ],
            'image distante' => [
                '<img src="https://fls-x.r2.cloudflarestorage.com/blog/images/o/u/abc.png" alt="">',
                '<img src="https://fls-x.r2.cloudflarestorage.com/blog/images/o/u/abc.png" alt="">',
            ],
            'tableau' => [
                '<table data-borderless="true" style="min-width: 50px"><colgroup><col style="min-width: 25px"><col style="width: 120px"></colgroup><thead><tr><th colspan="1" rowspan="1"><p>Col 1</p></th><th colspan="2" rowspan="1"><p>Col 2</p></th></tr></thead><tbody><tr><td colspan="1" rowspan="1"><p>A</p></td><td colspan="1" rowspan="1"><p>B</p></td></tr></tbody><tfoot><tr><td>f</td></tr></tfoot><caption>c</caption></table>',
                '<table data-borderless="true"><colgroup><col style="min-width: 25px"><col style="width: 120px"></colgroup><thead><tr><th colspan="1" rowspan="1"><p>Col 1</p></th><th colspan="2" rowspan="1"><p>Col 2</p></th></tr></thead><tbody><tr><td colspan="1" rowspan="1"><p>A</p></td><td colspan="1" rowspan="1"><p>B</p></td></tr></tbody><tfoot><tr><td>f</td></tr></tfoot><caption>c</caption></table>',
            ],
            'video youtube' => [
                '<div data-media-embed style="aspect-ratio: 16 / 9"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="100%" height="100%" frameborder="0" allowfullscreen="true" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" style="width: 100%; height: 100%" title="Embedded media"></iframe></div>',
                // Seul ecart de forme : le serialiseur HTML5 ecrit l'attribut vide `=""`
                // (meme sens pour le navigateur et pour TipTap `div[data-media-embed]`).
                '<div data-media-embed="" style="aspect-ratio: 16 / 9"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="100%" height="100%" frameborder="0" allowfullscreen="true" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" title="Embedded media"></iframe></div>',
            ],
            'annotation' => [
                '<p>Avant <span data-annotation-id="0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b" class="bp-annotation-mark">passage</span> apres.</p>',
                '<p>Avant <span data-annotation-id="0193a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b" class="bp-annotation-mark">passage</span> apres.</p>',
            ],
            'alignement couleur surlignage retires comme avant' => [
                '<p style="text-align: center">centre</p><p><span style="color: #ff0000">rouge</span> <mark data-color="#ff0">surligne</mark></p>',
                '<p>centre</p><p>rouge surligne</p>',
            ],
        ];
    }

    #[DataProvider('htmlDeLEditeur')]
    public function test_le_html_de_l_editeur_ressort_comme_avant(string $html, string $attendu): void
    {
        $this->assertSame($attendu, $this->nettoyer($html));
    }
}
