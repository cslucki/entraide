<?php

namespace Tests\Unit\Support\Ai;

use App\Support\Ai\AiProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1131 / IA P1-1 — `process` est un identifiant technique STABLE.
 *
 * Couvre le critère E : `process` ne doit jamais être un texte traduit ni un
 * libellé d'interface, et ne doit jamais varier avec la locale.
 */
class TASK1131AiProcessTest extends TestCase
{
    /**
     * Table de correspondance `ai_interactions.feature` → `process`.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function featureMappingProvider(): array
    {
        return [
            'blog generate' => ['blog_generate', 'blog.article_generate'],
            'blog correct' => ['blog_correct', 'blog.article_correct'],
            'blog explorer dialogue' => ['blog_explorer', 'blog.explorer_dialogue'],
            'blog explorer note' => ['blog_explorer_note', 'blog.explorer_note'],
            'chatloop answer' => ['chatloop_ai_answer', 'chatloop.answer'],
            'chatloop ask' => ['chatloop_ai_ask', 'chatloop.ask'],
            'chatloop summarize' => ['chatloop_ai_summarize', 'chatloop.summarize'],
        ];
    }

    /**
     * Table de correspondance `admin_ai_interactions.scenario_id` → `process`.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function scenarioMappingProvider(): array
    {
        return [
            'supervision' => ['supervision_content', 'supervision.content'],
            'clarify help request' => ['clarify_help_request', 'help_request.clarify'],
            'service offer master' => ['service_offer_master', 'service_offer.master'],
            'bounded member' => ['bounded_member_presentation', 'member_profile.bounded_presentation'],
            'inline member' => ['inline_member_presentation', 'member_profile.inline_presentation'],
            'admin llm test' => ['member_ai_profile_llm_test', 'member_profile.admin_llm_test'],
            'profile agent setup' => ['profile_agent_setup', 'member_profile.agent_setup'],
            'profile agent visitor chat' => ['profile_agent_visitor_chat', 'member_profile.agent_visitor_chat'],
            'profile agent master' => ['profile_agent_master', 'member_profile.agent_master'],
        ];
    }

    #[DataProvider('featureMappingProvider')]
    public function test_feature_maps_to_the_documented_process(string $feature, string $expected): void
    {
        $this->assertSame($expected, AiProcess::fromFeature($feature));
    }

    #[DataProvider('scenarioMappingProvider')]
    public function test_scenario_id_maps_to_the_documented_process(string $scenarioId, string $expected): void
    {
        $this->assertSame($expected, AiProcess::fromScenarioId($scenarioId));
    }

    /**
     * Critère E — le scénario blog porte la locale dans son identifiant.
     * Le `process` ne doit pas en dépendre.
     */
    public function test_locale_suffixed_features_collapse_to_one_stable_process(): void
    {
        $fr = AiProcess::fromFeature('blog_method_selection_narrative_fr');
        $en = AiProcess::fromFeature('blog_method_selection_narrative_en');

        $this->assertSame('blog.method_selection', $fr);
        $this->assertSame($fr, $en);
    }

    /**
     * Critère E — la méthode éditoriale choisie ne crée pas un process par
     * méthode : c'est le même traitement.
     */
    public function test_every_blog_method_shares_the_same_process(): void
    {
        $processes = [];

        foreach (['narrative', 'reformulate', 'shorten', 'expand'] as $method) {
            foreach (['fr', 'en'] as $locale) {
                $processes[] = AiProcess::fromFeature("blog_method_selection_{$method}_{$locale}");
            }
        }

        $this->assertSame(['blog.method_selection'], array_values(array_unique($processes)));
    }

    /**
     * Critère E — `process` reste identique quelle que soit la locale
     * applicative courante. Un libellé traduit échouerait ici.
     */
    public function test_process_does_not_change_with_the_application_locale(): void
    {
        $collect = function (): array {
            $values = [];

            foreach (self::featureMappingProvider() as [$feature]) {
                $values['feature:'.$feature] = AiProcess::fromFeature($feature);
            }

            foreach (self::scenarioMappingProvider() as [$scenarioId]) {
                $values['scenario:'.$scenarioId] = AiProcess::fromScenarioId($scenarioId);
            }

            $values['feature:blog_method_selection'] = AiProcess::fromFeature('blog_method_selection_narrative_fr');

            return $values;
        };

        app()->setLocale('fr');
        $inFrench = $collect();

        app()->setLocale('en');
        $inEnglish = $collect();

        $this->assertSame($inFrench, $inEnglish);
    }

    /**
     * Critère E — forme technique : minuscules ASCII, pas d'espace, pas
     * d'accent, pas de ponctuation de phrase. Un texte traduit
     * (« Génération d'article ») ne passerait pas.
     */
    public function test_every_process_value_is_a_technical_token(): void
    {
        $values = [AiProcess::MEMBER_PROFILE_INLINE_PRESENTATION, AiProcess::MEMBER_PROFILE_LOOP_AGENT_REPLY];

        foreach (self::featureMappingProvider() as [$feature]) {
            $values[] = AiProcess::fromFeature($feature);
        }

        foreach (self::scenarioMappingProvider() as [$scenarioId]) {
            $values[] = AiProcess::fromScenarioId($scenarioId);
        }

        foreach ($values as $value) {
            $this->assertMatchesRegularExpression('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $value);
            $this->assertLessThanOrEqual(100, strlen($value));
        }
    }

    public function test_unknown_or_empty_input_falls_back_to_unknown(): void
    {
        $this->assertSame(AiProcess::UNKNOWN, AiProcess::fromFeature(null));
        $this->assertSame(AiProcess::UNKNOWN, AiProcess::fromFeature(''));
        $this->assertSame(AiProcess::UNKNOWN, AiProcess::fromFeature('   '));
        $this->assertSame(AiProcess::UNKNOWN, AiProcess::fromScenarioId('unknown'));
    }

    /**
     * Un identifiant non cartographié reste stable et technique : on ne
     * fabrique pas une taxonomie, on normalise (locale retirée, casse et
     * caractères parasites neutralisés).
     */
    public function test_unmapped_identifier_is_normalized_and_locale_free(): void
    {
        $this->assertSame(
            'some_future_scenario',
            AiProcess::fromScenarioId('Some Future Scenario')
        );

        $this->assertSame(
            AiProcess::fromFeature('some_future_feature_fr'),
            AiProcess::fromFeature('some_future_feature_en')
        );
    }
}
