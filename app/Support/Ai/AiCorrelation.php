<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Corrélation d'UNE opération BouclePro (TASK-1131 / IA P1-1).
 *
 * `correlation_id` identifie une OPÉRATION métier, pas un appel LLM.
 * Une opération peut produire plusieurs appels IA et plusieurs écritures de
 * trace : toutes partagent la même corrélation.
 *
 *   correlation_id BouclePro
 *       ├── appel provider A   (futur invocationId SDK A — P1-3)
 *       ├── appel provider B   (futur invocationId SDK B — P1-3)
 *       └── écritures de trace (ai_interactions, admin_ai_interactions, ...)
 *
 * Règles :
 * - une nouvelle opération utilisateur (requête HTTP, commande, job d'origine)
 *   → une nouvelle corrélation ;
 * - un job asynchrone hérite de la corrélation créée au DISPATCH, il ne la
 *   recrée jamais à l'exécution (cf. App\Jobs\GenerateAiAgentResponse) ;
 * - la corrélation sert à TRACER, jamais à AUTORISER : elle ne remplace ni ne
 *   contourne aucun tenant scope (Organization = Tenant).
 *
 * Le support est `Illuminate\Support\Facades\Context` : scoped par requête et
 * par job (le worker réinitialise le scope entre deux jobs), déshydraté dans le
 * payload de queue et réhydraté sur `JobProcessing`.
 */
final class AiCorrelation
{
    /**
     * Clé de contexte partagée (également visible dans les logs).
     */
    public const CONTEXT_KEY = 'ai_correlation_id';

    private function __construct() {}

    /**
     * Corrélation courante, sans en créer une.
     */
    public static function peek(): ?string
    {
        $value = Context::get(self::CONTEXT_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Corrélation courante, créée à la volée si l'opération n'en a pas encore.
     */
    public static function id(): string
    {
        return self::peek() ?? self::start();
    }

    /**
     * Démarre explicitement une nouvelle opération.
     */
    public static function start(): string
    {
        $id = (string) Str::uuid();

        Context::add(self::CONTEXT_KEY, $id);

        return $id;
    }

    /**
     * Adopte une corrélation existante (propagation asynchrone).
     *
     * Une valeur invalide n'est jamais écrite en base : on repart alors sur une
     * nouvelle corrélation en le signalant, plutôt que de casser l'exécution.
     */
    public static function bind(?string $id): string
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            Log::warning('AiCorrelation: invalid correlation id, starting a new one.', [
                'received' => $id,
            ]);

            return self::start();
        }

        Context::add(self::CONTEXT_KEY, $id);

        return $id;
    }

    /**
     * Oublie la corrélation courante (fin d'opération, tests).
     */
    public static function forget(): void
    {
        Context::forget(self::CONTEXT_KEY);
    }
}
