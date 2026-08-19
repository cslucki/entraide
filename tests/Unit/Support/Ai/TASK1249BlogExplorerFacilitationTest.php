<?php

namespace Tests\Unit\Support\Ai;

use App\Services\BlogAiService;
use App\Support\Ai\BlogExplorerFacilitation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TASK-1249 — contenu par defaut des quatre methodes de facilitation de
 * Roger (fallback code du repository `AdminAiPrompt`) : identifiants
 * canoniques partages, scenarios nommes, definitions et regles jamais vides,
 * distinctes par methode, disponibles en FR et EN.
 */
#[Group('ai')]
class TASK1249BlogExplorerFacilitationTest extends TestCase
{
    public function test_methods_are_the_canonical_selection_identifiers_and_nothing_else(): void
    {
        $this->assertSame(BlogAiService::METHOD_SELECTION_METHODS, BlogExplorerFacilitation::methods());
        $this->assertEqualsCanonicalizing(['explorer', 'slow_down', 'clarifier', 'invent'], BlogExplorerFacilitation::methods());

        $this->assertTrue(BlogExplorerFacilitation::isValid('slow_down'));
        $this->assertFalse(BlogExplorerFacilitation::isValid('clarify'), 'Pas de second nom pour la meme notion.');
        $this->assertFalse(BlogExplorerFacilitation::isValid('ralentir'));
        $this->assertFalse(BlogExplorerFacilitation::isValid(null));
        $this->assertFalse(BlogExplorerFacilitation::isValid(''));
    }

    public function test_scenario_ids_follow_the_admin_prompt_repository_convention(): void
    {
        $this->assertSame('blog_explorer_method_explorer_fr', BlogExplorerFacilitation::scenarioId('explorer', 'fr'));
        $this->assertSame('blog_explorer_method_slow_down_en', BlogExplorerFacilitation::scenarioId('slow_down', 'en'));
        $this->assertSame(['fr', 'en'], BlogExplorerFacilitation::LOCALES);
    }

    public function test_every_method_has_a_non_empty_distinct_default_in_both_locales(): void
    {
        foreach (BlogExplorerFacilitation::LOCALES as $locale) {
            $prompts = [];

            foreach (BlogExplorerFacilitation::methods() as $method) {
                $prompt = BlogExplorerFacilitation::defaultPrompt($method, $locale);
                $this->assertNotSame('', trim($prompt), "Fallback {$method}/{$locale} jamais vide.");
                $this->assertGreaterThan(400, mb_strlen($prompt), "Une definition, pas un slogan ({$method}/{$locale}).");
                $this->assertLessThan(2_500, mb_strlen($prompt), "Une definition COURTE, pas la reference complete ({$method}/{$locale}).");
                $prompts[$method] = $prompt;
            }

            $this->assertCount(4, array_unique($prompts), "Quatre definitions distinctes en {$locale}.");
        }
    }

    public function test_the_french_defaults_carry_the_four_required_postures(): void
    {
        $explorer = BlogExplorerFacilitation::defaultPrompt('explorer', 'fr');
        foreach (['angle', 'faits', 'ressentis', 'risques', 'opportunités', 'alternatives'] as $m) {
            $this->assertStringContainsStringIgnoringCase($m, $explorer);
        }

        $slow = BlogExplorerFacilitation::defaultPrompt('slow_down', 'fr');
        foreach (['suspend', 'système', 'hypothèses', 'signaux faibles', 'réversible'] as $m) {
            $this->assertStringContainsStringIgnoringCase($m, $slow);
        }

        $clarifier = BlogExplorerFacilitation::defaultPrompt('clarifier', 'fr');
        foreach (['termes', 'affirmations', 'hypothèses', 'points de vue', 'désaccords'] as $m) {
            $this->assertStringContainsStringIgnoringCase($m, $clarifier);
        }

        $invent = BlogExplorerFacilitation::defaultPrompt('invent', 'fr');
        foreach (['analogie', 'modél', 'inverser', 'échelle', 'rapprochement inattendu'] as $m) {
            $this->assertStringContainsStringIgnoringCase($m, $invent);
        }
    }

    public function test_the_facilitation_rules_state_the_four_non_directive_commitments_in_both_locales(): void
    {
        $fr = BlogExplorerFacilitation::facilitationRules('fr');
        $this->assertStringContainsString('jamais directif', $fr);
        $this->assertStringContainsString('Jamais toute la méthode en un seul message', $fr);
        $this->assertStringContainsString('Tu ne réponds pas à la place de l\'humain', $fr);
        $this->assertStringContainsString('Tu ne passes jamais automatiquement à l\'étape suivante', $fr);
        $this->assertStringContainsString('La validation humaine est toujours l\'étape finale', $fr);

        $en = BlogExplorerFacilitation::facilitationRules('en');
        $this->assertStringContainsString('never directive', $en);
        $this->assertStringContainsString('Never unfold the whole method in a single message', $en);
        $this->assertStringContainsString('You do not answer in the human\'s place', $en);
        $this->assertStringContainsString('You never move to the next step automatically', $en);
        $this->assertStringContainsString('Human validation is always the final step', $en);

        $this->assertNotSame($fr, $en);
    }

    public function test_an_unknown_locale_falls_back_to_french_and_an_unknown_method_throws(): void
    {
        $this->assertSame(
            BlogExplorerFacilitation::defaultPrompt('explorer', 'fr'),
            BlogExplorerFacilitation::defaultPrompt('explorer', 'de'),
        );
        $this->assertSame(BlogExplorerFacilitation::facilitationRules('fr'), BlogExplorerFacilitation::facilitationRules('de'));

        $this->expectException(\InvalidArgumentException::class);
        BlogExplorerFacilitation::defaultPrompt('six_hats', 'fr');
    }
}
