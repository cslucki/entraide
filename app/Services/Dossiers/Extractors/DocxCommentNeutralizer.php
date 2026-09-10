<?php

namespace App\Services\Dossiers\Extractors;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;
use ZipArchive;

/**
 * TASK-1510 — rendre lisible par PHPWord un DOCX porteur de commentaires Word.
 *
 * ## Le defaut, mesure
 *
 * PHPWord 1.4.0 leve un `TypeError` sur un DOCX commente :
 * `Reader\Word2007\AbstractPart::setCommentReference()` recoit `null` pour son
 * argument type `AbstractElement` (appel en `AbstractPart.php:485`, levee en
 * `:156`). L'appelant fait `$parent->getElement($parent->countElements() - 1)` :
 * quand la ligne qui porte l'ancre n'a produit aucun element — une ligne vide
 * commentee, par exemple — l'index vaut -1 et `getElement()` rend `null`.
 *
 * Consequence dans le produit : `FileContentExtractor::extract()` rend `null`,
 * et `DossierFileIndexer` SUPPRIME les chunks au lieu d'en creer. Un document
 * relu par un humain — donc commente — est exactement celui qui a le plus de
 * valeur pour le RAG, et c'etait celui qui ne s'indexait jamais.
 *
 * ## Ce que cette classe fait, et ce qu'elle ne fait pas
 *
 * Elle ne lit AUCUN texte metier. PHPWord reste l'unique moteur d'extraction :
 * ce service se contente de produire une COPIE du DOCX que PHPWord accepte de
 * lire. Aucun patch, aucun fork de `vendor/`.
 *
 * L'original n'est jamais ouvert en ecriture. `copyWithoutComments()` commence
 * par `copy()` et ne touche plus qu'a la copie — et l'appelant lui passe deja
 * un fichier temporaire (`FileContentExtractor::extractDocument()` ecrit les
 * octets dans un `tempnam` avant d'appeler l'extracteur), de sorte que le
 * fichier de `storage` est a deux pas de toute ecriture, jamais a zero.
 *
 * ## Pourquoi retirer les ancres NE SUFFIT PAS
 *
 * Mesure faite sur le document reel avant d'ecrire ce fichier : en retirant les
 * seules ancres `commentReference` / `commentRangeStart` / `commentRangeEnd`,
 * PHPWord echoue autrement — `InvalidArgumentException: Comment with id 31
 * isn't referenced in document`. `word/comments.xml` declare alors des
 * commentaires devenus orphelins, et le lecteur refuse. La neutralisation doit
 * donc porter sur les QUATRE endroits ou le format declare des commentaires :
 * le corps, les parties `word/comments*.xml`, leurs relations, et les types de
 * contenu. Avec les quatre : 206 318 caracteres extraits, 65 chunks.
 *
 * ## Aucune regexp sur le XML
 *
 * Tout passe par `DOMDocument` + `DOMXPath` avec `local-name()`, insensible au
 * prefixe de namespace choisi par le producteur du fichier. Le seul motif
 * textuel est le nom des ENTREES du zip (`word/comments*.xml`), qui n'est pas
 * du XML.
 *
 * Les commentaires eux-memes ne deviennent PAS des donnees RAG : ils
 * disparaissent de la copie. Seul le corps du document est indexe.
 */
class DocxCommentNeutralizer
{
    /**
     * Les trois familles d'ancres posees DANS le corps du document.
     *
     * @var list<string>
     */
    private const ANCHOR_ELEMENTS = ['commentReference', 'commentRangeStart', 'commentRangeEnd'];

    /** Les parties du paquet qui portent les commentaires eux-memes. */
    private const COMMENT_PART_PATTERN = '#^word/comments.*\.xml$#i';

    /** Ce qui, dans une relation ou un type de contenu, designe ces parties. */
    private const COMMENT_PART_NEEDLE = 'comments';

