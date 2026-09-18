<?php

namespace Tests\Feature;

use App\Services\Dossiers\Extractors\DocxCommentNeutralizer;
use App\Services\Dossiers\Extractors\WordTextExtractor;
use App\Services\Dossiers\FileContentExtractor;
use Tests\TestCase;
use ZipArchive;

/**
 * TASK-1510 — un DOCX porteur de commentaires Word ne s'indexait jamais.
 *
 * PHPWord 1.4.0 leve un `TypeError` dessus
 * (`Reader\Word2007\AbstractPart::setCommentReference()` recoit `null` pour un
 * argument type `AbstractElement`), l'extraction rend `null`, et
 * `DossierFileIndexer` SUPPRIME les chunks au lieu d'en creer. Un document relu
 * par un humain — donc commente — est justement celui qui a le plus de valeur
 * pour le RAG.
 *
 * ## La fixture est CONSTRUITE ici, pas versionnee
 *
 * Aucun document reel n'entre dans le depot. `docx()` assemble un paquet OOXML
 * minimal, et `commentedDocx()` y pose l'ancre qui declenche la panne : une
 * ligne qui porte `w:commentReference` sans produire le moindre element, de
 * sorte que `$parent->getElement($parent->countElements() - 1)` vaut `null`.
 *
 * Le premier test verifie que la fixture reproduit VRAIMENT la panne : sans
 * cela, toute la suite serait verte pour de mauvaises raisons.
 */
class TASK1510DocxCommentFallbackTest extends TestCase
{
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    // ── La premisse ─────────────────────────────────────────────────────────

    /**
     * Sans ce test, tous les autres pourraient etre verts pour de mauvaises
     * raisons : une fixture qui ne casse pas PHPWord ne prouve rien du repli.
     */
    public function test_the_fixture_really_reproduces_the_phpword_failure(): void
    {
        $sane = $this->plainDocx();
        $commented = $this->commentedDocx();

        $this->assertNull($this->canonicalFailure($sane), 'un DOCX normal doit se charger sans erreur');

        $failure = $this->canonicalFailure($commented);
        $this->assertNotNull($failure, 'la fixture commentee doit faire echouer le chargement canonique');
        $this->assertInstanceOf(\TypeError::class, $failure);
        $this->assertStringContainsString('setCommentReference', $failure->getMessage());
    }

    // ── Le correctif ────────────────────────────────────────────────────────

    public function test_a_commented_document_is_extracted_without_its_comments(): void
    {
        $text = (new WordTextExtractor)->extract($this->commentedDocx());

        $this->assertNotNull($text, 'le corps d un document commente doit etre extrait');
        $this->assertStringContainsString('Corps visible du document', $text);
        $this->assertStringContainsString('Seconde ligne du corps', $text);

        // Les commentaires ne deviennent PAS des donnees RAG dans cette TASK.
        $this->assertStringNotContainsString('TEXTE_DU_COMMENTAIRE', $text, 'le texte du commentaire ne doit pas entrer dans l index');
        $this->assertStringNotContainsString('Relectrice', $text, 'ni le nom de son auteur');
    }

    /**
     * Le chemin nominal ne doit pas payer le repli : un DOCX normal ne produit
     * aucune copie, et le neutraliseur n'est jamais sollicite.
     */
    public function test_a_normal_document_never_triggers_the_fallback(): void
    {
        $spy = new class extends DocxCommentNeutralizer
        {
            public int $inspections = 0;

            public int $copies = 0;

            public function hasComments(string $absolutePath): bool
            {
                $this->inspections++;

                return parent::hasComments($absolutePath);
            }

            public function copyWithoutComments(string $absolutePath): ?string
            {
                $this->copies++;

                return parent::copyWithoutComments($absolutePath);
            }
        };

        $text = (new WordTextExtractor($spy))->extract($this->plainDocx());

        $this->assertNotNull($text);
        $this->assertSame(0, $spy->inspections, 'un chargement reussi ne doit meme pas interroger le document');
        $this->assertSame(0, $spy->copies, 'aucune copie ne doit etre produite sur le chemin nominal');
    }

    /** Contrat MASTER n°1 : le fichier soumis n'est jamais modifie. */
    public function test_the_submitted_file_is_never_modified(): void
    {
        $path = $this->commentedDocx();
        $before = hash_file('sha256', $path);

        $this->assertNotNull((new WordTextExtractor)->extract($path));

        $this->assertSame($before, hash_file('sha256', $path), 'le document soumis doit etre bit-a-bit identique apres extraction');
    }

