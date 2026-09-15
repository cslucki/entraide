<?php

namespace App\Services\Dossiers;

use InvalidArgumentException;

/**
 * Le decoupage d'un texte en chunks indexables.
 *
 * ## TASK-1564 — pourquoi ce fichier a deux chemins
 *
 * Jusqu'ici il n'en avait qu'un : une fenetre glissante de mots, aveugle a la
 * structure. Sur de la prose, c'est le bon outil. Sur un TABLEAU, il produit
 * des chunks qui ne veulent rien dire.
 *
 * Mesure sur le Dossier ARIA, avant cette TASK : **20 chunks contiennent du
 * tableau, et les 20 le collent a autre chose.** Un exemple reel, releve en
 * base :
 *
 *     debut du chunk : « |WP7: 2.5|WP8: 0.25|WP9: … »
 *                        -> COMMENCE AU MILIEU D'UNE LIGNE
 *     fin du chunk   : « …Prof Hurley leads a research programme… »
 *                        -> LA PROSE D'UNE AUTRE SECTION
 *
 * Un vecteur calcule sur ce melange ne represente ni le tableau ni le
 * paragraphe. Consequence mesuree : la ligne « Enrica De Cian, Team Lead de
 * UNIVE » vivait dans un chunk de 4361 caracteres couvrant 21 lignes, et
 * n'entrait JAMAIS dans le bassin de candidats (distance 0.6724 quand il faut
 * 0.5341). Decoupee en blocs de 3 lignes : 0.5034.
 *
 * ## Le marqueur existait deja
 *
 * TASK-1522 avait choisi `¶` pour terminer une ligne de tableau PRECISEMENT
 * parce qu'il survit a l'ecrasement des blancs — un `\n` n'y survit pas. Il
 * n'est emis que pour les tableaux d'au moins deux colonnes, jamais par la
 * prose. C'est donc un marqueur fiable et exclusif, et il est deja teste
 * (`test_a_row_boundary_survives_the_chunker`,
 * `test_prose_without_a_table_gains_no_separator`).
 *
 * Rien n'a ete invente ici : on lit un signal que le depot posait deja.
 *
 * ## Ce que ce chemin ne fait PAS
 *
 * Aucune prose synthetique, aucun resume, aucune connaissance ajoutee. La
 * legende et l'en-tete recopies en tete d'un fragment sont ceux du DOCUMENT ;
 * s'ils n'existent pas, le fragment n'en porte pas.
 */
class ArticleChunker
{
    /**
     * Fin d'une ligne de tableau. Pose par `WordTextExtractor` (TASK-1522),
     * seul emetteur du depot.
     */
    private const ROW_END = '¶';

    /**
     * Lignes de donnees par fragment tabulaire.
     *
     * TROIS, et c'est mesure. Distance a une question precise, sur la table
     * des participants d'ARIA (seuil d'entree au bassin : 0.5341) :
     *
     *     21 lignes (l'existant)  0.6724   hors bassin
     *      5 lignes               0.5226   entre, marge 0,011
     *      3 lignes               0.5034   entre, marge 0,031
     *      1 ligne                0.4786   entre, marge 0,055
     *
     * Cinq n'entre qu'avec un centieme de marge — trop fragile. Une ligne par
     * fragment serait le plus precis, mais multiplierait l'index par cinq sur
     * les tableaux. Trois est le compromis retenu.
     */
    private const TABLE_ROWS_PER_FRAGMENT = 3;

    /**
     * Budget de caracteres de la legende recopiee en tete d'un fragment.
     *
     * Assez pour porter un titre de section et le nom du tableau (mesure sur
     * ARIA : il en faut ~185), pas assez pour avaler le paragraphe precedent
     * — ce qui rendrait au fragment la dilution qu'on vient de lui retirer.
     */
    private const PRE_TABLE_CONTEXT_MAX_CHARS = 220;

