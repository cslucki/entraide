<?php

namespace App\Services\Ai\DTO;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Filtres de la console « Consommation IA » (TASK-1219).
 *
 * La fenetre temporelle est TOUJOURS un intervalle semi-ouvert `[from, to[`,
 * comme `AiEconomicGuard::authorize()` : `>= debut de mois` et `< mois suivant`.
 * Reprendre exactement la meme convention evite qu'une trace de la derniere
 * seconde du mois soit comptee par la console et pas par la garde, ou l'inverse.
 *
 * Les filtres de dimension (`user`, `process`, `model`, `provider`) valent
 * `null` quand ils ne sont pas poses. `null` signifie « toutes les valeurs »,
 * jamais « valeur inconnue » : filtrer SUR l'inconnu est un besoin different,
 * hors V1.
 */
final class AiConsumptionFilters
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $userId = null,
        public readonly ?string $process = null,
        public readonly ?string $model = null,
        public readonly ?string $provider = null,
    ) {}

    /**
     * Mois courant, aucune dimension filtree. C'est la vue par defaut, et c'est
     * la fenetre sur laquelle le budget mensuel est reellement applique.
     */
    public static function currentMonth(): self
    {
        $start = CarbonImmutable::now()->startOfMonth();

        return new self($start, $start->addMonth());
    }

    /**
     * Construit les filtres depuis la requete HTTP.
     *
     * CONTRAT DES BORNES, tenu par le code et par les tests :
     *
     *   - aucune borne fournie                 -> mois courant ;
     *   - une borne fournie mais illisible     -> mois courant pour LES DEUX ;
     *   - bornes inversees                     -> mois courant ;
     *   - deux dates `YYYY-MM-DD` valides      -> conservees, `to` INCLUSIF
     *                                             cote utilisateur, converti en
     *                                             intervalle technique
     *                                             `[from, to + 1 jour[`.
     *
     * Une borne illisible invalide TOUTE la periode, pas seulement elle-meme :
     * garder l'autre borne composerait une fenetre que l'utilisateur n'a jamais
     * demandee, et les chiffres rendus porteraient son nom sans etre les siens.
     *
     * Le parsing est STRICT (cf. `parseStrictDate`) : `tomorrow` ou
     * `next monday` sont des expressions que Carbon accepterait volontiers, et
     * qui feraient dependre la periode affichee du jour de lecture.
     */
    public static function fromRequest(Request $request): self
    {
        $default = self::currentMonth();

        $rawFrom = self::cleanString($request->query('from'));
        $rawTo = self::cleanString($request->query('to'));

        $from = $rawFrom === null ? $default->from : self::parseStrictDate($rawFrom);

        // `to` est exclusif en interne : l'utilisateur saisit un jour de fin
        // qu'il attend INCLUS, on borne donc au lendemain a minuit.
        $to = $rawTo === null ? $default->to : self::parseStrictDate($rawTo)?->addDay();

        // Borne illisible, ou intervalle inverse : on repart du mois courant,
        // sans deviner ce que l'utilisateur voulait dire.
        if ($from === null || $to === null || $to <= $from) {
            $from = $default->from;
            $to = $default->to;
        }

        return new self(
            $from,
            $to,
            self::cleanString($request->query('user_id')),
            self::cleanString($request->query('process')),
            self::cleanString($request->query('model')),
            self::cleanString($request->query('provider')),
        );
    }

    /**
     * Etat des filtres pour la vue et pour les liens de pagination/tri.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            // Retour a la borne INCLUSIVE cote interface : l'utilisateur relit
            // la date qu'il a saisie, pas la borne technique du lendemain.
            'to' => $this->to->subDay()->toDateString(),
            'user_id' => $this->userId,
            'process' => $this->process,
            'model' => $this->model,
            'provider' => $this->provider,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * Parse STRICTEMENT le format des champs `<input type="date">` : `YYYY-MM-DD`.
     *
     * `CarbonImmutable::parse()` etait trop accueillant. Il accepte `tomorrow`,
     * `next monday`, `+3 days` : la periode affichee aurait alors dependu du
     * jour de lecture, et deux personnes ouvrant la meme URL auraient vu des
     * chiffres differents sans qu'aucune ne puisse le savoir.
     *
     * Deux verrous, parce que le format seul ne suffit pas :
     *   1. la FORME doit etre exactement `\d{4}-\d{2}-\d{2}` ;
     *   2. la date doit exister vraiment — `createFromFormat` reporte
     *      silencieusement les debordements (`2026-02-31` deviendrait le
     *      3 mars). On exige donc que la date relue rende la chaine d'origine.
     */
    private static function parseStrictDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            // `!` remet l'heure a 00:00:00 : sans lui, l'heure courante
            // s'invite dans la borne et la fenetre glisse au fil de la journee.
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }

    private static function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
