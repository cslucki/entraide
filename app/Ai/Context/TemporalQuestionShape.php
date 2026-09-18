<?php

namespace App\Ai\Context;

use Illuminate\Support\Carbon;

/**
 * TASK-1543 — forme d'une question de CHANGEMENT, et son ancrage.
 *
 * Indice LOCAL et DETERMINISTE, comme `DocumentaryQuestionShape` : aucun appel
 * de modele pour router, aucune inference. Il repond a deux questions
 * separees, et les garder separees est ce qui rend la suite honnete :
 *
 *  1. **cette question porte-t-elle sur ce qui a CHANGE ?** « Qu'est-ce qui a
 *     change », « quoi de neuf », « des nouvelles », « what changed » ;
 *  2. **depuis QUAND ?** « depuis mardi », « depuis hier », « la semaine
 *     derniere », « depuis trois jours ».
 *
 * ## Pourquoi l'ancrage peut manquer, et pourquoi on n'en invente pas
 *
 * Le mandat l'interdit explicitement : ne jamais simuler « depuis mardi » avec
 * un timestamp choisi par le code. Une question de changement sans reperage
 * temporel n'a donc PAS d'ancre par defaut — elle porte sur toute l'histoire
 * connue de la Boucle, ce qui est sa lecture la plus large et la seule qui ne
 * fabrique aucune date.
 *
 * Se rabattre sur « les sept derniers jours » serait repondre a une question
 * que personne n'a posee, et le lecteur n'aurait aucun moyen de le savoir.
 *
 * ## Ce que « mardi » veut dire
 *
 * L'occurrence la plus recente de ce jour de semaine, a minuit. Si l'on EST
 * mardi, c'est aujourd'hui a minuit — jamais mardi dernier : quelqu'un qui
 * demande « depuis mardi » un mardi parle de la journee en cours.
 */
final class TemporalQuestionShape
{
    /**
     * Marqueurs d'intention de CHANGEMENT, normalises.
     *
     * Volontairement etroits. Un faux positif ajoute un bloc d'histoire a une
     * question qui n'en demandait pas — du bruit dans le contexte, et un
     * budget mange pour rien. Les marqueurs decrivent donc un changement
     * d'ETAT, jamais une simple nouveaute de contenu.
     *
     * @var list<string>
     */
    private const CHANGE_MARKERS = [
        // FR
        'qu est ce qui a change', 'qu est ce qui change', 'ce qui a change',
        'qu a t il change', 'quoi de change', 'a change depuis',
        'quoi de neuf', 'quoi de nouveau', 'du nouveau', 'des nouvelles',
        'qu est ce qui a bouge', 'ce qui a bouge', 'a bouge depuis',
        'mis a jour depuis', 'mises a jour depuis', 'evolue depuis',
        'ou en est on depuis', 'qu est ce qui est nouveau',
        // EN
        'what changed', 'what has changed', 'what s changed', 'whats changed',
        'what is new', 'what s new', 'whats new', 'any updates',
        'what was updated', 'what has been updated', 'any news since',
    ];

    /**
     * Les jours de la semaine, normalises, dans l'ordre ISO (lundi = 1).
     *
     * @var array<int, list<string>>
     */
    private const WEEKDAYS = [
        1 => ['lundi', 'monday'],
        2 => ['mardi', 'tuesday'],
        3 => ['mercredi', 'wednesday'],
        4 => ['jeudi', 'thursday'],
        5 => ['vendredi', 'friday'],
        6 => ['samedi', 'saturday'],
        7 => ['dimanche', 'sunday'],
    ];

