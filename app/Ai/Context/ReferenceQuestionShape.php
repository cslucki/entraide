<?php

namespace App\Ai\Context;

/**
 * TASK-1544 — forme d'une REFERENCE INDIRECTE a un projet.
 *
 * « Le projet dont Roger parlait mardi, ça avance ? »
 *
 * Indice LOCAL et DETERMINISTE, comme `DocumentaryQuestionShape` et
 * `TemporalQuestionShape`. Aucun appel de modele pour router, aucune
 * reconnaissance d'entites : la seule chose que cette classe decide, c'est
 * « cette phrase designe-t-elle un projet SANS le nommer, en s'appuyant sur
 * quelqu'un ? ».
 *
 * ## Pourquoi elle ne reconnait PAS les noms propres
 *
 * Elle ne cherche pas « Roger ». Elle rend les mots candidats, et c'est le
 * SERVEUR qui les confronte aux membres reels de l'Organization. Reconnaitre
 * un nom propre par heuristique — majuscule, position, dictionnaire — serait
 * le premier etage d'un Entity Resolver general, que le mandat interdit, et
 * cela inventerait des personnes qui n'existent pas.
 *
 * L'univers des personnes est une donnee du serveur, pas une deduction.
 *
 * ## Pourquoi la reference doit etre INDIRECTE
 *
 * « Comment avance ARIA ? » nomme son sujet : la recherche documentaire y
 * repond deja, et l'intercepter degraderait une question qui marche. Cette
 * forme ne se declenche donc que sur une designation qui ne dit PAS de quoi
 * elle parle — « le projet de », « celui dont », « la boucle dont ».
 */
final class ReferenceQuestionShape
{
    /**
     * Marqueurs de designation INDIRECTE, normalises.
     *
     * Chacun se termine sur une preposition d'attribution : c'est elle qui
     * dit que le sujet va etre designe PAR QUELQU'UN plutot que nomme.
     *
     * @var list<string>
     */
    private const MARQUEURS = [
        // FR — le projet / la boucle / le dossier, attribue a quelqu'un
        'le projet de', 'le projet du', 'le projet dont', 'le projet que',
        'la boucle de', 'la boucle du', 'la boucle dont', 'la boucle que',
        'le truc de', 'le truc dont', 'le chantier de', 'le chantier dont',
        'le dossier de', 'le dossier dont',
        // FR — la designation pure
        'celui dont', 'celle dont', 'ce dont', 'celui que', 'celle que',
        'dont parlait', 'dont parle', 'qu evoquait', 'qu evoque',
        // EN
        'the project of', 'the project that', 'the loop of', 'the loop that',
        'the one that', 'the thing that', 'was talking about', 'mentioned',
    ];

    /** Mots trop courants pour etre des candidats de nom. */
    private const VIDES = [
        'le', 'la', 'les', 'du', 'de', 'des', 'un', 'une', 'dont', 'que', 'qui',
        'projet', 'boucle', 'dossier', 'chantier', 'truc', 'parlait', 'parle',
        'evoquait', 'evoque', 'ca', 'avance', 'ou', 'en', 'est', 'il', 'elle',
        'on', 'nous', 'vous', 'ils', 'elles', 'mardi', 'lundi', 'mercredi',
        'jeudi', 'vendredi', 'samedi', 'dimanche', 'hier', 'semaine', 'derniere',
        'depuis', 'the', 'project', 'loop', 'was', 'talking', 'about',
        'mentioned', 'that', 'one', 'thing', 'how', 'is', 'going', 'and',
    ];

    /** La phrase designe-t-elle un projet sans le nommer ? */
    public static function isIndirect(?string $question): bool
    {
        $q = self::normalize((string) $question);

        if ($q === '') {
            return false;
        }

        foreach (self::MARQUEURS as $marqueur) {
            if (str_contains($q, $marqueur)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les mots que le SERVEUR ira confronter aux membres reels.
     *
     * Ce ne sont pas des noms : ce sont des candidats. Rendre ici une liste
     * large et laisser le serveur trancher vaut mieux que deviner — un mot qui
     * ne designe personne ne trouvera personne, et c'est sans consequence.
     *
     * @return list<string>
     */
    public static function candidatsDeNom(?string $question): array
    {
        $q = self::normalize((string) $question);

        if ($q === '') {
            return [];
        }

        $mots = array_values(array_filter(
            explode(' ', $q),
            static fn (string $mot): bool => mb_strlen($mot) >= 3
                && ! in_array($mot, self::VIDES, true)
                && ! ctype_digit($mot),
        ));

        return array_values(array_unique($mots));
    }

    /**
     * Minuscules, accents retires. Meme table que les deux autres indices :
     * aucune dependance a `intl`, et un caractere hors francais/anglais
     * devient un espace.
     */
    private static function normalize(string $question): string
    {
        $lower = mb_strtolower(trim($question));

        $lower = strtr($lower, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'õ' => 'o', 'ò' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n',
            'œ' => 'oe', 'æ' => 'ae',
        ]);

        $lower = (string) preg_replace('/[^a-z0-9]+/u', ' ', $lower);

        return trim((string) preg_replace('/\s+/', ' ', $lower));
    }
}