    /** Contrat MASTER n°7 : une seule seconde tentative, jamais deux. */
    public function test_the_fallback_is_attempted_exactly_once_and_leaves_no_copy(): void
    {
        $spy = new class extends DocxCommentNeutralizer
        {
            public int $copies = 0;

            /** @var list<string> */
            public array $produced = [];

            public function copyWithoutComments(string $absolutePath): ?string
            {
                $this->copies++;
                $copy = parent::copyWithoutComments($absolutePath);

                if ($copy !== null) {
                    $this->produced[] = $copy;
                }

                return $copy;
            }
        };

        (new WordTextExtractor($spy))->extract($this->commentedDocx());

        $this->assertSame(1, $spy->copies, 'le repli ne doit etre tente qu une seule fois');

        foreach ($spy->produced as $copy) {
            $this->assertFileDoesNotExist($copy, 'la copie temporaire doit etre supprimee');
        }
    }

    /** Contrat MASTER n°8 : au-dela, on retombe sur le comportement fail-safe. */
    public function test_a_corrupted_document_still_returns_null_without_a_second_chance(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'task1510-broken-').'.docx';
        file_put_contents($path, 'ceci n est pas un paquet OOXML');
        $this->temporaryFiles[] = $path;

        $spy = new class extends DocxCommentNeutralizer
        {
            public int $copies = 0;

            public function copyWithoutComments(string $absolutePath): ?string
            {
                $this->copies++;

                return parent::copyWithoutComments($absolutePath);
            }
        };

        $this->assertNull((new WordTextExtractor($spy))->extract($path));
        $this->assertSame(0, $spy->copies, 'un fichier qui n est pas un DOCX ne doit declencher aucune copie');
    }

    // ── Non-regression ──────────────────────────────────────────────────────

    /**
     * Les modifications suivies (`w:ins` / `w:del`) vivent dans le meme corps
     * que les ancres de commentaires : le neutraliseur ne doit pas les emporter.
     */
    public function test_tracked_changes_survive_the_neutralization(): void
    {
        $body = '<w:p><w:ins w:id="7" w:author="Relectrice"><w:r><w:t>Ajout suivi</w:t></w:r></w:ins>'
            .'<w:del w:id="8" w:author="Relectrice"><w:r><w:delText>Suppression suivie</w:delText></w:r></w:del></w:p>'
            .'<w:p><w:commentRangeStart w:id="1"/><w:commentRangeEnd w:id="1"/><w:r><w:commentReference w:id="1"/></w:r></w:p>';

        $text = (new WordTextExtractor)->extract($this->commentedDocx($body));

        $this->assertNotNull($text);
        $this->assertStringContainsString('Ajout suivi', $text, 'une insertion suivie doit rester dans le texte indexe');
    }

    public function test_the_neutralizer_reports_comments_only_when_they_exist(): void
    {
        $neutralizer = new DocxCommentNeutralizer;

        $this->assertTrue($neutralizer->hasComments($this->commentedDocx()));
        $this->assertFalse($neutralizer->hasComments($this->plainDocx()));
    }

    /** Le repli est reserve au DOCX : les deux autres formats ne bougent pas. */
    public function test_other_formats_are_untouched(): void
    {
        $extractor = new FileContentExtractor;

        $this->assertNull($extractor->extract('%PDF-1.4 corrompu', 'application/pdf', 'rapport.pdf'));
        $this->assertSame('Une note', $extractor->extract('Une note', 'text/plain', 'note.txt'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function canonicalFailure(string $path): ?\Throwable
    {
        try {
            \PhpOffice\PhpWord\Settings::setTempDir(sys_get_temp_dir());
            \PhpOffice\PhpWord\IOFactory::load($path, 'Word2007');

            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    private function plainDocx(): string
    {
        return $this->docx(false, '<w:p><w:r><w:t>Corps visible du document</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Seconde ligne du corps</w:t></w:r></w:p>');
    }

    private function commentedDocx(?string $extraBody = null): string
    {
        // L'ancre fatale : une ligne qui porte `w:commentReference` sans
        // produire le moindre element, donc `getElement(-1)` -> null.
        $anchor = '<w:p><w:commentRangeStart w:id="1"/><w:commentRangeEnd w:id="1"/>'
            .'<w:r><w:commentReference w:id="1"/></w:r></w:p>';

        return $this->docx(true, '<w:p><w:r><w:t>Corps visible du document</w:t></w:r></w:p>'
            .($extraBody ?? $anchor)
            .'<w:p><w:r><w:t>Seconde ligne du corps</w:t></w:r></w:p>');
    }

    private function docx(bool $withComments, string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'task1510-').'.docx';
        $this->temporaryFiles[] = $path;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .($withComments ? '<Override PartName="/word/comments.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml"/>' : '')
            .'</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>');

        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document '.self::W.'><w:body>'.$body.'</w:body></w:document>');

        $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .($withComments ? '<Relationship Id="rId10" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments" Target="comments.xml"/>' : '')
            .'</Relationships>');

        if ($withComments) {
            $zip->addFromString('word/comments.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:comments '.self::W.'>'
                .'<w:comment w:id="1" w:author="Relectrice" w:date="2026-09-10T10:00:00Z">'
                .'<w:p><w:r><w:t>TEXTE_DU_COMMENTAIRE</w:t></w:r></w:p></w:comment></w:comments>');
        }

        $zip->close();

        return $path;
    }
}
