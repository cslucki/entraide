<?php

namespace Tests\Feature;

use App\Services\Dossiers\ArticleChunker;
use App\Services\Dossiers\Extractors\WordTextExtractor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * TASK-1522 — Un tableau DOCX garde ses lignes, ses cellules et ses en-tetes.
 *
 * ## Le defaut, trace etage par etage sur le document ARIA reel
 *
 * | etage | lignes | cellules | en-tete | valeur -> colonne |
 * |---|---|---|---|---|
 * | DOCX XML | 238 `w:tr` | 1335 `w:tc` | oui | **oui** |
 * | WordTextExtractor | oui (`\n`) | **NON** | present comme texte | **NON** |
 * | ArticleChunker | **NON** (`\s+` -> espace) | — | — | — |
 *
 * `TABLE_STRUCTURE_LOSS_STAGE = EXTRACTION` pour les cellules, puis CHUNKING
 * pour les lignes. Le chunker ecrase tout blanc par definition d'un mot — le
 * corriger changerait la representation de TOUTE la prose de la plateforme.
 * La ligne se termine donc par un marqueur non-blanc, emis par l'extracteur,
 * seul etage a savoir qu'une ligne est une ligne. Mesure de l'etat
 * intermediaire (cellules seules) : « 493.3 » toujours rendu comme budget,
 * 3 tirages sur 3, et « | PMs 1/ UNIVE » fusionnait deux lignes.
 *
 * Deux degats cumules, tous deux sur la meme ligne :
 *
 *   1. `implode(' ', ...)` : une cellule devient indiscernable d'un mot ;
 *   2. `array_filter(...)` : les cellules VIDES disparaissent, donc chaque
 *      ligne se decale d'un nombre DIFFERENT de colonnes.
 *
 * Mesure sur le tableau « Table 3.1f: Summary of staff effort » : les lignes
 * portaient de 8 a 14 jetons alors que l'en-tete en declare 12. Apres
 * correctif : 21 lignes, 13 champs chacune.
 *
 * Consequence produit : « 489.95 », total de la colonne `PMs` (person-months),
 * etait rendu comme un budget en euros.
 *
 * ## Ce que ces tests mesurent
 *
 * Une fixture CONSTRUITE par le test, jamais un document reel : le fichier
 * ARIA sert a la recette, pas au harnais. Chaque test nomme la propriete
 * structurelle qu'il garde, jamais une chaine attendue en entier.
 */
class TASK1522DocxTableStructureTest extends TestCase
{
    private string $chemin = '';

