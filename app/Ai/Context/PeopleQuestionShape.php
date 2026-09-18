<?php

namespace App\Ai\Context;

/**
 * TASK-1546 — forme d'une question qui porte sur des PERSONNES.
 *
 * « Qui pourrait les aider ? »  → {@see isPeople()}
 * « Et moi ? »                  → {@see isSelf()}
 *
 * Indice LOCAL et DETERMINISTE, quatrieme de la famille ouverte par
 * `DocumentaryQuestionShape` et continuee par `TemporalQuestionShape` puis
 * `ReferenceQuestionShape`. Aucun appel de modele pour router.
 *
 * ## Ce que cette classe NE decide pas
 *
 * Elle ne dit pas de QUEL projet on parle, ni QUI pourrait aider. Elle ne
 * reconnait aucun nom, aucune competence, aucune personne. Elle rend une
 * seule chose : « cette phrase demande-t-elle des personnes, et pour qui ? ».
 *
 * L'univers des personnes est une donnee du serveur — c'est la doctrine de
 * `EligiblePeopleService`, et rien ici ne l'entame.
 *
 * ## Pourquoi SELF est teste AVANT PEOPLE
 *
 * « Et moi, je pourrais aider ? » porte les deux formes. La personne
 * interroge sa PROPRE place : repondre par une liste d'autres membres
 * serait repondre a cote. L'appelant tranche donc `isSelf()` en premier, et
 * ce test le protege.
 *
 * ## Pourquoi l'appariement est borne aux MOTS
 *
 * `str_contains()` nu confond « et moins de budget » avec « et moi ». La
 * normalisation reduit la phrase a des mots separes par une seule espace ;
 * l'appariement ajoute alors une espace de chaque cote, ce qui donne des
 * frontieres de mot sans expression reguliere.
 */
final class PeopleQuestionShape
{
    /**
     * La question demande QUI pourrait aider — d'autres que soi.
     *
     * @var list<string>
     */
    private const MARQUEURS_PEOPLE = [
        // FR — la demande de personnes, quelle que soit la cible
        'qui pourrait les aider', 'qui peut les aider', 'qui pourrait leur',
        'qui pourrait nous aider', 'qui peut nous aider',
        'qui pourrait m aider', 'qui peut m aider',
        'qui pourrait aider', 'qui peut aider', 'qui saurait aider',
        'qui pourrait contribuer', 'qui peut contribuer',
        'qui connait', 'qui s y connait', 'qui saurait faire',
        'quelles personnes', 'quels membres', 'qui dans la boucle',
        // EN
        'who could help', 'who can help', 'who might help', 'who would help',
        'who knows about', 'who could contribute', 'who can contribute',
        'which members', 'which people',
    ];

    /**
     * La question porte sur MOI — la personne authentifiee, jamais une autre.
     *
     * @var list<string>
     */
    private const MARQUEURS_SELF = [
        // FR
        'et moi', 'et moi la dedans', 'et moi dans tout ca',
        'je peux aider', 'je pourrais aider', 'puis je aider',
        'je peux contribuer', 'je pourrais contribuer',
        'moi je peux', 'moi je pourrais', 'et de mon cote',
        'est ce que je peux aider', 'est ce que je pourrais aider',
        'qu est ce que je peux apporter', 'ce que je peux apporter',
        // EN
        'what about me', 'and me', 'can i help', 'could i help',
        'how can i help', 'how could i help', 'am i a good fit',
        'what can i contribute', 'can i contribute',
    ];

    /** La phrase demande-t-elle des personnes autres que soi ? */
    public static function isPeople(?string $question): bool
    {
        return self::porte((string) $question, self::MARQUEURS_PEOPLE);
    }

    /** La phrase porte-t-elle sur la personne qui la pose ? */
    public static function isSelf(?string $question): bool
    {
        return self::porte((string) $question, self::MARQUEURS_SELF);
    }

    /**
     * @param  list<string>  $marqueurs
     */
    private static function porte(string $question, array $marqueurs): bool
    {
        $q = self::normalize($question);

        if ($q === '') {
            return false;
        }

        // Frontieres de mot : « et moins » ne porte pas « et moi ».
        $borne = ' '.$q.' ';

        foreach ($marqueurs as $marqueur) {
            if (str_contains($borne, ' '.$marqueur.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minuscules, accents retires. Meme table que les trois autres indices :
     * aucune dependance a `intl`, et un caractere hors francais/anglais
     * devient une espace — ce qui decoupe aussi « m'aider » en « m aider ».
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
