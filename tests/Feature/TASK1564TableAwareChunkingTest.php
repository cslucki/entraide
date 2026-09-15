<?php

namespace Tests\Feature;

use App\Services\Dossiers\ArticleChunker;
use Tests\TestCase;

/**
 * TASK-1564 — un chunk ne melange plus un tableau et la prose d'a cote.
 *
 * Ce que ces tests protegent : la FRONTIERE. Avant cette TASK, le chunker
 * tranchait par fenetre de 500 mots sans rien savoir de la structure, et sur
 * le Dossier ARIA **20 chunks sur 20** collaient du tableau a autre chose —
 * l'un commencait au milieu d'une ligne et finissait dans un paragraphe sur
 * un chercheur d'une autre section.
 *
 * Les tests travaillent directement sur le format de sortie de
 * `WordTextExtractor` (`|` entre cellules, `¶` en fin de ligne) plutot que de
 * fabriquer des .docx : c'est ce format que le chunker recoit reellement, et
 * `TASK1522DocxTableStructureTest` couvre deja le trajet .docx -> texte.
 */
class TASK1564TableAwareChunkingTest extends TestCase
{
    private ArticleChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chunker = new ArticleChunker;
    }

    /** REQ 1 — une ligne de tableau n'est JAMAIS coupee. */
    public function test_a_table_row_is_never_split(): void
    {
        $texte = $this->tableau(30);

        // Une fenetre minuscule : le chemin legacy aurait hache chaque ligne.
        $chunks = $this->contenus($this->chunker->chunk($texte, targetSize: 8, overlap: 2));

        foreach ($chunks as $contenu) {
            foreach ($this->lignesDe($contenu) as $ligne) {
                $this->assertStringContainsString('|', $ligne, "ligne amputee de ses cellules : {$ligne}");
                $this->assertMatchesRegularExpression('/^L\d+\b/', $ligne, "ligne commencant en cours de route : {$ligne}");
                $this->assertStringEndsWith('fin', rtrim($ligne, '¶'), "ligne tronquee avant sa fin : {$ligne}");
            }
        }
    }

    /** REQ 2 — tableau et prose suivante ne partagent JAMAIS un chunk. */
    public function test_a_table_never_shares_a_chunk_with_the_surrounding_prose(): void
    {
        $texte = "Un paragraphe avant le tableau.\n"
            .$this->tableau(6)."\n"
            .'Un paragraphe apres le tableau, sans aucun rapport.';

        foreach ($this->contenus($this->chunker->chunk($texte)) as $contenu) {
            $tabulaire = str_contains($contenu, '¶');

            if ($tabulaire) {
                $this->assertStringNotContainsString('paragraphe apres', $contenu,
                    'un fragment de tableau a avale la prose suivante');

                continue;
            }

            // Ici `¶` est deja absent PAR DEFINITION de la ligne au-dessus :
            // l'affirmer ne garderait rien. C'est le CONTENU qu'il faut
            // interroger — de la matiere tabulaire peut migrer dans un chunk
            // de prose en ayant perdu son pilcrow en chemin.
            foreach (['EnteteA', 'L1', '1 valeur', '1 fin'] as $cellule) {
                $this->assertStringNotContainsString($cellule, $contenu,
                    "un chunk de prose porte la cellule « {$cellule} » du tableau");
            }
        }
    }

    /** REQ 3 — l'en-tete accompagne CHAQUE fragment du tableau. */
    public function test_the_header_row_travels_with_every_fragment(): void
    {
        // 9 lignes de donnees -> au moins trois fragments de 3.
        $texte = $this->tableau(10);
        $fragments = array_filter($this->contenus($this->chunker->chunk($texte)),
            static fn (string $c): bool => str_contains($c, '¶'));

        $this->assertGreaterThanOrEqual(3, count($fragments), 'il faut plusieurs fragments pour que le test ait un sens');

        foreach ($fragments as $fragment) {
            $this->assertStringContainsString('EnteteA', $fragment,
                'un fragment a perdu la ligne d en-tete du tableau');
        }
    }

    /** REQ 4 — la legende accompagne chaque fragment quand elle existe. */
    public function test_the_caption_travels_with_every_fragment_when_it_exists(): void
    {
        $texte = "Table 3.1f: Summary of staff effort\n".$this->tableau(10);
        $fragments = array_filter($this->contenus($this->chunker->chunk($texte)),
            static fn (string $c): bool => str_contains($c, '¶'));

        foreach ($fragments as $fragment) {
            $this->assertStringContainsString('Table 3.1f', $fragment,
                'un fragment a perdu la legende de son tableau');
        }

        // ...et JAMAIS de legende fabriquee quand le document n'en a pas.
        $sansLegende = array_filter($this->contenus($this->chunker->chunk($this->tableau(4))),
            static fn (string $c): bool => str_contains($c, '¶'));

        foreach ($sansLegende as $fragment) {
            $this->assertStringStartsWith('EnteteA', $fragment,
                'une legende a ete inventee alors que le document n en portait aucune');
        }
    }

    /** REQ 5 — la legende reste AUSSI dans la prose : on la recopie, on ne la deplace pas. */
    public function test_the_caption_is_copied_not_moved_away_from_the_prose(): void
    {
        $texte = "Introduction du document.\nTable 3.1f: Summary of staff effort\n".$this->tableau(4);
        $chunks = $this->contenus($this->chunker->chunk($texte));

        $prose = array_filter($chunks, static fn (string $c): bool => ! str_contains($c, '¶'));

        $this->assertNotEmpty($prose, 'la prose doit toujours produire son propre chunk');
        $this->assertStringContainsString('Table 3.1f', implode(' ', $prose),
            'la legende a ete DEPLACEE dans le tableau au lieu d etre recopiee');
    }

    /** REQ 6 — prose seule : sortie identique OCTET POUR OCTET au chemin legacy. */
    public function test_prose_only_output_is_byte_for_byte_identical_to_legacy(): void
    {
        $prose = trim(str_repeat('Un paragraphe ordinaire sans le moindre tableau. ', 60));

        $obtenu = $this->chunker->chunk($prose);
        $attendu = $this->legacy($prose);

        $this->assertSame($attendu, array_map(
            static fn (array $c): array => ['content' => $c['content'], 'token_count' => $c['token_count']],
            $obtenu,
        ), 'le chemin prose a change de comportement');
    }

    /** REQ 7 — un tableau d'UNE colonne n'a pas de `¶` : il reste de la prose. */
    public function test_a_single_column_table_stays_on_the_prose_path(): void
    {
        // Sans `¶`, rien ne distingue ces lignes d un paragraphe — et c est
        // voulu : TASK-1522 n emet pas de separateur pour une seule colonne.
        $texte = "Alpha\nBeta\nGamma";

        $chunks = $this->contenus($this->chunker->chunk($texte));

        $this->assertCount(1, $chunks);
        $this->assertSame('Alpha Beta Gamma', $chunks[0]);
    }

    /** REQ 8 — un document sans aucun tableau est inchange. */
    public function test_a_document_without_any_table_is_untouched(): void
    {
        $texte = "Premier paragraphe.\n\nSecond paragraphe, plus long, mais toujours de la prose.";

        $this->assertSame($this->legacy($texte), array_map(
            static fn (array $c): array => ['content' => $c['content'], 'token_count' => $c['token_count']],
            $this->chunker->chunk($texte),
        ));
    }

    /** REQ 9 — determinisme : deux passes, memes chunks, memes empreintes. */
    public function test_chunking_is_deterministic(): void
    {
        $texte = "Avant.\n".$this->tableau(8)."\nApres.";

        $this->assertSame($this->chunker->chunk($texte), $this->chunker->chunk($texte));
    }

    /** REQ 10 — deux tableaux distincts ne sont JAMAIS fusionnes. */
    public function test_two_distinct_tables_are_never_merged(): void
    {
        $texte = "Legende du premier\n".$this->tableau(3, 'A')
            ."\nUn paragraphe qui separe les deux tableaux.\n"
            ."Legende du second\n".$this->tableau(3, 'B');

        $fragments = array_values(array_filter($this->contenus($this->chunker->chunk($texte)),
            static fn (string $c): bool => str_contains($c, '¶')));

        foreach ($fragments as $fragment) {
            $melange = str_contains($fragment, 'A1') && str_contains($fragment, 'B1');
            $this->assertFalse($melange, 'deux tableaux distincts ont ete fusionnes dans un fragment');
        }

        // Et chacun garde SA legende, pas celle du voisin.
        $premier = array_values(array_filter($fragments, static fn ($f) => str_contains($f, 'A1')));
        $second = array_values(array_filter($fragments, static fn ($f) => str_contains($f, 'B1')));

        $this->assertStringContainsString('premier', $premier[0]);
        $this->assertStringNotContainsString('second', $premier[0]);
        $this->assertStringContainsString('second', $second[0]);
    }

    /** REQ 11 — une note derivee (sans `¶`) garde le comportement legacy. */
    public function test_a_derived_note_without_any_row_marker_keeps_legacy_behaviour(): void
    {
        // Forme reelle d une note derivee : de la prose, jamais de tableau.
        $note = 'Ce que la Boucle retient : la coordination est assuree par une equipe dediee.';

        $this->assertSame($this->legacy($note), array_map(
            static fn (array $c): array => ['content' => $c['content'], 'token_count' => $c['token_count']],
            $this->chunker->chunk($note),
        ));
    }

    /**
     * REQ 12 — le tableau multi-colonnes reduit a UNE SEULE ligne.
     *
     * C'est precisement le cas que pretendait traiter le bloc mort retire par
     * F1, et aucun test ne le couvrait — ni avant, ni apres. Quand `rows()` ne
     * rend qu'une ligne, il n'y a pas d'en-tete a extraire : cette ligne EST
     * la donnee. Elle doit ressortir entiere, une seule fois, dans un unique
     * fragment.
     *
     * Sabotage : traiter la ligne unique comme un en-tete -> le fragment perd
     * sa donnee, ou la repete, et le test rougit.
     */
    public function test_a_single_row_table_keeps_its_row_as_data(): void
    {
        $fragments = array_values(array_filter(
            $this->contenus($this->chunker->chunk('Rome|2026|termine¶')),
            static fn (string $c): bool => str_contains($c, '¶'),
        ));

        $this->assertCount(1, $fragments, 'une ligne tabulaire doit rendre exactement un fragment');

        foreach (['Rome', '2026', 'termine'] as $cellule) {
            $this->assertStringContainsString($cellule, $fragments[0],
                "la cellule « {$cellule} » de la ligne unique a ete perdue");
        }

        $this->assertSame(1, substr_count($fragments[0], 'Rome'),
            'la ligne unique a ete dupliquee, comme si elle etait aussi un en-tete');
    }

    // ───────────────────────────────────────────────────────── harnais

    /**
     * Un tableau au format de `WordTextExtractor` : en-tete + `$lignes-1`
     * lignes de donnees, chacune terminee par `¶`.
     */
    private function tableau(int $lignes, string $prefixe = ''): string
    {
        $sortie = "Entete{$prefixe}A|Entete{$prefixe}B|Entete{$prefixe}C¶";

        for ($i = 1; $i < $lignes; $i++) {
            $sortie .= "\nL{$i}|{$prefixe}{$i} valeur|{$prefixe}{$i} fin¶";
        }

        return $sortie;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunks
     * @return list<string>
     */
    private function contenus(array $chunks): array
    {
        return array_values(array_map(static fn (array $c): string => (string) $c['content'], $chunks));
    }

    /**
     * @return list<string>
     */
    private function lignesDe(string $contenu): array
    {
        return array_values(array_filter(
            array_map('trim', explode('¶', $contenu)),
            static fn (string $l): bool => $l !== '' && ! str_starts_with($l, 'Entete') && ! str_starts_with($l, 'Table'),
        ));
    }

    /**
     * Le chemin LEGACY reproduit a l'identique : fenetre glissante de mots.
     * Sert de reference pour prouver que la prose n'a pas bouge.
     *
     * @return list<array{content: string, token_count: int}>
     */
    private function legacy(string $text, int $targetSize = 500, int $overlap = 50): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return [];
        }

        preg_match_all('/\S+/u', $text, $matches);
        $words = $matches[0] ?? [];
        $sortie = [];
        $step = $targetSize - $overlap;

        for ($offset = 0; $offset < count($words); $offset += $step) {
            $mots = array_slice($words, $offset, $targetSize);
            $content = trim(implode(' ', $mots));

            if ($content !== '') {
                $sortie[] = ['content' => $content, 'token_count' => count($mots)];
            }
        }

        return $sortie;
    }
}
