<?php

namespace App\Ai\Context;

/**
 * Forme d'une question documentaire (TASK-1309) — indice LOCAL et
 * DETERMINISTE, jamais un appel LLM de routage.
 *
 * Repond a UNE question, et une seule : « cette question porte-t-elle sur le
 * CORPUS DANS SON ENSEMBLE (de quoi parlent les documents ? resume-les) plutot
 * que sur un point precis (que dit tel document sur X ?) ? ».
 *
 * ## Pourquoi cet indice existe
 *
 * Une question panoramique n'a, par construction, aucun excellent voisin
 * vectoriel : le plus proche chunk de « Que contiennent les dossiers ? » n'est
 * pas le corpus, c'est un paragraphe qui parle vaguement de documents. Le
 * filtre `max_distance` ecarte alors TOUT, et `dossier.retrieval` rend zero
 * extrait alors meme que le corpus est riche (constat reel TASK-1309 :
 * organisation test20260822, Boucle 01-COMMUNICATION, 26 chunks indexes sur 5
 * documents, ZERO [Sn] rendu).
 *
 * ## Ce que cet indice n'est pas
 *
 * Ce n'est PAS l'autorite qui decide si une reponse est possible : le filet
 * STRUCTUREL — et prioritaire — reste « la selection semantique est vide »
 * (`DossierRetrievalSource`), qui ne depend d'aucun mot et couvre a lui seul
 * le cas reproduit. Cet indice ne sert qu'au second cas : la recherche a
 * trouve QUELQUE CHOSE, mais la question demandait de la LARGEUR (plusieurs
 * documents) la ou le classement par distance donne de la PROFONDEUR (les
 * meilleurs chunks d'un ou deux documents).
 *
 * ## Pourquoi il ne peut qu'ELARGIR
 *
 * Un faux positif ajoute des extraits courts d'autres documents : le contexte
 * est plus large, jamais tronque de ce qu'il portait deja, et AUCUN appel
 * provider supplementaire n'en decoule (les extraits representatifs sont une
 * lecture SQL, sans embedding). Un faux negatif, lui, laisse une question
 * panoramique sans vue d'ensemble. Le cout de l'erreur est donc asymetrique :
 * l'indice est volontairement genereux — c'est le « biais vers le chemin
 * complet en cas de doute ».
 *
 * ## Pourquoi des marqueurs de LARGEUR, et non de corpus
 *
 * Reconnaitre le corpus (« dossier », « document », « fichier ») ne suffit
 * pas : « Que dit precisement 02-ManifesteV2.md sur les Boucles ? » nomme un
 * document sans rien demander de panoramique, et l'elargir DILUERAIT une
 * question precise (ce que TASK-1309 interdit explicitement). Ce qui distingue
 * A/C de B, c'est l'INTENTION DE LARGEUR : contenir, parler de, resumer, vue
 * d'ensemble, principaux sujets. Les marqueurs sont donc des intentions, dans
 * les DEUX langues du produit — jamais une liste de noms de fichiers, jamais
 * une Boucle, jamais un tenant.
 */
final class DocumentaryQuestionShape
{
    /**
     * Marqueurs d'INTENTION DE LARGEUR, normalises (minuscules, sans
     * accents). Compares par simple inclusion : « resume » attrape
     * « resume », « resumez », « resumer », « resume-moi ».
     *
     * @var list<string>
     */
    private const BREADTH_MARKERS = [
        // FR — ce que le corpus contient / de quoi il parle
        'que contien', 'qu il y a dans', 'qu est ce qu il y a', 'qu y a t il',
        'de quoi parle', 'parlent', 'traitent', 'traite de quoi',
        // FR — synthese / vue d'ensemble
        'resume', 'resumer', 'synthese', 'synthetise', 'syntheses',
        'vue d ensemble', 'vue globale', 'apercu', 'panorama',
        'tour d horizon', 'grandes lignes', 'en gros', 'essentiel',
        // FR — sujets / themes du corpus
        'principaux sujets', 'principaux themes', 'sujets', 'themes',
        'thematiques', 'quoi de neuf',
        // EN — same three families
        'overview', 'summary', 'summarize', 'summarise', 'sum up',
        'what is in', 'what s in', 'whats in', 'what do they contain',
        'what does it contain', 'what are they about', 'what is it about',
        'topics', 'themes', 'gist', 'high level', 'big picture',
        'main subjects', 'key subjects',
    ];

    /**
     * `true` quand la question demande une vue d'ensemble du corpus.
     *
     * Volontairement conservateur sur UN point : une question qui ne porte
     * aucun marqueur de largeur n'est jamais elargie ici — c'est ce qui
     * protege la question precise (« Que dit precisement DOCUMENT.md sur
     * X ? ») de la dilution. Le cas ou une question panoramique n'emploie
     * aucun de ces mots reste couvert par le filet structurel de
     * `DossierRetrievalSource` (selection semantique vide).
     */
    public static function wantsCorpusOverview(?string $question): bool
    {
        $normalized = self::normalize((string) $question);

        if ($normalized === '') {
            return false;
        }

        foreach (self::BREADTH_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minuscules, accents retires, ponctuation et apostrophes reduites a des
     * espaces simples. Aucune dependance a `iconv`/`intl` : la table couvre
     * exactement les caracteres accentues du francais, et tout autre
     * caractere non alphanumerique devient un espace — un mot japonais ou
     * arabe ne matchera simplement aucun marqueur, ce qui est le comportement
     * voulu (pas d'elargissement, filet structurel toujours actif).
     */
    private static function normalize(string $question): string
    {
        $lowered = mb_strtolower(trim($question));

        $lowered = strtr($lowered, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
            'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
        ]);

        $spaced = preg_replace('/[^a-z0-9]+/u', ' ', $lowered) ?? '';

        return trim(preg_replace('/\s+/', ' ', $spaced) ?? '');
    }
}
