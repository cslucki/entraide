<?php

namespace Tests\Feature\Services\Ai;

use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\PromptRepository;
use App\Ai\ProviderResolver;
use App\Services\Ai\AiProviderInvocationLedger;
use App\Services\Ai\ClarifyUserHelpRequestService;
use App\Services\Ai\DTO\AssistedInteractionLabResult;
use App\Services\Ai\FakeAIProvider;
use App\Services\Ai\SupervisionProviderResolver;
use App\Support\Ai\AiEconomicGuard;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TASK-1283 : `analyze()` est NEUTRALISEE — elle retombe inconditionnellement
 * sur la clarification deterministe (FakeAIProvider), et le service ne possede
 * plus aucun resolver de provider de supervision. Ces tests figent ce
 * comportement : si quelqu'un reintroduit un appel provider dans `analyze()`,
 * ils rougissent — avec le test d'architecture
 * Tests\Unit\Architecture\AiEconomicAuthorityIsolationTest.
 *
 * Le chemin GOUVERNE (clarifyForLoop / clarifyForOrganization ->
 * clarifyInContext : garde economique AiEconomicGuard PUIS ledger) est couvert
 * par TASK1210ClarifyHelpRequestTest, TASK1220AiProviderInvocationLedgerTest,
 * TASK1222EconomicAuthorityTest, TASK1227OrganizationDoctrineTest et
 * TASK1236DoctrineVersionTraceabilityTest — pas ici.
 */
class ClarifyUserHelpRequestServiceTest extends TestCase
{
    private ClarifyUserHelpRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Construction complete, sans aucun mock : depuis TASK-1283 le service
        // n'a plus de dependance vers SupervisionProviderResolver ni
        // AiScenarioFactory — il ne PEUT plus atteindre un provider depuis
        // `analyze()`, quelle que soit la configuration.
        $this->service = new ClarifyUserHelpRequestService(
            new FakeAIProvider,
            app(CapabilityRegistry::class),
            app(PromptRepository::class),
            app(ProviderResolver::class),
            app(ContextBuilder::class),
            app(AiEconomicGuard::class),
            app(AiProviderInvocationLedger::class),
        );
    }

    public function test_falls_back_to_fake_provider_when_disabled(): void
    {
        config(['ai.clarify.enabled' => false]);

        $result = $this->service->analyze('Je cherche des conseils pour trouver mes premiers clients');

        $this->assertInstanceOf(AssistedInteractionLabResult::class, $result);
        $this->assertSame('help_request', $result->intent);
        $this->assertSame(0.84, $result->confidence);
        $this->assertSame('Trouver mes premiers clients', $result->title);
        $this->assertFalse($result->needsFallback());
    }

    public function test_analyze_stays_deterministic_even_when_clarify_is_enabled(): void
    {
        // Configuration la plus permissive possible : clarification activee,
        // provider et modele par defaut presents. Avant TASK-1283, ce cas
        // partait vers SupervisionProviderResolver::resolve() SANS garde
        // economique ni ledger. Il doit desormais produire exactement le meme
        // resultat deterministe que quand la clarification est desactivee.
        config([
            'ai.clarify.enabled' => true,
            'ai.default_provider' => 'openai',
            'ai.default_model' => 'gpt-4o-mini',
            'ai.openai.supervision_enabled' => true,
        ]);

        $result = $this->service->analyze('Je cherche des conseils pour trouver mes premiers clients');

        $this->assertSame('deterministic_fallback', $result->producer);
        $this->assertSame('help_request', $result->intent);
        $this->assertSame(0.84, $result->confidence);
        $this->assertSame('Trouver mes premiers clients', $result->title);
    }

    public function test_analyze_output_is_byte_identical_to_the_fallback_output(): void
    {
        config(['ai.clarify.enabled' => true]);

        foreach ([
            'Je cherche des conseils pour trouver mes premiers clients',
            'Je suis bloqué',
            'phrase sans aucun scenario correspondant',
        ] as $phrase) {
            $this->assertEquals(
                (new FakeAIProvider)->analyze($phrase)->toArray(),
                $this->service->analyze($phrase)->toArray(),
                "analyze('{$phrase}') doit etre le repli deterministe, rien d'autre.",
            );
        }
    }

    public function test_to_array_contains_expected_keys(): void
    {
        config(['ai.clarify.enabled' => true]);

        $result = $this->service->analyze('test');
        $array = $result->toArray();

        $expectedKeys = [
            'intent', 'confidence', 'title', 'need', 'context',
            'expected_help_type', 'deadline', 'suggested_loop', 'tone',
            'message_draft', 'fallback', 'human_validation', 'safety',
            '_scenario', '_scenario_label',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "Missing key: $key");
        }
    }

    /**
     * La table de correspondance help_type -> libelle francais.
     *
     * Son vehicule public historique (`mapToDto()`, chemin IA de `analyze()`)
     * est mort avec la neutralisation TASK-1283. Le consommateur VIVANT est
     * `mapStructuredToDto()` sur le chemin gouverne, dont le harnais complet
     * (Organization, credential tenant, garde, ledger) vit dans
     * TASK1210ClarifyHelpRequestTest. Ce test fige la table sans payer ce
     * harnais — par reflexion, assumee et documentee.
     */
    public function test_help_type_mapping_table_is_preserved(): void
    {
        $method = new ReflectionMethod(ClarifyUserHelpRequestService::class, 'mapHelpType');

        foreach ([
            'service_offer' => 'proposition de service',
            'collaboration' => 'collaboration',
            'information' => 'information, conseil',
            'support' => 'soutien, accompagnement',
            'other' => 'autre',
            'unknown' => 'autre',
        ] as $input => $expected) {
            $this->assertSame($expected, $method->invoke($this->service, $input));
        }
    }

    // -----------------------------------------------------------------
    // SupervisionProviderResolver : selection du provider par defaut du
    // banc de supervision. Le resolver ne sert PLUS a `analyze()` — ces
    // tests restent parce qu'ils couvrent le comportement du resolver
    // lui-meme (banc supervision, offre de service), pas le service CHR.
    // -----------------------------------------------------------------

    public function test_resolver_picks_openrouter_when_config_default_null(): void
    {
        config([
            'ai.default_provider' => null,
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => true,
            'ai.openrouter.model' => 'mistralai/ministral-3b-2512',
        ]);

        $resolver = new SupervisionProviderResolver;

        $this->assertSame('openrouter', $resolver->defaultProvider());
    }

    public function test_resolver_has_no_default_when_everything_disabled(): void
    {
        config([
            'ai.default_provider' => null,
            'ai.ollama.enabled' => false,
            'ai.openrouter.enabled' => false,
            'ai.openai.supervision_enabled' => false,
        ]);

        $resolver = new SupervisionProviderResolver;

        $this->assertNull($resolver->defaultProvider());
    }
}