    /**
     * MVP approximation: one token is estimated as one Unicode word.
     *
     * @return array<int, array{chunk_index: int, content: string, content_hash: string, token_count: int}>
     */
    public function chunk(string $text, int $targetSize = 500, int $overlap = 50): array
    {
        $this->validateWindow($targetSize, $overlap);

        $chunks = [];
        $index = 0;

        // La segmentation se fait AVANT toute normalisation globale : c'est la
        // seule etape qui voit encore les frontieres de ligne.
        foreach ($this->segments($text) as $segment) {
            $morceaux = $segment['table']
                ? $this->tableFragments($segment['text'], $segment['caption'])
                : $this->slidingWindow($segment['text'], $targetSize, $overlap);

            foreach ($morceaux as $content) {
                $chunks[] = [
                    'chunk_index' => $index,
                    'content' => $content,
                    'content_hash' => hash('sha256', $content),
                    'token_count' => count($this->words($content)),
                ];
                $index++;
            }
        }

        return $chunks;
    }

    /**
     * Le texte, coupe en passages PROSE et TABLEAU qui alternent.
     *
     * Un passage TABLEAU est une suite maximale de lignes terminees par `¶`.
     * Une ligne de prose glissee entre deux lignes de tableau ferme donc le
     * passage — deux tableaux separes par un paragraphe ne fusionnent jamais.
     *
     * `caption` porte la DERNIERE ligne de prose qui precede immediatement le
     * tableau, quand elle existe. Elle reste par ailleurs dans le passage de
     * prose : on la RECOPIE comme contexte, on ne la deplace pas.
     *
     * @return list<array{table: bool, text: string, caption: ?string}>
     */
    private function segments(string $text): array
    {
        $lignes = preg_split('/\R/u', $text) ?: [];
        $segments = [];
        $courant = [];
        $courantTable = false;

        foreach ($lignes as $ligne) {
            $estTable = str_contains($ligne, self::ROW_END);

            if ($courant !== [] && $estTable !== $courantTable) {
                $segments[] = ['table' => $courantTable, 'text' => implode("\n", $courant)];
                $courant = [];
            }

            $courant[] = $ligne;
            $courantTable = $estTable;
        }

        if ($courant !== []) {
            $segments[] = ['table' => $courantTable, 'text' => implode("\n", $courant)];
        }

        // Seconde passe : donner a chaque passage tabulaire la legende de son
        // voisin de gauche. Faite APRES, pour que la prose reste intacte.
        $resultat = [];

        foreach ($segments as $i => $segment) {
            $legende = null;

            if ($segment['table'] && isset($segments[$i - 1]) && ! $segments[$i - 1]['table']) {
                $legende = $this->lastLine($segments[$i - 1]['text']);
            }

            $resultat[] = $segment + ['caption' => $legende];
        }

        return $resultat;
    }

