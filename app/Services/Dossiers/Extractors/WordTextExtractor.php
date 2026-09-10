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
    /**
     * Longueur maximale d'une cellule qui recoit son en-tete de colonne
     * (TASK-1522). Au-dela, c'est du texte qui se suffit a lui-meme.
     */
    private const KEYED_CELL_MAX_CHARS = 24;

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
     * Un tableau, ligne par ligne, chaque ligne AUTONOME.
     *
     * TASK-1522. Trois pertes cumulees, mesurees etage par etage sur le
     * tableau « Table 3.1f: Summary of staff effort » du dossier ARIA :
     *
     *   1. `implode(' ', ...)` rendait une cellule indiscernable d'un mot ;
     *   2. `array_filter(...)` retirait les cellules VIDES, decalant chaque
     *      ligne d'un nombre different de colonnes (8 a 14 jetons pour un
     *      en-tete de 12) ;
     *   3. ArticleChunker ecrase tout blanc, donc un retour a la ligne ne
     *      survit pas, ET sa fenetre de 500 mots coupe le tableau : la ligne
     *      « Total » arrivait au modele dans un chunk sans sa legende ni son
     *      en-tete `PMs`, colle a la legende du tableau SUIVANT
     *      (« Subcontracting costs »). Mesure : « 489.95 » person-months
     *      rendu comme un budget en euros, 3 tirages sur 3, meme apres le
     *      correctif des cellules et des lignes.
     *
     * D'ou la forme retenue, deterministe et lisible :
     *
     *   |WP1|WP2|PMs¶
     *   1/ UNIVE|WP1: 5|WP2: 6|PMs: 88¶
     *   Total|WP1: 30.25|WP2: 76|PMs: 489.95¶
     *
     * - la barre separe les cellules ; une cellule vide garde sa place ;
     * - barre et pilcrow sont COLLES aux cellules, jamais entoures
     *   d'espaces : le chunker compte un mot par suite de non-blancs, et
     *   « a | b | c ¶ » coutait neuf mots par ligne. Mesure sur ARIA : la
     *   liste des 19 participants, qui tenait dans un chunk, debordait sa
     *   fenetre et le modele n'en comptait plus que 14 ;
     * - le pilcrow termine la ligne : signe typographique de la fin de
     *   ligne, absent des cellules, il survit a l'ecrasement des blancs
     *   (« | | » ne pouvait pas jouer ce role : c'est aussi une cellule vide) ;
     * - chaque cellule de donnees porte son EN-TETE de colonne : la ligne se
     *   suffit a elle-meme, ou que la coupe le chunker. Le chunker n'a pas a
     *   connaitre les tableaux, et la prose n'est pas touchee.
     *
     * La premiere ligne est l'en-tete si le tableau a au moins deux lignes et
     * deux colonnes et si cette ligne n'est pas faite de nombres — sinon les
     * lignes restent positionnelles (barres et pilcrow, sans cles). Une ligne
     * d'une seule cellule est un paragraphe de mise en page : elle reste nue.
     *
     * @param  list<string>  $lines
     */
    private function collectTable(Table $table, array &$lines): void
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];
            foreach ($row->getCells() as $cell) {
                $cellLines = [];
                $this->collect($cell, $cellLines);
                $cells[] = trim(implode(' ', $cellLines));
            }

            // Une ligne ENTIEREMENT vide n'apporte rien : c'est la seule chose
            // que l'ancien filtre faisait de juste.
            if (array_filter($cells, static fn (string $cell): bool => $cell !== '') === []) {
                continue;
            }

            $rows[] = $cells;
        }

        if ($rows === []) {
            return;
        }

        $width = max(array_map('count', $rows));
        $headers = $width >= 2 && count($rows) >= 2 && $this->looksLikeHeader($rows[0]) ? $rows[0] : null;

        foreach ($rows as $index => $cells) {
            if (count($cells) < 2) {
                $lines[] = $cells[0];

                continue;
            }

            if ($headers === null || $index === 0) {
                $lines[] = implode('|', $cells).'¶';

                continue;
            }

            $keyed = [];
            foreach ($cells as $position => $cell) {
                $key = trim((string) ($headers[$position] ?? ''));
                // Un en-tete vide (colonne des libelles) ou un en-tete de la
                // longueur d'un paragraphe ne fait pas une cle utile. Et seule
                // une cellule COURTE — un nombre, un code, une date — recoit la
                // sienne : une phrase porte deja son sens. Mesure sur ARIA
                // quand toute cellule etait prefixee : les chunks du tableau
                // des risques (jusqu'a 103 cles) passaient en tete du
                // retrieval pour « budget total du FSTP » et le fait en prose
                // « €720,000 » tombait du rang 1 au rang 20.
                $courte = mb_strlen($cell) <= self::KEYED_CELL_MAX_CHARS;
                $keyed[] = $key === '' || mb_strlen($key) > 60 || ! $courte ? $cell : $key.': '.$cell;
            }

            $lines[] = implode('|', $keyed).'¶';
        }
    }

    /**
     * Une ligne faite de nombres n'est pas un en-tete : la nommer cle
     * fabriquerait des libelles absurdes (« 5: 6 »).
     *
     * @param  list<string>  $cells
     */
    private function looksLikeHeader(array $cells): bool
    {
        $filled = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));

        if ($filled === []) {
            return false;
        }

        $numeric = count(array_filter($filled, static fn (string $c): bool => is_numeric(str_replace([',', ' '], ['.', ''], $c))));

        return $numeric * 2 < count($filled);
    }

    /**
     * @param  list<string>  $lines
     */
    private function collect(AbstractElement $element, array &$lines): void
    {
        if ($element instanceof Table) {
            $this->collectTable($element, $lines);

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
