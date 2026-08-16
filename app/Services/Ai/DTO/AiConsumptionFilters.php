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
     * Une date illisible ne provoque pas d'erreur et ne se devine pas : elle est
     * ignoree, et la periode retombe sur le mois courant. Une console
     * d'observabilite ne doit pas se casser sur un parametre d'URL malforme.
     */
    public static function fromRequest(Request $request): self
    {
        $default = self::currentMonth();

        $from = self::parseDate($request->query('from')) ?? $default->from;
        $to = self::parseDate($request->query('to'));

        // `to` est exclusif : l'utilisateur saisit un jour de fin qu'il attend
        // INCLUS, on borne donc au lendemain a minuit.
        $to = $to !== null ? $to->addDay() : $default->to;

        // Un intervalle inverse ne rend rien de sense : on repart du mois courant.
        if ($to <= $from) {
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

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
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