    /** Une question qui demande ce qui a change. */
    public static function wantsChangeReport(?string $question): bool
    {
        $normalized = self::normalize((string) $question);

        if ($normalized === '') {
            return false;
        }

        foreach (self::CHANGE_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * L'instant depuis lequel la question demande le changement, ou `null`.
     *
     * `null` n'est PAS une erreur et ne declenche aucun repli sur une fenetre
     * arbitraire : il signifie « la question ne dit pas depuis quand », et
     * l'appelant doit alors couvrir toute l'histoire connue.
     */
    public static function anchor(?string $question, ?Carbon $maintenant = null): ?Carbon
    {
        $q = self::normalize((string) $question);

        if ($q === '') {
            return null;
        }

        $now = ($maintenant ?? Carbon::now())->copy();

        // « depuis trois jours », « il y a 2 semaines », « depuis 6 mois ».
        $relative = self::relatif($q, $now);

        if ($relative !== null) {
            return $relative;
        }

        // Reperes nommes, du plus precis au plus large. L'ordre compte :
        // « avant hier » contient « hier ».
        foreach ([
            ['avant hier', fn (): Carbon => $now->copy()->subDays(2)->startOfDay()],
            ['hier', fn (): Carbon => $now->copy()->subDay()->startOfDay()],
            ['yesterday', fn (): Carbon => $now->copy()->subDay()->startOfDay()],
            ['aujourd hui', fn (): Carbon => $now->copy()->startOfDay()],
            ['today', fn (): Carbon => $now->copy()->startOfDay()],
            ['la semaine derniere', fn (): Carbon => $now->copy()->subWeek()->startOfWeek()],
            ['last week', fn (): Carbon => $now->copy()->subWeek()->startOfWeek()],
            ['cette semaine', fn (): Carbon => $now->copy()->startOfWeek()],
            ['this week', fn (): Carbon => $now->copy()->startOfWeek()],
            ['le mois dernier', fn (): Carbon => $now->copy()->subMonth()->startOfMonth()],
            ['last month', fn (): Carbon => $now->copy()->subMonth()->startOfMonth()],
            ['ce mois ci', fn (): Carbon => $now->copy()->startOfMonth()],
            ['this month', fn (): Carbon => $now->copy()->startOfMonth()],
        ] as [$marqueur, $resoudre]) {
            if (str_contains($q, $marqueur)) {
                return $resoudre();
            }
        }

        return self::jourDeSemaine($q, $now);
    }

    /** « depuis trois jours », « since 2 weeks », « il y a 6 mois ». */
    private static function relatif(string $q, Carbon $now): ?Carbon
    {
        $chiffres = ['un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4,
            'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10,
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
            'six ' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];

        if (! preg_match('/(\d+|[a-z]+)\s+(jours?|semaines?|mois|days?|weeks?|months?)\b/', $q, $m)) {
            return null;
        }

        $n = ctype_digit($m[1]) ? (int) $m[1] : ($chiffres[$m[1]] ?? null);

        if ($n === null || $n < 1 || $n > 366) {
            return null;
        }

        return match (true) {
            str_starts_with($m[2], 'jour'), str_starts_with($m[2], 'day') => $now->copy()->subDays($n)->startOfDay(),
            str_starts_with($m[2], 'semaine'), str_starts_with($m[2], 'week') => $now->copy()->subWeeks($n)->startOfDay(),
            default => $now->copy()->subMonths($n)->startOfDay(),
        };
    }

    /** « depuis mardi » — l'occurrence la plus recente, minuit. */
    private static function jourDeSemaine(string $q, Carbon $now): ?Carbon
    {
        foreach (self::WEEKDAYS as $iso => $noms) {
            foreach ($noms as $nom) {
                if (! str_contains($q, $nom)) {
                    continue;
                }

                $cible = $now->copy()->startOfDay();
                // Zero quand on EST ce jour-la : « depuis mardi », un mardi,
                // parle de la journee en cours, pas de la semaine precedente.
                $recul = ($now->dayOfWeekIso - $iso + 7) % 7;

                return $cible->subDays($recul);
            }
        }

        return null;
    }

    /**
     * Minuscules, accents retires, tout le reste reduit a des espaces simples.
     *
     * Meme table que `DocumentaryQuestionShape` : aucune dependance a `intl`,
     * et un caractere hors francais/anglais devient un espace — il ne matchera
     * simplement aucun marqueur.
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
