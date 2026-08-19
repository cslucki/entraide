<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiUserCreditSettings;
use App\Support\Ai\AiRefusedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1248 — fermer l'autorite economique de `BlogExplorerController`
 * (dialogue Explorer + note d'analyse, chemin herite, cle plateforme) :
 * garde AVANT provider, ledger canonique sur chaque appel reellement tente,
 * credential PROUVE `platform`, tenant = Organization de l'article, refus
 * rendu 429 `{error, code, offers_url}` — jamais `200 {text}`. Seulement le
 * bypass economique : aucune migration vers le systeme nerveux.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1248BlogExplorerEconomicAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $author;

    private User $admin;

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
            'ai.openai.base_url' => 'https://api.openai.test/v1',
            'ai.blog.economic_guard.monthly_budget_usd' => 2.00,
            'ai.blog.economic_guard.monthly_unknown_limit' => 10,
        ]);

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'orga-1248']);

        $this->author = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);
        $this->admin = User::factory()->create(['organization_id' => $this->organization->id, 'is_admin' => true]);

        $this->post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'title' => 'Article TASK-1248',
            'slug' => 'article-task-1248',
            'content' => '<p>'.str_repeat('Un contenu sauvegarde a explorer en profondeur. ', 12).'</p>',
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
                'choices' => [['message' => ['content' => '<h3>Analyse</h3><p>'.str_repeat('Reponse du fake Explorer. ', 12).'</p>']]],
                'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $completionTokens],
            ]),
        ]);
    }

    private function exhaustUserCredit(): void
    {
        // Credit plateforme : zero utilisation incluse, offres proposees.
        app(AiUserCreditSettings::class)->updatePlatform([
            'free_enabled' => true, 'monthly_uses' => 0, 'alert_percent' => 80, 'offer_subscription' => true,
        ], $this->admin);
    }

    private function chat(?User $as = null, array $payload = ['message' => 'Que dit cet article ?'])
    {
        return $this->actingAs($as ?? $this->author)
            ->postJson(route('blog.explorer.chat', $this->post), $payload);
    }

    private function note(?User $as = null)
    {
        return $this->actingAs($as ?? $this->author)
            ->postJson(route('blog.explorer.note.generate', $this->post), [
                'messages' => [['role' => 'user', 'text' => 'Resume-moi cet article.']],
            ]);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'Un refus n\'ecrit aucune ligne de ledger.');
        $this->assertSame(0, AiInteraction::query()->count(), 'Un refus n\'ecrit aucune trace produit.');
        Http::assertNothingSent();
    }

    /**
     * Un refus economique est STRUCTURE et distinct d'une reponse IA : 429,
     * `{error, code, offers_url}`, jamais la cle `text` du contrat deep-chat.
     */
    private function assertRefused($response, string $code): void
    {
        $response->assertStatus(429)
            ->assertJsonPath('code', $code)
            ->assertJsonStructure(['error', 'code', 'offers_url'])
            ->assertJsonMissingPath('text')
            ->assertJsonMissingPath('note');
    }

    // =====================================================================
    // A. SUCCES : ledger + trace, credential prouve, tenant = article
    // =====================================================================

    public function test_a_dialogue_writes_one_ledger_line_with_the_platform_credential_and_one_product_trace(): void
    {
        $this->fakeChatCompletion();

        $this->chat()->assertOk()->assertJsonStructure(['text']);
        Http::assertSentCount(1);

        $ledger = AiProviderInvocation::query()->get();
        $this->assertCount(1, $ledger);
        $row = $ledger->first();
        $this->assertSame($this->organization->id, $row->organization_id);
        $this->assertSame($this->author->id, $row->user_id);
        $this->assertNull($row->capability, 'Pas une capability canonique : dit tel quel.');
        $this->assertSame('blog_explorer', $row->feature);
        $this->assertSame('blog.explorer_dialogue', $row->process);
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

        $interaction = AiInteraction::query()->where('feature', 'blog_explorer')->firstOrFail();
        $this->assertSame($this->post->organization_id, $interaction->organization_id, 'Tenant = Organization de l\'article.');
        $this->assertSame($this->author->id, $interaction->user_id);
        $this->assertSame('blog.explorer_dialogue', $interaction->process);
        $this->assertSame($row->correlation_id, $interaction->correlation_id, 'Meme correlation sur les deux traces.');
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $interaction->metadata['credential_source'] ?? null);
        $this->assertSame($this->post->id, $interaction->metadata['blog_post_id'] ?? null);
        $this->assertSame(1, AiInteraction::query()->count(), 'Une seule trace produit : zero double comptage.');
    }

    public function test_a_generated_note_is_under_the_same_authority(): void
    {
        $this->fakeChatCompletion(2_000, 300);

        $this->note()->assertOk()->assertJsonStructure(['note', 'length']);
        Http::assertSentCount(1);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame('blog.explorer_note', $row->process);
        $this->assertSame('blog_explorer_note', $row->feature);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $row->status);
        $this->assertSame(2_000, (int) $row->input_tokens);
        $this->assertSame($this->post->organization_id, $row->organization_id);

        $interaction = AiInteraction::query()->where('feature', 'blog_explorer_note')->firstOrFail();
        $this->assertSame($row->correlation_id, $interaction->correlation_id);
        $this->assertSame($this->post->organization_id, $interaction->organization_id);
        $this->assertSame(1, AiInteraction::query()->count());
    }

    public function test_the_organization_route_alias_inherits_the_same_authority(): void
    {
        $this->fakeChatCompletion();

        $this->actingAs($this->author)
            ->postJson(route('organization.blog.explorer.chat', [
                'organization' => $this->organization->slug,
                'post' => $this->post,
            ]), ['message' => 'Que dit cet article ?'])
            ->assertOk()
            ->assertJsonStructure(['text']);

        $this->assertSame(1, AiProviderInvocation::query()->count());
        $this->assertSame('blog.explorer_dialogue', AiProviderInvocation::query()->value('process'));
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, AiProviderInvocation::query()->value('credential_source'));
    }

    // =====================================================================
    // B. REFUS AVANT PROVIDER : rien ne part, rien ne s'ecrit, 429 + code
    // =====================================================================

    public function test_an_exhausted_user_credit_refuses_dialogue_and_note_before_any_call_with_a_structured_429(): void
    {
        Http::fake();
        $this->exhaustUserCredit();

        $this->assertRefused($this->chat(), AiRefusedException::CODE_USER_CREDIT_EXHAUSTED);
        $this->assertRefused($this->note(), AiRefusedException::CODE_USER_CREDIT_EXHAUSTED);

        // Alias Organization : meme garde, meme contrat, aucune double implementation.
        $this->assertRefused(
            $this->actingAs($this->author)->postJson(route('organization.blog.explorer.chat', [
                'organization' => $this->organization->slug,
                'post' => $this->post,
            ]), ['message' => 'Encore ?']),
            AiRefusedException::CODE_USER_CREDIT_EXHAUSTED,
        );

        $this->assertNothingWritten();
    }

    public function test_the_credit_refusal_carries_the_offers_url_when_the_platform_offers_a_subscription(): void
    {
        Http::fake();
        $this->exhaustUserCredit();

        $response = $this->chat();
        $this->assertRefused($response, AiRefusedException::CODE_USER_CREDIT_EXHAUSTED);
        $this->assertIsString($response->json('offers_url'));
        $this->assertNotSame('', $response->json('offers_url'));
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
        // Depense deja connue ce mois-ci, sur ce tenant (autre process Blog).
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

        $this->assertRefused($this->chat(), AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED);
        $this->assertRefused($this->note(), AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED);

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(1, AiInteraction::query()->count(), 'Seule la ligne de fixture existe.');
    }

    public function test_the_process_monthly_budget_refuses_the_dialogue_but_not_the_note_which_is_another_process(): void
    {
        // Un seul stub pour tout le test : le premier `Http::fake()` enregistre
        // gagne (un stub vide masquerait la reponse posee ensuite).
        $this->fakeChatCompletion();
        config(['ai.blog.economic_guard.monthly_budget_usd' => 0.10]);
        AiInteraction::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->organization->id,
            'process' => 'blog.explorer_dialogue',
            'feature' => 'blog_explorer',
            'model' => 'openai/gpt-catalogued',
            'prompt' => 'p',
            'response' => 'r',
            'input_tokens' => 10,
            'output_tokens' => 10,
            'cost_usd' => 0.20,
            'cost_unknown' => false,
            'metadata' => [],
        ]);

        $this->assertRefused($this->chat(), AiRefusedException::CODE_ORGANIZATION_BUDGET_REACHED);
        Http::assertNothingSent();

        $this->note()->assertOk();
        Http::assertSentCount(1);
        $this->assertSame('blog.explorer_note', AiProviderInvocation::query()->value('process'));
    }

    public function test_a_missing_platform_key_refuses_as_not_configured_before_any_call(): void
    {
        Http::fake();
        config(['ai.openai.api_key' => '']);

        $this->assertRefused($this->chat(), AiRefusedException::CODE_NOT_CONFIGURED);
        $this->assertRefused($this->note(), AiRefusedException::CODE_NOT_CONFIGURED);

        $this->assertNothingWritten();
    }

    // =====================================================================
    // C. ECHEC APRES DEPART : ledger failed, aucune trace produit
    // =====================================================================

    public function test_a_provider_failure_writes_a_failed_ledger_line_without_product_trace(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $this->chat()->assertStatus(500);

        $row = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_FAILED, $row->status);
        $this->assertSame(\RuntimeException::class, $row->failure_reason);
        $this->assertNull($row->provider_cost, 'Jamais 0 invente sur un echec.');
        $this->assertSame(AiProviderInvocation::COST_UNKNOWN, $row->cost_status);
        $this->assertNull($row->input_tokens);
        $this->assertSame('blog.explorer_dialogue', $row->process);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_PLATFORM, $row->credential_source);
        $this->assertSame($this->post->organization_id, $row->organization_id);

        $this->assertSame(0, AiInteraction::query()->count(), 'Un echec n\'est pas une reponse Explorer.');
    }

    // =====================================================================
    // D. CE QUI NE CHANGE PAS : contrat « article non sauvegarde », throttle
    // =====================================================================

    public function test_an_unsaved_article_keeps_its_historical_contract_and_never_reaches_the_guard(): void
    {
        Http::fake();
        $this->exhaustUserCredit();
        $this->post->forceFill(['content' => ''])->saveQuietly();

        // Le contrat historique (200 {text} pour le dialogue, 422 {error} pour
        // la note) precede la garde : rien n'est evalue, rien n'est ecrit.
        $this->chat()->assertOk()->assertJsonPath('text', __('blog.explorer_article_not_saved'));
        $this->note()->assertStatus(422)->assertJsonPath('error', __('blog.explorer_article_not_saved'));

        $this->assertNothingWritten();
    }

    public function test_the_frequency_throttle_stays_in_place_beside_the_economic_guard(): void
    {
        foreach (['blog.explorer.chat', 'blog.explorer.note.generate'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('throttle:20,1', $route->gatherMiddleware(), $name.' garde son throttle de frequence.');
        }
    }
}
