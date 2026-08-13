<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Lecture du catalogue tarifaire IA (TASK-1132 / IA P1-2).
 *
 * Source unique : `config/ai_pricing.php`, configuration versionnee. Aucune
 * table metier, aucun quatrieme registre, aucun appel reseau.
 *
 * Garantie principale : ce lecteur ne renvoie JAMAIS 0 silencieusement. Un
 * couple provider + modele absent, mal declare, ou dont l'usage n'a pas ete
 * observe, produit un `AiCost::unknown()` explicite. Seule une entree declaree
 * `'free' => true` autorise un cout de 0.
 *
 * Ce n'est ni un garde economique, ni une reservation de credits, ni un
 * CapabilityRegistry : uniquement `usage observe x tarif courant connu`.
 */
final class AiPricingCatalog
{
    /**
     * Le couple provider + modele n'est pas au catalogue.
     */
    public const REASON_MODEL_NOT_IN_CATALOG = 'model_not_in_catalog';

    /**
     * Entree presente mais inexploitable (taux manquant, non numerique, ou
     * taux nul non declare `free`).
     */
    public const REASON_INVALID_CATALOG_ENTRY = 'invalid_catalog_entry';

    /**
     * Tarif connu, mais le provider n'a rapporte aucun usage : sans tokens, il
     * n'y a rien a multiplier.
     */
    public const REASON_USAGE_NOT_OBSERVED = 'usage_not_observed';

    /**
     * Appel sans provider identifiable : on ne peut meme pas chercher un tarif.
     */
    public const REASON_PROVIDER_UNIDENTIFIED = 'provider_unidentified';

    /**
     * Clef declarant un tarif valable pour tous les modeles d'un provider.
     */
    private const WILDCARD = '*';

    private function __construct() {}

    /**
     * Date du releve des tarifs actifs.
     */
    public static function version(): string
    {
        $version = config('ai_pricing.version');

        return is_string($version) && $version !== '' ? $version : 'unknown';
    }

    /**
     * Vrai si un tarif exploitable existe pour ce couple.
     */
    public static function hasRate(?string $provider, ?string $model): bool
    {
        return self::rateFor($provider, $model) !== null;
    }

    /**
     * Taux exploitable pour ce couple, ou null si le tarif est inconnu.
     *
     * @return array{input_per_1m: float, output_per_1m: float, free: bool}|null
     */
    public static function rateFor(?string $provider, ?string $model): ?array
    {
        return self::resolve($provider, $model)['rate'];
    }

    /**
     * Résout le tarif ET la raison d'un échec, afin qu'un diagnostic distingue
     * « personne n'a déclaré ce modèle » de « l'entrée est inexploitable ».
     * Les deux mènent au même verdict prudent, mais pas au même correctif.
     *
     * @return array{rate: array{input_per_1m: float, output_per_1m: float, free: bool}|null, reason: ?string}
     */
    private static function resolve(?string $provider, ?string $model): array
    {
        $providerKey = self::normalizeKey($provider);

        if ($providerKey === null) {
            return ['rate' => null, 'reason' => self::REASON_PROVIDER_UNIDENTIFIED];
        }

        $override = self::overrideFor($providerKey);

        if ($override !== null) {
            return ['rate' => $override, 'reason' => null];
        }

        $entry = self::entryFor($providerKey, self::normalizeKey($model));

        if ($entry === null) {
            return ['rate' => null, 'reason' => self::REASON_MODEL_NOT_IN_CATALOG];
        }

        $rate = self::validate($entry, $providerKey, $model);

        if ($rate === null) {
            return ['rate' => null, 'reason' => self::REASON_INVALID_CATALOG_ENTRY];
        }

        return ['rate' => $rate, 'reason' => null];
    }

