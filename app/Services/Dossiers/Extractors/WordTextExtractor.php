<?php

namespace App\Services\Dossiers\Extractors;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

/**
 * DOCX via phpoffice/phpword (LGPL-3.0, utilise non modifie). PHPWord n'a
 * pas d'ecrivain « texte brut » : on parcourt son MODELE d'elements
 * (sections, paragraphes, tableaux, listes, titres) — jamais le XML du
 * conteneur, interdit par la TASK.
 *
 * TASK-1510 — un DOCX porteur de COMMENTAIRES Word faisait lever PHPWord 1.4.0
 * (`TypeError` dans `Reader\Word2007\AbstractPart::setCommentReference()`), et
 * l'extraction rendait `null` : `DossierFileIndexer` supprimait alors les
 * chunks au lieu d'en creer. Un document relu par un humain — donc commente —
 * est justement celui qui a le plus de valeur pour le RAG, et c'etait celui qui
 * ne s'indexait jamais.
 *
 * Le repli est ci-dessous : UNE seconde tentative, sur une COPIE dont les
 * declarations de commentaires ont ete retirees (`DocxCommentNeutralizer`).
 * Le chemin nominal, lui, ne change pas d'un octet : un DOCX normal ne produit
 * aucune copie.
 */
class WordTextExtractor implements DocumentTextExtractor
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(
        private readonly DocxCommentNeutralizer $neutralizer = new DocxCommentNeutralizer,
    ) {}

    public function supports(string $mimeType): bool
    {
        return $mimeType === self::MIME_TYPE;
    }

    public function extract(string $absolutePath): ?string
    {
        // Aucun rendu, aucune image decodee : seuls les objets texte nous
        // interessent. Le dossier temporaire sert aux lecteurs PHPWord qui
        // decompressent certaines parties.
        Settings::setTempDir(sys_get_temp_dir());

        $document = $this->load($absolutePath);

        if ($document === null) {
            $document = $this->loadWithoutComments($absolutePath);
        }

        if ($document === null) {
            return null;
        }

        $lines = [];

        foreach ($document->getSections() as $section) {
            $this->collect($section, $lines);
        }

        // Le lecteur Word2007 de PHPWord echappe le texte a la lecture
        // (`htmlspecialchars`, pour son ecrivain HTML) : « R&D » arrive en
        // « R&amp;D ». On rend au texte sa forme d'origine.
        $text = trim(html_entity_decode(implode("\n", $lines), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }

    /**
     * La seconde et DERNIERE tentative : sur une copie sans commentaires.
     *
     * Le declencheur n'est jamais le message de l'exception — une
     * correspondance de chaine sur un texte d'erreur de dependance casse au
     * premier changement de version, et ne dit rien du document. On interroge
     * le DOCUMENT : porte-t-il des commentaires ? Sinon, l'echec est un vrai
     * echec et l'on rend `null`, exactement comme avant cette TASK.
     */
    private function loadWithoutComments(string $absolutePath): ?PhpWord
    {
        if (! $this->neutralizer->hasComments($absolutePath)) {
            return null;
        }

        $copy = $this->neutralizer->copyWithoutComments($absolutePath);

        if ($copy === null) {
            return null;
        }

        try {
            return $this->load($copy);
        } finally {
            @unlink($copy);
        }
    }

    /** Un chargement PHPWord, ou `null`. Aucune autre relance ailleurs. */
    private function load(string $path): ?PhpWord
    {
        try {
            return IOFactory::load($path, 'Word2007');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function collect(AbstractElement $element, array &$lines): void
    {
        if ($element instanceof Table) {
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellLines = [];
                    $this->collect($cell, $cellLines);
                    $cells[] = trim(implode(' ', $cellLines));
                }
                $lines[] = implode(' ', array_filter($cells, static fn (string $cell): bool => $cell !== ''));
            }

            return;
        }

        if ($element instanceof TextRun) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $childLines = [];
                $this->collect($child, $childLines);
                $parts[] = implode(' ', $childLines);
            }
            $lines[] = implode('', $parts);

            return;
        }

        if ($element instanceof Title) {
            $title = $element->getText();
            if ($title instanceof TextRun) {
                $this->collect($title, $lines);
            } elseif (is_string($title)) {
                $lines[] = $title;
            }

            return;
        }

        if ($element instanceof ListItem) {
            $lines[] = (string) $element->getText();

            return;
        }

        if ($element instanceof Text || $element instanceof Link) {
            $lines[] = (string) $element->getText();

            return;
        }

        if ($element instanceof PreserveText) {
            $text = $element->getText();
            $lines[] = is_array($text) ? implode('', array_map('strval', $text)) : (string) $text;

            return;
        }

        if ($element instanceof TextBreak) {
            $lines[] = '';

            return;
        }

        // Section, Cell, ListItemRun, TextBox, Footnote... : tout conteneur
        // PHPWord. Les elements sans texte (Image, Chart, Line, PageBreak...)
        // n'ont rien a livrer et tombent ici sans effet.
        if ($element instanceof AbstractContainer) {
            foreach ($element->getElements() as $child) {
                $this->collect($child, $lines);
            }
        }
    }
}
