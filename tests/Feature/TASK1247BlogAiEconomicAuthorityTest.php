<?php

namespace Tests\Feature;

use App\Ai\ProviderResolver;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Services\BlogAiService;
use App\Support\Ai\AiRefusedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1247 — fermer l'autorite economique de `BlogAiService` (chemin herite,
 * cle plateforme) : garde AVANT provider, ledger canonique sur chaque appel
 * reellement tente, credential PROUVE `platform`, tenant = Organization de
 * l'article. Seulement le bypass economique : aucune migration vers le
 * systeme nerveux.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1247BlogAiEconomicAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $author;

    private User $superAdmin;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_pricing.version' => 'test-catalog',
            'ai_pricing.overrides' => [],
            'ai_pricing.models' => [
                'openai' => [
                    'gpt-catalogued' => ['input_per_1m' => 1.0, 'output_per_1m' => 4.0],
                ],
            ],
            'ai.default_provider' => 'openai',
            'ai.default_model' => null,
            'ai.openai.api_key' => 'platform-test-key',
            'ai.openai.model' => 'gpt-catalogued',
            'ai.blog.economic_guard.monthly_budget_usd' => 2.00,
            'ai.blog.economic_guard.monthly_unknown_limit' => 10,
        ]);

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->otherOrganization = Organization::factory()->create(['is_active' => true]);

        $this->author = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);

        $this->post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article TASK-1247',
            'slug' => 'article-task-1247',
            'content' => '<p>'.str_repeat('Un contenu suffisamment long pour etre corrige. ', 12).'</p>',
            'summary' => 'Resume',
            'status' => 'draft',
        ]);

        app()->instance('current_organization', $this->organization);
        app()->setLocale('fr');

        Http::preventStrayRequests();
    }

    private function fakeChatCompletion(int $promptTokens = 1_000, int $completionTokens = 500): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => '<p>'.str_repeat('Reponse du fake. ', 20).'</p>']]],
                'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
            ]),
        ]);
    }

    private function service(): BlogAiService
    {
        return app(BlogAiService::class);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'Un refus n\'ecrit aucune ligne de ledger.');
        $this->assertSame(0, AiInteraction::query()->count(), 'Un refus n\'ecrit aucune trace produit.');
        Http::assertNothingSent();
    }

    // =====================================================================
    // A. SUCCES : ledger + trace, credential prouve, tenant = article
    // =====================================================================

    public function test_a_generation_writes_one_ledger_line_with_the_platform_credential_and_one_product_trace(): void
    {
        $this->fakeChatCompletion();

        $result = $this->service()->generate($this->post, $this->author, 'Titre', 'Resume');

        $this->assertNotSame('', $result['content']);
        Http::assertSentCount(1);

        $ledger = AiProviderInvocation::query()->get();
        $this->assertCount(1, $ledger);
        $row = $ledger->first();
        $this->assertSame($this->organization->id, $row->organization_id);
        $this->assertSame($this->author->id, $row->user_id);
        $this->assertNull($row->capability, 'Pas une capability canonique : dit tel quel.');
        $this->assertSame('blog_generate', $row->feature);
        $this->assertSame('blog.article_generate', $row->process);
        $this->assertSame(AiProviderInvocation::OPERATION_GENERATION, $row->operation);
        $this->assertSame('openai', $row->provider);
        $this->assertSame('gpt-catalogued', $row->model);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        $this->assertSame(1_000, (int) $row->input_tokens);
        $this->assertSame(500, (int) $row->output_tokens);
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $row->cost_status);
        // 1000 x 1.0/1M + 500 x 4.0/1M = 0.001 + 0.002
        $this->assertEqualsWithDelta(0.003, (float) $row->provider_cost, 0.0000001);
        $this->assertNotNull($row->correlation_id);

        $interaction = AiInteraction::query()->where('feature', 'blog_generate')->firstOrFail();
        $this->assertSame($this->organization->id, $interaction->organization_id);
        $this->assertSame($this->author->id, $interaction->user_id);
        $this->assertSame($row->correlation_id, $interaction->correlation_id, 'Meme correlation sur les deux traces.');
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $interaction->metadata['credential_source'] ?? null);
    }

    public function test_the_tenant_of_the_trace_is_the_article_organization_not_the_request_one(): void
    {
        $this->fakeChatCompletion();

        // Un SuperAdmin de la plateforme agit depuis une autre Organization
        // courante : la trace atterrit dans l'Organization de l'ARTICLE.
        app()->instance('current_organization', $this->otherOrganization);
        $this->superAdmin->organization_id = $this->otherOrganization->id;
        $this->superAdmin->save();

        $this->service()->correct($this->post, $this->superAdmin);

        $this->assertSame($this->organization->id, AiProviderInvocation::query()->value('organization_id'));
        $this->assertSame($this->organization->id, AiInteraction::query()->value('organization_id'));
    }

    public function test_method_selection_is_under_the_same_authority(): void
    {
        $this->fakeChatCompletion();

        $this->service()->methodSelection($this->post, $this->author, 'clarifier', 'Un passage selectionne');

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame('blog.method_selection', $row->process);
        $this->assertSame('blog_method_selection_clarifier_fr', $row->feature);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertSame(1, AiInteraction::query()->count());
    }

    // =====================================================================
    // B. REFUS AVANT PROVIDER : rien ne part, rien ne s'ecrit
    // =====================================================================

    public function test_an_exhausted_user_credit_refuses_before_any_call_for_all_three_features(): void
    {
        Http::fake();
        // Credit plateforme : zero utilisation incluse.
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true, 'monthly_uses' => 0, 'alert_percent' => 80, 'offer_subscription' => true,
        ], $this->superAdmin);

        foreach ([
            fn () => $this->service()->generate($this->post, $this->author, 'Titre', 'Resume'),
            fn () => $this->service()->correct($this->post, $this->author),
            fn () => $this->service()->methodSelection($this->post, $this->author, 'explorer', 'Un passage'),
        ] as $call) {
            try {
                $call();
                $this->fail('Le credit epuise doit refuser AVANT l\'appel.');
            } catch (AiRefusedException $exception) {
                $this->assertSame(AiRefusedException::CODE_USER_CREDIT_EXHAUSTED, $exception->refusalCode);
            }
        }

        $this->assertNothingWritten();
    }

    public function test_an_organization_budget_reached_refuses_before_any_call(): void
    {
        Http::fake();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-catalogued',
            'api_key' => 'sk-tenant',
            'monthly_budget_usd' => 0.001,
        ]);
        // Depense deja connue ce mois-ci, sur ce tenant.
        AiInteraction::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'process' => 'blog.article_generate',
            'feature' => 'blog_generate',
            'model' => 'openai/gpt-catalogued',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'cost_usd' => 0.5,
            'cost_unknown' => false,
            'metadata' => [],
        ]);
        AiProviderInvocation::query()->delete();

        try {
            $this->service()->generate($this->post, $this->author, 'Titre', 'Resume');
            $this->fail('Le budget Organization atteint doit refuser AVANT l\'appel.');
        } catch (AiRefusedException $exception) {
            $this->assertSame(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, $exception->refusalCode);
        }

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(1, AiInteraction::query()->count(), 'Seule la ligne de fixture existe.');
    }

    public function test_the_process_monthly_budget_refuses_before_any_call(): void
    {
        Http::fake();
        config(['ai.blog.economic_guard.monthly_budget_usd' => 0.10]);
        AiInteraction::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'process' => 'blog.article_correct',
            'feature' => 'blog_correct',
            'model' => 'openai/gpt-catalogued',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'cost_usd' => 0.20,
            'cost_unknown' => false,
            'metadata' => [],
        ]);
        // TASK-1260 : jumelle ledger — l'autorite generation de la garde
        // depuis le cutover ; le reel ecrit les deux tables ensemble.
        AiProviderInvocation::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->author->id,
            'process' => 'blog.article_correct',
            'operation' => AiProviderInvocation::OPERATION_GENERATION,
            'provider' => 'openai',
            'model' => 'gpt-catalogued',
            'credential_source' => AiProviderInvocation::CREDENTIAL_PLATFORM,
            'provider_cost' => 0.20,
            'currency' => 'USD',
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'cost_source' => 'catalog_estimated',
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);

        // Le budget du process `blog.article_correct` est atteint : correct()
        // refuse ; generate() (process distinct) passe encore.
        try {
            $this->service()->correct($this->post, $this->author);
            $this->fail('Le budget du process doit refuser AVANT l\'appel.');
        } catch (AiRefusedException $exception) {
            $this->assertSame(AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED, $exception->refusalCode);
        }

        Http::assertNothingSent();

        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => '<p>ok</p>']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
            ]),
        ]);
        $this->service()->generate($this->post, $this->author, 'Titre', 'Resume');
        Http::assertSentCount(1);
    }

    public function test_a_missing_platform_key_refuses_as_not_configured_before_any_call(): void
    {
        Http::fake();
        config(['ai.openai.api_key' => '']);

        try {
            $this->service()->generate($this->post, $this->author, 'Titre', 'Resume');
            $this->fail('Une cle absente est une indisponibilite AVANT l\'appel.');
        } catch (AiRefusedException $exception) {
            $this->assertSame(AiRefusedException::CODE_NOT_CONFIGURED, $exception->refusalCode);
        }

        $this->assertNothingWritten();
    }

    // =====================================================================
    // C. ECHEC APRES DEPART : ledger failed, aucune trace produit, quota intact
    // =====================================================================

    public function test_a_provider_failure_writes_a_failed_ledger_line_without_product_trace_and_keeps_the_article_quota(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $before = $this->service()->remainingCount($this->post, $this->author, 'blog_generate');

        try {
            $this->service()->generate($this->post, $this->author, 'Titre', 'Resume');
            $this->fail('Un echec provider doit remonter.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('boom', $exception->getMessage());
        }

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame(\RuntimeException::class, $row->failure_reason);
        $this->assertNull($row->provider_cost);
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        $this->assertNull($row->input_tokens);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);

        $this->assertSame(0, AiInteraction::query()->count(), 'Un echec n\'est pas un article genere.');
        $this->assertSame($before, $this->service()->remainingCount($this->post, $this->author, 'blog_generate'));
    }

    // =====================================================================
    // D. LEDGER : la preuve du credential est POSEE, jamais deduite
    // =====================================================================

    public function test_the_platform_credential_is_declared_by_the_primitive_never_inferred(): void
    {
        $this->assertSame(AiProviderInvocation::CREDENTIAL_UNKNOWN, ProviderResolver::credentialSourceFor('legacy:platform:openai'));

        $instance = ProviderResolver::declareLegacyPlatformCredential('openai');
        $this->assertSame('legacy:platform:openai', $instance);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, ProviderResolver::credentialSourceFor($instance));

        $keyless = ProviderResolver::declareLegacyPlatformCredential('ollama', keyless: true);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_NONE, ProviderResolver::credentialSourceFor($keyless));

        // La famille nue reste inconnue : rien n'est deduit du nom du provider.
        $this->assertSame(AiProviderInvocation::CREDENTIAL_UNKNOWN, ProviderResolver::credentialSourceFor('openai'));
    }

    // =====================================================================
    // E. HTTP : le refus remonte avec son code
    // =====================================================================

    public function test_the_controller_returns_the_refusal_code_for_generation_and_method_selection(): void
    {
        Http::fake();
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true, 'monthly_uses' => 0, 'alert_percent' => 80, 'offer_subscription' => true,
        ], $this->superAdmin);

        $this->actingAs($this->author)
            ->postJson(route('blog.ai-generate'), ['post_id' => $this->post->id, 'title' => 'T', 'summary' => 'S'])
            ->assertStatus(429)
            ->assertJsonPath('code', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED)
            ->assertJsonStructure(['error', 'code']);

        $this->actingAs($this->author)
            ->postJson(route('blog.ai-method-selection'), [
                'post_id' => $this->post->id, 'method' => 'clarifier', 'selected_text' => 'Un passage',
            ])
            ->assertStatus(429)
            ->assertJsonPath('code', AiRefusedException::CODE_USER_CREDIT_EXHAUSTED);

        $this->assertNothingWritten();
    }
}
