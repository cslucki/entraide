<?php

namespace App\Support\Ai;

/**
 * Usage OBSERVE d'un appel IA (TASK-1132 / IA P1-2).
 *
 * Distingue « 0 token » de « usage non rapporte ». Sans cette distinction, une
 * reponse provider depourvue de bloc `usage` produit mecaniquement un cout de 0
 * qui se lit ensuite comme une mesure certaine.
 *
 * Defaut corrige au passage : les trois points de calcul lisaient
 * `usage.input_tokens` / `usage.output_tokens`, absents de l'API
 * `chat/completions` qui expose `prompt_tokens` / `completion_tokens`. L'usage
 * n'etait donc jamais observe et le cout valait toujours 0.
 */
final class AiUsage
{
    private function __construct(
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
    ) {}

    public static function of(?int $inputTokens, ?int $outputTokens): self
    {
        return new self(
            $inputTokens === null ? null : max(0, $inputTokens),
            $outputTokens === null ? null : max(0, $outputTokens),
        );
    }

    /**
     * Aucun usage rapporte par le provider.
     */
    public static function notObserved(): self
    {
        return new self(null, null);
    }

    /**
     * Usage d'une reponse `chat/completions` (OpenAI, OpenRouter et compatibles).
     *
     * Accepte les deux conventions de nommage rencontrees, mais ne fabrique
     * jamais de zero : un bloc `usage` absent ou depourvu des deux compteurs
     * reste non observe.
     */
    public static function fromChatCompletions(mixed $body): self
    {
        $usage = is_array($body) ? ($body['usage'] ?? null) : null;

        if (! is_array($usage)) {
            return self::notObserved();
        }

        $input = self::readCounter($usage, ['prompt_tokens', 'input_tokens']);
        $output = self::readCounter($usage, ['completion_tokens', 'output_tokens']);

        if ($input === null && $output === null) {
            return self::notObserved();
        }

        return self::of($input, $output);
    }

    /**
     * Usage rapporte par l'API texte du Laravel AI SDK (TASK-1207).
     *
     * `Laravel\Ai\Responses\Data\Usage` type ses compteurs `int` avec un
     * defaut a 0 : ses passerelles ecrivent `$usage['prompt_tokens'] ?? 0`, si
     * bien qu'un bloc `usage` absent devient indiscernable d'un vrai zero DANS
     * l'objet du SDK.
     *
     * On retablit la distinction au seul endroit ou elle est encore decidable :
     * une generation de texte reelle ne consomme jamais 0 token d'entree ET 0
     * token de sortie. Ce couple signe donc un usage non rapporte, et doit
     * rester UNKNOWN plutot que de produire un cout de 0 (invariant P1-2).
     */
    public static function fromSdkTextTokens(int $promptTokens, int $completionTokens): self
    {
        if ($promptTokens <= 0 && $completionTokens <= 0) {
            return self::notObserved();
        }

        return self::of($promptTokens, $completionTokens);
    }

    /**
     * Usage d'une reponse Ollama `api/generate` : seul `eval_count` (tokens
     * produits) est rapporte, l'usage d'entree n'existe pas dans la reponse.
     */
    public static function fromOllamaGenerate(mixed $body): self
    {
        $evalCount = is_array($body) ? ($body['eval_count'] ?? null) : null;

        return self::of(null, is_numeric($evalCount) ? (int) $evalCount : null);
    }

    /**
     * Vrai si au moins un compteur a ete rapporte par le provider.
     */
    public function isObserved(): bool
    {
        return $this->inputTokens !== null || $this->outputTokens !== null;
    }

    /**
     * Compteur d'entree pour une colonne `input_tokens` NOT NULL.
     *
     * Les colonnes token de `ai_interactions` et `admin_ai_interactions` sont
     * NOT NULL DEFAULT 0 depuis leur creation ; les rendre nullable depasse le
     * perimetre de P1-2. La verite sur la mesurabilite est portee par
     * `cost_unknown`, pas par ce repli.
     */
    public function inputTokensOrZero(): int
    {
        return $this->inputTokens ?? 0;
    }

    public function outputTokensOrZero(): int
    {
        return $this->outputTokens ?? 0;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private static function readCounter(array $usage, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $usage[$key] ?? null;

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