    /**
     * Le document porte-t-il des commentaires ?
     *
     * C'est la SEULE question posee pour decider du repli. On n'inspecte jamais
     * le message de l'exception PHPWord : une correspondance de chaine sur un
     * texte d'erreur de dependance casse au premier changement de version, et
     * ne dit rien du document. Ici, on interroge le document lui-meme.
     */
    public function hasComments(string $absolutePath): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($absolutePath) !== true) {
            return false;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (preg_match(self::COMMENT_PART_PATTERN, (string) $zip->getNameIndex($i)) === 1) {
                    return true;
                }
            }

            $body = $zip->getFromName('word/document.xml');

            if (! is_string($body) || $body === '') {
                return false;
            }

            $dom = $this->parse($body);

            if ($dom === null) {
                return false;
            }

            return $this->anchors($dom) !== [];
        } finally {
            $zip->close();
        }
    }

    /**
     * Une copie du DOCX, sans aucune declaration de commentaire.
     *
     * @return string|null le chemin de la copie temporaire, a la charge de
     *                     l'appelant de la supprimer ; `null` si la copie n'a
     *                     pas pu etre produite.
     */
    public function copyWithoutComments(string $absolutePath): ?string
    {
        $copy = tempnam(sys_get_temp_dir(), 'bp-docx-');

        if ($copy === false) {
            return null;
        }

        if (! copy($absolutePath, $copy)) {
            @unlink($copy);

            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($copy) !== true) {
            @unlink($copy);

            return null;
        }

        try {
            $this->stripAnchorsFromBody($zip);
            $this->removeCommentParts($zip);
            $this->stripCommentRelationships($zip);
            $this->stripCommentContentTypes($zip);
            $zip->close();

            return $copy;
        } catch (Throwable) {
            // Un paquet inattendu ne doit pas faire echouer l'extraction
            // autrement que par le `null` fail-safe deja en place.
            $zip->close();
            @unlink($copy);

            return null;
        }
    }

    /** Le corps : on retire les ancres, on garde tout le reste. */
    private function stripAnchorsFromBody(ZipArchive $zip): void
    {
        $body = $zip->getFromName('word/document.xml');

        if (! is_string($body) || $body === '') {
            return;
        }

        $dom = $this->parse($body);

        if ($dom === null) {
            return;
        }

        foreach ($this->anchors($dom) as $node) {
            $node->parentNode?->removeChild($node);
        }

        $xml = $dom->saveXML();

        if (is_string($xml)) {
            $zip->addFromString('word/document.xml', $xml);
        }
    }

    /** Les parties de commentaires : `comments`, `commentsExtended`, `commentsIds`… */
    private function removeCommentParts(ZipArchive $zip): void
    {
        $parts = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match(self::COMMENT_PART_PATTERN, $name) === 1) {
                $parts[] = $name;
            }
        }

        // Deux passes : supprimer pendant qu'on enumere les index deplacerait
        // les suivants.
        foreach ($parts as $part) {
            $zip->deleteName($part);
        }
    }

    /** Les relations qui pointent vers ces parties deviendraient pendantes. */
    private function stripCommentRelationships(ZipArchive $zip): void
    {
        $this->rewrite($zip, 'word/_rels/document.xml.rels', 'Relationship', 'Target');
    }

    /** Les types de contenu declares pour ces parties, de meme. */
    private function stripCommentContentTypes(ZipArchive $zip): void
    {
        $this->rewrite($zip, '[Content_Types].xml', 'Override', 'PartName');
    }

    /**
     * Retire d'une partie XML les elements `$elementName` dont l'attribut
     * `$attribute` designe une partie de commentaires.
     */
    private function rewrite(ZipArchive $zip, string $entry, string $elementName, string $attribute): void
    {
        $raw = $zip->getFromName($entry);

        if (! is_string($raw) || $raw === '') {
            return;
        }

        $dom = $this->parse($raw);

        if ($dom === null) {
            return;
        }

        $xpath = new DOMXPath($dom);
        $doomed = [];

        foreach ($xpath->query(sprintf('//*[local-name()=%s]', $this->xpathLiteral($elementName))) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if (str_contains(strtolower($node->getAttribute($attribute)), self::COMMENT_PART_NEEDLE)) {
                $doomed[] = $node;
            }
        }

        foreach ($doomed as $node) {
            $node->parentNode?->removeChild($node);
        }

        $xml = $dom->saveXML();

        if (is_string($xml)) {
            $zip->addFromString($entry, $xml);
        }
    }

    /**
     * Les noeuds d'ancre du corps.
     *
     * `local-name()` : le prefixe de namespace (`w:`) est un choix du
     * producteur du fichier, pas une garantie du format.
     *
     * @return list<DOMElement>
     */
    private function anchors(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $found = [];

        foreach (self::ANCHOR_ELEMENTS as $name) {
            foreach ($xpath->query(sprintf('//*[local-name()=%s]', $this->xpathLiteral($name))) ?: [] as $node) {
                if ($node instanceof DOMElement) {
                    $found[] = $node;
                }
            }
        }

        return $found;
    }

    private function parse(string $xml): ?DOMDocument
    {
        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = true;

        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $dom : null;
    }

    /** Un litteral XPath sur : aucun nom d'element du produit ne contient de guillemet. */
    private function xpathLiteral(string $value): string
    {
        return "'".str_replace("'", '', $value)."'";
    }
}