    protected function tearDown(): void
    {
        if ($this->chemin !== '' && is_file($this->chemin)) {
            @unlink($this->chemin);
        }

        parent::tearDown();
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function docxAvecTableau(array $rows, string $prose = ''): string
    {
        $word = new PhpWord;
        $section = $word->addSection();

        if ($prose !== '') {
            $section->addText($prose);
        }

        $table = $section->addTable();

        foreach ($rows as $row) {
            $table->addRow();
            foreach ($row as $cellule) {
                $cell = $table->addCell(2000);
                if ($cellule !== '') {
                    $cell->addText($cellule);
                }
            }
        }

        $this->chemin = tempnam(sys_get_temp_dir(), 't1522').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($this->chemin);

        return $this->chemin;
    }

    private function extrait(string $chemin): string
    {
        return (string) app(WordTextExtractor::class)->extract($chemin);
    }

    /** @return list<string> */
    private function lignesDeTableau(string $texte): array
    {
        return array_values(array_filter(
            explode("\n", $texte),
            static fn (string $l): bool => str_contains($l, '|'),
        ));
    }

    // ── La garde ────────────────────────────────────────────────────────────

    /**
     * LE test de cette TASK. Une cellule vide occupe sa place, sinon toutes
     * les valeurs a sa droite changent de colonne — et un total d'effort
     * devient un budget.
     *
     * Sabotage : retablir `array_filter` avant l'implode → rouge.
     */
    public function test_an_empty_cell_keeps_its_column(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['', 'WP1', 'WP2', 'WP3', 'PMs'],
            ['Alpha', '5', '6', '7', '18'],
            ['Beta', '2', '', '', '2'],       // deux cellules vides au milieu
            ['Total', '7', '6', '7', '20'],
        ]));

        $lignes = $this->lignesDeTableau($texte);

        $this->assertCount(4, $lignes, 'les quatre lignes du tableau doivent survivre');

        $largeurs = array_unique(array_map(
            static fn (string $l): int => count(explode('|', rtrim($l, "\xC2\xB6"))),
            $lignes,
        ));

        $this->assertCount(1, $largeurs,
            'toutes les lignes doivent porter le MEME nombre de champs : '
            .'sans cela aucune valeur ne peut etre rattachee a sa colonne');
        $this->assertSame(5, $largeurs[0], 'cinq colonnes declarees, cinq champs par ligne');
    }

    /**
     * La frontiere de cellule doit exister. Jointes par une espace nue, deux
     * cellules sont indiscernables d'un mot suivi d'un autre.
     *
     * Sabotage : revenir a `implode(' ', ...)` → rouge.
     */
    public function test_two_cells_are_separated_by_a_boundary(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['Poste', 'Montant'],
            ['Materiel', '1200'],
        ]));

        $this->assertStringContainsString('Materiel|Montant: 1200', $texte,
            'la frontiere doit etre lisible entre le libelle et la valeur');
        $this->assertStringNotContainsString('Materiel 1200', $texte,
            'la forme aplatie ne doit plus exister');
    }

    /**
     * La derniere colonne d'une ligne de total doit rester alignee sous son
     * en-tete. C'est exactement le cas ARIA : `PMs` (person-months), pas des
     * euros.
     */
    public function test_a_total_stays_under_its_own_header(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['', 'WP1', 'WP2', 'PMs'],
            ['Alpha', '5', '', '5'],
            ['Total', '5', '', '5'],
        ]));

        // Le marqueur de fin de ligne n'est pas une cellule : on l'ote avant
        // de compter, sinon la derniere cellule le porterait.
        $lignes = array_map(static fn (string $l): string => rtrim($l, "\xC2\xB6"), $this->lignesDeTableau($texte));
        $entete = explode('|', $lignes[0]);
        $total = explode('|', $lignes[2]);

        $this->assertSame(count($entete), count($total),
            'en-tete et ligne de total doivent avoir la meme largeur');
        $this->assertSame('PMs', trim(end($entete)));
        $this->assertSame('PMs: 5', trim(end($total)),
            'la derniere valeur du total porte l en-tete PMs — ou que le chunker la coupe');
    }

    /**
     * LE correctif du P0. Une ligne de donnees porte ses en-tetes de colonne :
     * separee de l'en-tete par une coupe du chunker, elle dit encore que
     * « 489.95 » est un total de `PMs`, pas d'euros. Mesure sur ARIA : la
     * ligne « Total » arrivait au modele dans un chunk sans son en-tete, 3
     * tirages sur 3, apres le seul correctif cellules + lignes.
     *
     * Sabotage : ne plus prefixer les cellules par leur en-tete → rouge.
     */
    public function test_a_data_row_carries_its_column_headers(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['', 'WP1', 'WP2', 'PMs'],
            ['Alpha', '5', '6', '11'],
            ['Total', '5', '6', '11'],
        ]));

        $this->assertStringContainsString('Alpha|WP1: 5|WP2: 6|PMs: 11¶', $texte);
        $this->assertStringContainsString('Total|WP1: 5|WP2: 6|PMs: 11¶', $texte);
        $this->assertStringContainsString('|WP1|WP2|PMs¶', $texte,
            'l en-tete lui-meme reste une ligne positionnelle, sans cles');
    }

    /**
     * Seule une cellule COURTE recoit son en-tete. Une cellule de la longueur
     * d'une phrase porte deja son sens ; la prefixer repete l'en-tete a
     * chaque ligne et sature l'embedding du chunk. Mesure sur ARIA : le
     * tableau des risques passait en tete du retrieval pour une question de
     * budget, et le fait en prose « €720,000 » tombait du rang 1 au rang 20.
     *
     * Sabotage : prefixer toute cellule quelle que soit sa longueur → rouge.
     */
    public function test_only_a_short_cell_receives_its_header(): void
    {
        $phrase = 'The coordinator is constantly monitoring the partners and will reallocate effort if needed.';
        $texte = $this->extrait($this->docxAvecTableau([
            ['Risk', 'WP', 'Mitigation'],
            ['Delays', '6', $phrase],
        ]));

        $this->assertStringContainsString('WP: 6', $texte, 'un nombre nu recoit sa colonne');
        $this->assertStringContainsString('|'.$phrase.'¶', $texte, 'la phrase reste nue');
        $this->assertStringNotContainsString('Mitigation: The coordinator', $texte,
            'un en-tete repete devant chaque phrase saturerait le chunk');
    }

    /**
     * Une premiere ligne faite de nombres n'est pas un en-tete : la nommer
     * cle fabriquerait « 5: 6 ». Le tableau reste positionnel.
     */
    public function test_a_numeric_first_row_is_not_taken_for_a_header(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['5', '6', '11'],
            ['7', '8', '15'],
        ]));

        $this->assertStringContainsString('7|8|15¶', $texte);
        $this->assertStringNotContainsString(': ', $texte,
            'aucune cle fabriquee a partir d un nombre');
    }

    /**
     * Un en-tete VIDE — la colonne des libelles — ne produit pas de cle : le
     * libelle reste tel quel, jamais « : Alpha ».
     */
    public function test_an_empty_header_cell_leaves_the_label_unkeyed(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['', 'Montant'],
            ['Materiel', '1200'],
        ]));

        $this->assertStringContainsString('Materiel|Montant: 1200¶', $texte);
        $this->assertStringNotContainsString(': Materiel', $texte);
    }

    /**
     * Une ligne ENTIEREMENT vide n'apporte rien : c'est la seule chose que
     * l'ancien filtre faisait de juste, et elle est conservee.
     */
    public function test_a_wholly_empty_row_is_dropped(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['Poste', 'Montant'],
            ['', ''],
            ['Materiel', '1200'],
        ]));

        $this->assertCount(2, $this->lignesDeTableau($texte),
            'la ligne vide ne doit pas produire une ligne de separateurs nus');
    }

    /**
     * Les delimiteurs ne coutent AUCUN mot au chunker. Mesure sur ARIA avec
     * « a | b | c ¶ » : neuf mots de plus par ligne, la liste des 19
     * participants debordait sa fenetre de 500 mots et le modele en comptait
     * 14. Une ligne sans cle doit peser exactement ses cellules.
     *
     * Sabotage : entourer la barre ou le pilcrow d'espaces → rouge.
     */
    public function test_delimiters_cost_no_word_to_the_chunker(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['Name', 'Country', 'Expertise'],
            ['Universita di Venezia', 'IT', 'Environmental economics and organization studies'],
        ]));

        $ligne = $this->lignesDeTableau($texte)[1];
        $mots = preg_match_all('/\S+/u', $ligne);

        $this->assertSame(9, $mots,
            'Universita di Venezia (3) + IT (1) + Environmental economics and organization studies (5) = 9, pas un de plus');
    }

    // ── Non-regression ──────────────────────────────────────────────────────

    /**
     * Un document SANS tableau ne doit gagner aucun separateur. Le correctif
     * doit etre invisible pour la prose, qui est l'immense majorite du corpus.
     */
    public function test_prose_without_a_table_gains_no_separator(): void
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addText('Le projet decrit ses methodes de travail et ses etapes.');
        $section->addText('Une seconde phrase, sans le moindre tableau.');

        $this->chemin = tempnam(sys_get_temp_dir(), 't1522p').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($this->chemin);

        $texte = $this->extrait($this->chemin);

        $this->assertStringContainsString('methodes de travail', $texte);
        $this->assertStringNotContainsString('|', $texte,
            'aucune barre ne doit apparaitre dans un document sans tableau');
    }

    /**
     * Un tableau d'UNE colonne — motif frequent de mise en page dans Word —
     * ne doit pas gagner de separateur non plus : il n'y a pas de frontiere
     * a marquer.
     */
    public function test_a_single_column_table_gains_no_separator(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['Un encadre de mise en page'],
            ['Une seconde ligne d encadre'],
        ]));

        $this->assertStringContainsString('Un encadre de mise en page', $texte);
        $this->assertStringNotContainsString('|', $texte,
            'une seule cellule par ligne : aucune frontiere a marquer');
        $this->assertStringNotContainsString('¶', $texte,
            'ni fin de ligne a marquer : une ligne d une cellule est un paragraphe');
    }

    /**
     * Une frontiere de LIGNE doit survivre au chunker, qui ecrase tout blanc.
     * C'est le second etage de perte : sans ce test, l'extracteur peut etre
     * parfait et le modele recevoir quand meme deux lignes collees.
     *
     * Sabotage : retirer le marqueur de fin de ligne → rouge.
     */
    public function test_a_row_boundary_survives_the_chunker(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['', 'WP1', 'PMs'],
            ['Alpha', '5', '5'],
            ['Total', '5', '5'],
        ]));

        $chunks = app(ArticleChunker::class)->chunk($texte);
        $contenu = $chunks[0]['content'];

        $this->assertStringNotContainsString("\n", $contenu,
            'premisse : le chunker a bien ecrase les retours a la ligne');

        $lignes = array_values(array_filter(array_map('trim', explode('¶', $contenu))));

        $this->assertCount(3, $lignes, 'trois lignes doivent rester separables apres le chunker');
        $this->assertStringStartsWith('Total', $lignes[2],
            'la ligne de total commence a sa propre frontiere, pas collee a la precedente');
        $this->assertStringNotContainsString('PMs Alpha', $contenu,
            'la fin de l en-tete ne doit pas fusionner avec le libelle de la ligne suivante');
        $this->assertStringContainsString('PMs: 5', $lignes[2],
            'coupee de l en-tete, la ligne de total sait encore que 5 est un PMs');
    }

    /**
     * Le texte des cellules reste intact — le correctif touche l'assemblage,
     * jamais le contenu.
     */
    public function test_cell_text_is_preserved_verbatim(): void
    {
        $texte = $this->extrait($this->docxAvecTableau([
            ['Universita Ca Foscari Venezia', '88'],
        ]));

        $this->assertStringContainsString('Universita Ca Foscari Venezia', $texte);
    }
}