    /**
     * La legende d'un tableau : les DERNIERES lignes de prose qui le
     * precedent, dans la limite de `PRE_TABLE_CONTEXT_MAX_CHARS`.
     *
     * Pourquoi plusieurs lignes et pas une seule — mesure sur le vrai document
     * ARIA. La prose qui precede la table des participants tient sur quatre
     * lignes :
     *
     *     [-4] ARtistic Intelligence Alliance:
     *     [-3] multiplying creative interactions to enhance soft skills…
     *     [-2] (vide)
     *     [-1] List of participants
     *
     * Une premiere version ne reprenait que « List of participants ». Le
     * fragment obtenu mesurait 0.6735 contre la question « quel est le role
     * d'Enrica De Cian dans le projet ARIA ? » — toujours HORS du bassin
     * (seuil 0.5341), soit un gain de 0,001 sur l'existant : le decoupage
     * etait bon, le fragment restait introuvable. Avec le titre de section,
     * **0.5037** : il entre.
     *
     * Ce n'est pas une astuce : un tableau nomme « List of participants » ne
     * dit pas DE QUOI il est la liste. Son titre de section, lui, le dit. On
     * recopie du texte du DOCUMENT, jamais un resume.
     *
     * La borne evite l'exces inverse — avaler un paragraphe entier ramenerait
     * la dilution qu'on vient de supprimer.
     */
    private function lastLine(string $text): ?string
    {
        $lignes = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $text) ?: []),
            static fn (string $l): bool => $l !== '',
        ));

        if ($lignes === []) {
            return null;
        }

        $retenues = [];
        $budget = self::PRE_TABLE_CONTEXT_MAX_CHARS;

        foreach (array_reverse($lignes) as $ligne) {
            $cout = mb_strlen($ligne) + 1;

            if ($retenues !== [] && $cout > $budget) {
                break;
            }

            array_unshift($retenues, $ligne);
            $budget -= $cout;
        }

        return implode(' ', $retenues);
    }

    /**
     * Un passage tabulaire, en fragments de `TABLE_ROWS_PER_FRAGMENT` lignes.
     *
     * Chaque fragment porte la legende (si elle existe) et la ligne d'en-tete
     * (si le tableau en a une), pour se suffire a lui-meme ou que tombe la
     * coupe. Aucun recouvrement : les lignes d'un tableau ne se repetent pas
     * d'un fragment a l'autre, seul le contexte se repete.
     *
     * @return list<string>
     */
    private function tableFragments(string $text, ?string $caption): array
    {
        $lignes = $this->rows($text);

        if ($lignes === []) {
            return [];
        }

        // L'en-tete est la premiere ligne, si le tableau a de quoi en avoir
        // une : au moins deux lignes, sinon « l'en-tete » EST la donnee.
        $entete = count($lignes) >= 2 ? array_shift($lignes) : null;

        if ($lignes === []) {
            // Tableau d'une seule ligne : elle est la donnee, pas un en-tete.
            $lignes = [$entete];
            $entete = null;
        }

        $fragments = [];

        foreach (array_chunk($lignes, self::TABLE_ROWS_PER_FRAGMENT) as $bloc) {
            $parties = array_filter([$caption, $entete]);
            $parties = array_merge($parties, $bloc);

            $contenu = trim(preg_replace('/\s+/u', ' ', implode(' ', $parties)) ?? '');

            if ($contenu !== '') {
                $fragments[] = $contenu;
            }
        }

        return $fragments;
    }

    /**
     * Les lignes d'un passage tabulaire, `¶` conserve.
     *
     * Le pilcrow reste COLLE a sa ligne : c'est lui qui rend la frontiere
     * lisible en aval (TASK-1522 le teste), et le retirer casserait
     * `explode('¶', ...)` chez les lecteurs.
     *
     * @return list<string>
     */
    private function rows(string $text): array
    {
        $morceaux = explode(self::ROW_END, $text);
        $lignes = [];

        foreach ($morceaux as $i => $morceau) {
            $morceau = trim(preg_replace('/\s+/u', ' ', $morceau) ?? '');

            if ($morceau === '') {
                continue;
            }

            // Le dernier morceau n'a pas de `¶` derriere lui s'il ne finissait
            // pas la ligne ; on ne lui en invente pas.
            $lignes[] = $i < count($morceaux) - 1 ? $morceau.self::ROW_END : $morceau;
        }

        return $lignes;
    }

    /**
     * Le chemin LEGACY, inchange depuis l'origine : fenetre glissante de mots.
     *
     * Il reste la seule voie pour la prose, et son comportement est fige par
     * les 13 tests de `ArticleChunkerTest` — dont aucun n'utilise `¶`.
     *
     * @return list<string>
     */
    private function slidingWindow(string $text, int $targetSize, int $overlap): array
    {
        $words = $this->words($text);

        if ($words === []) {
            return [];
        }

        $contenus = [];
        $step = $targetSize - $overlap;
        $wordCount = count($words);

        for ($offset = 0; $offset < $wordCount; $offset += $step) {
            $chunkWords = array_slice($words, $offset, $targetSize);
            $content = trim(implode(' ', $chunkWords));

            if ($content === '') {
                continue;
            }

            $contenus[] = $content;
        }

        return $contenus;
    }

    private function validateWindow(int $targetSize, int $overlap): void
    {
        if ($targetSize <= 0) {
            throw new InvalidArgumentException('Chunk target size must be strictly positive.');
        }

        if ($overlap < 0) {
            throw new InvalidArgumentException('Chunk overlap must be zero or positive.');
        }

        if ($overlap >= $targetSize) {
            throw new InvalidArgumentException('Chunk overlap must be lower than target size.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function words(string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return [];
        }

        preg_match_all('/\S+/u', $text, $matches);

        return $matches[0] ?? [];
    }
}
