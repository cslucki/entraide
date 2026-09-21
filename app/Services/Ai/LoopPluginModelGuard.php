<?php

namespace App\Services\Ai;

use App\Services\Loops\LoopAiAssistants;

/**
 * La garde FERMEE PAR DEFAUT qui precede toute generation d'assistant.
 * (TASK-1617)
 *
 * SLICE C ne genere rien. Cette garde est donc livree **calculable et
 * testee**, pour que SLICE D n'ait qu'a l'appeler — et ne puisse pas la
 * reinventer plus permissive.
 *
 * ## Sa place dans la sequence, et pourquoi elle est PREMIERE
 *
 *     LoopPluginModelGuard        <- ici : modele configure + preuve FREE fraiche
 *         v
 *     ProviderResolver            <- credential TENANT (invariant inchange)
 *         v
 *     provider
 *         v
 *     AiPricingCatalog            <- chiffrage, sur preuve PERSISTEE
 *         v
 *     ledger
 *
 * **Cette garde est INDEPENDANTE de ce que `AiPricingCatalog` sait.** Un slug
 * peut avoir une entree statique dans `config/ai_pricing.php` — parce qu'il a
 * ete tarife un jour — sans etre gratuit aujourd'hui. Le catalogue de prix
 * repond « combien ça coute » ; cette garde repond « avons-nous le droit
 * d'appeler ». Confondre les deux laisserait un tarif historique autoriser
 * une generation que personne n'a verifiee.
 *
 * ## Ce qu'elle a le droit de faire, et que le chiffrage n'a pas
 *
 * Rafraichir. Elle est sur le chemin de la GENERATION, avant l'appel : un
 * releve OpenRouter y est legitime, et son echec se traduit par un refus, pas
 * par une trace perdue. Le chiffrage, lui, ne sort jamais sur le reseau
 * (voir `LoopPluginAiModels::entryFor()`).
 *
 * ## Aucun repli
 *
 * Le verdict negatif est `MODEL_UNAVAILABLE_OR_NOT_FREE`. Il n'est JAMAIS
 * remplace par un autre modele gratuit, par `openrouter/free`, par le modele
 * de l'Organization, ni par un modele payant. Un assistant sans modele
 * eligible ne genere pas.
 */
class LoopPluginModelGuard
{
    public const REASON_UNAVAILABLE = LoopPluginAiModels::REASON_UNAVAILABLE;

    public const REASON_NOT_CONFIGURED = 'MODEL_NOT_CONFIGURED';

    public const REASON_UNKNOWN_ASSISTANT = 'UNKNOWN_ASSISTANT';

    public function __construct(
        private LoopPluginAiModels $models,
        private OpenRouterModelCatalog $catalogue,
        private LoopAiAssistants $assistants,
    ) {}

    /**
     * Le slug REELLEMENT utilisable pour cet assistant, ou `null`.
     *
     * Quatre conditions cumulatives, et l'ordre est la logique :
     *  1. l'assistant existe au catalogue du plugin ;
     *  2. un modele lui est explicitement configure ;
     *  3. sa preuve de gratuite est fraiche — sinon on tente UN releve, et
     *     son echec est un refus, pas une tolerance ;
     *  4. le slug est ENCORE au catalogue des modeles gratuits — c'est la
     *     seule facon de voir un modele disparu ou redevenu payant pendant
     *     la fenetre de fraicheur.
     */
    public function eligibleSlug(string $assistantKey, ?string &$reason = null): ?string
    {
        $reason = null;

        if (! $this->assistants->exists($assistantKey)) {
            $reason = self::REASON_UNKNOWN_ASSISTANT;

            return null;
        }

        $ligne = $this->models->lineFor($assistantKey);

        if ($ligne === null || ! is_string($ligne->model_slug) || $ligne->model_slug === '') {
            $reason = self::REASON_NOT_CONFIGURED;

            return null;
        }

        $slug = (string) $ligne->model_slug;

        // Preuve perimee : UN releve, force. S'il echoue — reseau, cle,
        // catalogue malforme — `verifiedFreeModels()` rend un tableau vide et
        // la verification echoue. Fail closed, sans cas particulier.
        if (! $this->models->proofIsFresh($ligne)) {
            if (! $this->catalogue->isSlugVerifiedFree($slug, forceRefresh: true)) {
                $reason = self::REASON_UNAVAILABLE;

                return null;
            }

            $this->models->renewProof($ligne);

            return $slug;
        }

        // Preuve fraiche : on confronte quand meme au catalogue en cache.
        if (! $this->catalogue->isSlugVerifiedFree($slug)) {
            $reason = self::REASON_UNAVAILABLE;

            return null;
        }

        return $slug;
    }

    public function isEligible(string $assistantKey): bool
    {
        return $this->eligibleSlug($assistantKey) !== null;
    }
}