    /**
     * Verdict economique d'un appel : `usage observe x tarif courant connu`.
     *
     * Un tarif reellement nul court-circuite l'usage : une execution locale ou
     * une reponse sans LLM coute 0 meme sans compteur de tokens.
     */
    public static function cost(?string $provider, ?string $model, AiUsage $usage): AiCost
    {
        ['rate' => $rate, 'reason' => $reason] = self::resolve($provider, $model);

        if ($rate === null) {
            return AiCost::unknown($reason ?? self::REASON_MODEL_NOT_IN_CATALOG);
        }

        if ($rate['free']) {
            return AiCost::known(0.0);
        }

        if (! $usage->isObserved()) {
            return AiCost::unknown(self::REASON_USAGE_NOT_OBSERVED);
        }

        $cost = ($usage->inputTokensOrZero() / 1_000_000) * $rate['input_per_1m']
            + ($usage->outputTokensOrZero() / 1_000_000) * $rate['output_per_1m'];

        return AiCost::known(round($cost, 8));
    }

    /**
     * Surcharge operateur au niveau provider. Une surcharge partielle est
     * ignoree : un tarif a moitie declare n'est pas un tarif connu.
     *
     * @return array{input_per_1m: float, output_per_1m: float, free: bool}|null
     */
    private static function overrideFor(string $providerKey): ?array
    {
        $override = config('ai_pricing.overrides.'.$providerKey);

        if (! is_array($override)) {
            return null;
        }

        $input = $override['input_per_1m'] ?? null;
        $output = $override['output_per_1m'] ?? null;

        if (! is_numeric($input) || ! is_numeric($output)) {
            return null;
        }

        $input = (float) $input;
        $output = (float) $output;

        // Une surcharge a 0/0 ne peut pas declarer un provider gratuit : seul
        // le catalogue versionne peut affirmer `free`.
        if ($input <= 0.0 && $output <= 0.0) {
            return null;
        }

        return ['input_per_1m' => $input, 'output_per_1m' => $output, 'free' => false];
    }

    /**
     * Entree brute du catalogue : correspondance exacte du modele, sinon
     * wildcard provider.
     */
    private static function entryFor(string $providerKey, ?string $modelKey): mixed
    {
        $models = config('ai_pricing.models.'.$providerKey);

        if (! is_array($models)) {
            return null;
        }

        if ($modelKey !== null && array_key_exists($modelKey, $models)) {
            return $models[$modelKey];
        }

        return $models[self::WILDCARD] ?? null;
    }

    /**
     * Valide une entree du catalogue.
     *
     * Un taux nul exige le marqueur `'free' => true`. Sans lui, l'entree est
     * consideree comme une coquille et le tarif reste inconnu : c'est la
     * garantie qu'aucun modele payant ne devient silencieusement gratuit.
     *
     * @return array{input_per_1m: float, output_per_1m: float, free: bool}|null
     */
    private static function validate(mixed $entry, string $providerKey, ?string $model): ?array
    {
        if (! is_array($entry)) {
            return self::rejectEntry($providerKey, $model, 'entry is not an array');
        }

        $input = $entry['input_per_1m'] ?? null;
        $output = $entry['output_per_1m'] ?? null;

        if (! is_numeric($input) || ! is_numeric($output)) {
            return self::rejectEntry($providerKey, $model, 'missing or non numeric rate');
        }

        $input = (float) $input;
        $output = (float) $output;

        if ($input < 0.0 || $output < 0.0) {
            return self::rejectEntry($providerKey, $model, 'negative rate');
        }

        $free = ($entry['free'] ?? false) === true;

        if (! $free && $input === 0.0 && $output === 0.0) {
            return self::rejectEntry($providerKey, $model, 'zero rate without explicit free flag');
        }

        if ($free && ($input > 0.0 || $output > 0.0)) {
            return self::rejectEntry($providerKey, $model, 'free flag with non zero rate');
        }

        return ['input_per_1m' => $input, 'output_per_1m' => $output, 'free' => $free];
    }

    private static function rejectEntry(string $providerKey, ?string $model, string $why): null
    {
        Log::warning('AiPricingCatalog: invalid pricing entry, cost stays unknown.', [
            'provider' => $providerKey,
            'model' => $model,
            'reason' => $why,
            'catalog_version' => self::version(),
        ]);

        return null;
    }

    private static function normalizeKey(?string $value): ?string
    {
        $key = strtolower(trim((string) $value));

        return $key === '' ? null : $key;
    }
}
