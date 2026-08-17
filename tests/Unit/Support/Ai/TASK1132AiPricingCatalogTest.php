<?php

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\AiCost;
use App\Support\Ai\AiPricingCatalog;
use App\Support\Ai\AiUsage;
use Tests\TestCase;

/**
 * TASK-1132 / IA P1-2 — sémantique du catalogue tarifaire.
 *
 * L'invariant sous test est unique et non négociable :
 * `cost_unknown != cost 0`.
 *
 * Un tarif réellement nul vaut légitimement 0 sans `cost_unknown`. Un tarif
 * inconnu ne vaut JAMAIS 0.
 *
 * Le catalogue est entièrement redéfini dans setUp() : les tests ne doivent
 * dépendre ni des tarifs réels livrés, ni du `.env` de la machine (la surcharge
 * OPENAI_*_PRICE_PER_1M est renseignée en local mais absente en CI).
 */
class TASK1132AiPricingCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'paid_provider' => [
                    'paid-model' => ['input_per_1m' => 2.0, 'output_per_1m' => 10.0],
                    // Tarif réellement nul, déclaré explicitement.
                    'free-model' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true],
                    // Coquille : taux nuls SANS le marqueur `free`.
                    'suspicious-model' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0],
                    // Entrées inexploitables.
                    'half-declared-model' => ['input_per_1m' => 1.0],
                    'garbage-model' => ['input_per_1m' => 'gratuit', 'output_per_1m' => 'gratuit'],
                ],
                'local_provider' => [
                    '*' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0, 'free' => true],
                ],
            ],
        ]);
    }

    /**
     * 1 — tarif connu : le coût est calculable.
     */
    public function test_a_known_rate_yields_a_measurable_cost(): void
    {
        $cost = AiPricingCatalog::cost('paid_provider', 'paid-model', AiUsage::of(1000, 500));

        $this->assertFalse($cost->costUnknown, 'A known rate must not be reported as unknown.');
        $this->assertNotNull($cost->costUsd);
        $this->assertTrue($cost->isKnown());
        $this->assertNull($cost->reason);
    }

    /**
     * 5 — le calcul distingue bien le tarif d'entrée du tarif de sortie.
     *
     * 1 000 000 in x 2.0 + 500 000 out x 10.0 = 2.0 + 5.0 = 7.0
     * Un calcul qui confondrait les deux taux, ou n'en appliquerait qu'un,
     * donnerait un autre résultat.
     */
    public function test_input_and_output_rates_are_applied_to_their_own_counter(): void
    {
        $cost = AiPricingCatalog::cost(
            'paid_provider',
            'paid-model',
            AiUsage::of(1_000_000, 500_000),
        );

        $this->assertSame(7.0, $cost->costUsd);

        // Asymétrie vérifiée : inverser les compteurs change le coût.
        $swapped = AiPricingCatalog::cost(
            'paid_provider',
            'paid-model',
            AiUsage::of(500_000, 1_000_000),
        );

        $this->assertSame(11.0, $swapped->costUsd);
        $this->assertNotEquals($cost->costUsd, $swapped->costUsd);
    }

    /**
     * 2 — tarif inconnu : `cost_unknown = true`, et surtout coût NULL.
     */
    public function test_an_unknown_model_is_reported_unknown_and_never_zero(): void
    {
        $cost = AiPricingCatalog::cost('paid_provider', 'model-nobody-declared', AiUsage::of(1000, 1000));

        $this->assertTrue($cost->costUnknown);
        $this->assertNull($cost->costUsd, 'An unknown rate must never fall back to a cost of 0.');
        $this->assertSame(AiPricingCatalog::REASON_MODEL_NOT_IN_CATALOG, $cost->reason);
    }

    /**
     * 4 — la garantie centrale : aucun modèle absent du catalogue ne devient
     * silencieusement gratuit.
     *
     * On teste le VERDICT (`cost_usd === 0.0` interdit), pas seulement le flag :
     * c'est la valeur écrite en base qui trompe un lecteur, pas le drapeau.
     */
    public function test_no_absent_model_ever_becomes_silently_free(): void
    {
        $absent = [
            ['paid_provider', 'never-declared'],
            ['provider-not-in-catalog', 'whatever-model'],
            ['paid_provider', null],
            [null, 'orphan-model'],
            ['', ''],
        ];

        foreach ($absent as [$provider, $model]) {
            $cost = AiPricingCatalog::cost($provider, $model, AiUsage::of(5_000_000, 5_000_000));

            $label = sprintf('[%s / %s]', $provider ?? 'null', $model ?? 'null');

            $this->assertTrue($cost->costUnknown, "{$label} should be unknown.");
            $this->assertNull($cost->costUsd, "{$label} must not be priced at all.");
            $this->assertNotSame(0.0, $cost->costUsd, "{$label} must never be free.");
        }
    }

    /**
     * 3 — un tarif RÉELLEMENT nul autorise zéro, sans `cost_unknown`.
     *
     * C'est le pendant indispensable du test précédent : si tout zéro était
     * suspect, un modèle gratuit deviendrait inexprimable.
     */
    public function test_a_genuinely_free_model_costs_zero_without_being_unknown(): void
    {
        $cost = AiPricingCatalog::cost('paid_provider', 'free-model', AiUsage::of(9_000_000, 9_000_000));

        $this->assertFalse($cost->costUnknown, 'A declared free model is KNOWN, not unknown.');
        $this->assertSame(0.0, $cost->costUsd);
        $this->assertTrue($cost->isKnown());
    }

    /**
     * 3 bis — un provider gratuit par nature (exécution locale) reste gratuit
     * pour tous ses modèles, y compris sans usage rapporté : il n'y a rien à
     * mesurer quand il n'y a rien à facturer.
     */
    public function test_a_free_provider_covers_every_model_even_without_usage(): void
    {
        foreach (['un-modele-quelconque', 'autre-modele', 'llama3.2'] as $model) {
            $cost = AiPricingCatalog::cost('local_provider', $model, AiUsage::notObserved());

            $this->assertFalse($cost->costUnknown, "[{$model}] local execution is free and known.");
            $this->assertSame(0.0, $cost->costUsd);
        }
    }

    /**
     * 4 bis — une entrée à 0 SANS marqueur `free` est traitée comme une coquille.
     *
     * Sans ce garde-fou, une virgule oubliée sur un modèle payant le rendrait
     * gratuit en silence, ce qui est exactement le défaut que P1-2 corrige.
     */
    public function test_a_zero_rate_without_the_free_flag_is_rejected_as_unknown(): void
    {
        $cost = AiPricingCatalog::cost('paid_provider', 'suspicious-model', AiUsage::of(1000, 1000));

        $this->assertTrue($cost->costUnknown);
        $this->assertNull($cost->costUsd);
        $this->assertSame(AiPricingCatalog::REASON_INVALID_CATALOG_ENTRY, $cost->reason);
    }

    /**
     * 4 ter — une entrée incomplète ou non numérique n'est pas un tarif.
     */
    public function test_malformed_entries_are_unknown_rather_than_guessed(): void
    {
        foreach (['half-declared-model', 'garbage-model'] as $model) {
            $cost = AiPricingCatalog::cost('paid_provider', $model, AiUsage::of(1000, 1000));

            $this->assertTrue($cost->costUnknown, "[{$model}] should not be exploitable.");
            $this->assertNull($cost->costUsd, "[{$model}] must not be priced.");
            $this->assertSame(AiPricingCatalog::REASON_INVALID_CATALOG_ENTRY, $cost->reason);
        }
    }

    /**
     * Tarif connu mais usage NON rapporté : il n'y a rien à multiplier.
     * C'est le cas réel de `runScenario()` et du responder de profil.
     */
    public function test_a_known_rate_without_observed_usage_stays_unknown(): void
    {
        $cost = AiPricingCatalog::cost('paid_provider', 'paid-model', AiUsage::notObserved());

        $this->assertTrue($cost->costUnknown);
        $this->assertNull($cost->costUsd);
        $this->assertSame(AiPricingCatalog::REASON_USAGE_NOT_OBSERVED, $cost->reason);
    }

    /**
     * Un usage réellement nul sur un modèle payant coûte 0 et reste CONNU :
     * l'appel a bien été mesuré, il n'a simplement rien consommé.
     */
    public function test_an_observed_zero_usage_on_a_paid_model_is_a_measured_zero(): void
    {
        $cost = AiPricingCatalog::cost('paid_provider', 'paid-model', AiUsage::of(0, 0));

        $this->assertFalse($cost->costUnknown);
        $this->assertSame(0.0, $cost->costUsd);
    }

    /**
     * La surcharge opérateur s'applique, mais ne peut pas déclarer un provider
     * gratuit : seul le catalogue versionné peut affirmer `free`.
     */
    public function test_an_operator_override_prices_uncatalogued_models_but_cannot_make_them_free(): void
    {
        config(['ai_pricing.overrides' => [
            'paid_provider' => ['input_per_1m' => 1.0, 'output_per_1m' => 1.0],
        ]]);

        // TASK-1222 : une entree MODELE explicite prime desormais sur la
        // surcharge generique du provider — sans quoi une surcharge pensee
        // pour la generation refacturerait les embeddings a son tarif.
        $cost = AiPricingCatalog::cost('paid_provider', 'paid-model', AiUsage::of(1_000_000, 0));
        $this->assertSame(2.0, $cost->costUsd, 'An explicit model entry beats the generic provider override.');

        // La surcharge reste l'ultime recours des modeles HORS catalogue.
        $uncatalogued = AiPricingCatalog::cost('paid_provider', 'some-other-model', AiUsage::of(1_000_000, 0));
        $this->assertSame(1.0, $uncatalogued->costUsd, 'The override still prices uncatalogued models.');

        config(['ai_pricing.overrides' => [
            'paid_provider' => ['input_per_1m' => 0.0, 'output_per_1m' => 0.0],
        ]]);

        $free = AiPricingCatalog::cost('paid_provider', 'paid-model', AiUsage::of(1_000_000, 0));
        $this->assertSame(2.0, $free->costUsd, 'A 0/0 override is inert, it does not make a model free.');

        config(['ai_pricing.overrides' => [
            'paid_provider' => ['input_per_1m' => 1.0],
        ]]);

        $partial = AiPricingCatalog::cost('paid_provider', 'paid-model', AiUsage::of(1_000_000, 0));
        $this->assertSame(2.0, $partial->costUsd, 'A half declared override is not a rate.');
    }

    /**
     * `AiUsage` ne fabrique pas de zéro : c'est la lecture de `usage` qui
     * décidait auparavant du coût, et elle lisait les mauvaises clés.
     */
    public function test_usage_extraction_distinguishes_absent_from_zero(): void
    {
        // Convention réelle de `chat/completions`, celle que le code ne lisait pas.
        $observed = AiUsage::fromChatCompletions([
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 34],
        ]);
        $this->assertTrue($observed->isObserved());
        $this->assertSame(120, $observed->inputTokens);
        $this->assertSame(34, $observed->outputTokens);

        // Convention alternative, tolérée.
        $alternate = AiUsage::fromChatCompletions([
            'usage' => ['input_tokens' => 7, 'output_tokens' => 9],
        ]);
        $this->assertSame(7, $alternate->inputTokens);
        $this->assertSame(9, $alternate->outputTokens);

        // Aucun bloc `usage` : NON observé, et surtout pas 0.
        foreach ([[], ['usage' => null], ['usage' => []], ['usage' => 'nope'], null] as $body) {
            $missing = AiUsage::fromChatCompletions($body);

            $this->assertFalse($missing->isObserved());
            $this->assertNull($missing->inputTokens);
            $this->assertNull($missing->outputTokens);
        }

        // Un zéro réellement rapporté reste un zéro observé.
        $zero = AiUsage::fromChatCompletions(['usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0]]);
        $this->assertTrue($zero->isObserved());
        $this->assertSame(0, $zero->inputTokens);
    }

    /**
     * Le verdict expose toujours les deux colonnes de trace, jamais l'une sans
     * l'autre : c'est ce couple qui rend le zéro interprétable en base.
     */
    public function test_trace_attributes_always_carry_both_columns(): void
    {
        $known = AiCost::known(0.25)->traceAttributes();
        $this->assertSame(['cost_usd' => 0.25, 'cost_unknown' => false], $known);

        $unknown = AiCost::unknown('whatever')->traceAttributes();
        $this->assertSame(['cost_usd' => null, 'cost_unknown' => true], $unknown);
    }

    /**
     * Le catalogue est versionné : un coût mesuré doit être rattachable au
     * relevé de tarifs qui l'a produit.
     */
    public function test_the_catalog_exposes_its_version(): void
    {
        $this->assertSame('test-catalog', AiPricingCatalog::version());

        config(['ai_pricing.version' => null]);
        $this->assertSame('unknown', AiPricingCatalog::version());
    }

    /**
     * Le catalogue RÉELLEMENT LIVRÉ est exploitable.
     *
     * Les autres tests injectent un catalogue de test pour être déterministes ;
     * celui-ci vérifie `config/ai_pricing.php` tel qu'il est versionné. C'est
     * lui qui tombe si le fichier de catalogue est supprimé ou vidé.
     */
    public function test_the_shipped_catalog_is_present_and_exploitable(): void
    {
        // On recharge la configuration réelle du dépôt, en neutralisant la
        // surcharge d'environnement (renseignée en local, absente en CI).
        config(['ai_pricing' => require config_path('ai_pricing.php')]);
        config(['ai_pricing.overrides' => []]);

        $this->assertNotSame('unknown', AiPricingCatalog::version(), 'The shipped catalog must be versioned.');

        $models = config('ai_pricing.models');
        $this->assertIsArray($models);
        $this->assertNotEmpty($models, 'A removed or emptied catalog makes every cost unmeasurable.');

        // Chaque entrée livrée doit être valide : aucune coquille ne doit
        // survivre au commit.
        foreach ($models as $provider => $entries) {
            foreach (array_keys($entries) as $model) {
                $this->assertNotNull(
                    AiPricingCatalog::rateFor($provider, $model),
                    "Shipped catalog entry [{$provider} / {$model}] must be exploitable."
                );
            }
        }

        // Les deux ancrages concrets du catalogue livré.
        $this->assertFalse(
            AiPricingCatalog::cost('ollama', 'ministral-3:3b', AiUsage::notObserved())->costUnknown,
            'Local execution must be declared free.'
        );

        $openAi = AiPricingCatalog::cost('openai', 'gpt-4o-mini', AiUsage::of(1_000_000, 0));
        $this->assertFalse($openAi->costUnknown, 'The default OpenAI model must have a known rate.');
        $this->assertGreaterThan(0, $openAi->costUsd, 'A paid model must not be priced at zero.');
    }
}
