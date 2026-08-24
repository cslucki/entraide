<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1288 — fermer le trou de securite du flux CREATION de
 * `BlogController::handleAi()` : sur la surface courte (`POST /blog/ai-generate`,
 * `POST /blog/ai-correct`), l'Organization resolue est l'Organization PAR
 * DEFAUT, quel que soit l'utilisateur connecte. Sans article persiste, rien
 * ne verifiait que l'utilisateur lui appartient : un membre d'une AUTRE
 * Organization creait un article chez elle et debitait son budget IA
 * (`BlogAiService::tenantOf()`). Invariant viole : Organization = Tenant.
 *
 * Preuves : l'etranger est REFUSE (403 JSON, meme forme que `ai_disabled`)
 * sur les deux modes et sur les deux surfaces, et ce refus n'ecrit RIEN —
 * ni `blog_posts`, ni ledger, ni trace, ni appel provider ; le membre
 * legitime passe toujours, sur les deux modes. Le chemin `post_id` d'un
 * article existant reste garde par `checkPostAccess()` (tenant + policy
 * `update`), inchange.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1288BlogAiCreateFlowMembershipTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaultOrganization;

    private Organization $otherOrganization;

    private User $member;

    private User $stranger;

    private Category $category;

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
            'ai.openai.base_url' => 'https://api.openai.com/v1',
            'ai.openai.model' => 'gpt-catalogued',
            'ai.blog.economic_guard.monthly_budget_usd' => 2.00,
            'ai.blog.economic_guard.monthly_unknown_limit' => 10,
        ]);

        // L'Organization PAR DEFAUT : celle que `ResolveUrlOrganization` lie a
        // toute requete de la surface courte /blog/... (is_default = true).
        $this->defaultOrganization = Organization::factory()->create([
            'name' => 'BouclePro 1288',
            'slug' => 'bouclepro-1288',
            'is_active' => true,
            'is_default' => true,
        ]);
        $this->otherOrganization = Organization::factory()->create([
            'name' => 'Autre Organization 1288',
            'slug' => 'autre-org-1288',
            'is_active' => true,
            'is_default' => false,
        ]);

        $this->member = User::factory()->create([
            'organization_id' => $this->defaultOrganization->id,
            'is_admin' => false,
            'preferred_locale' => 'fr',
        ]);
        // Authentifie ET verifie (UserFactory pose email_verified_at) : il
        // franchit `auth` + `verified`, les seuls middlewares de la route.
        $this->stranger = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'is_admin' => false,
            'preferred_locale' => 'fr',
        ]);

        $this->category = Category::create([
            'name_b2c' => 'Cat 1288',
            'name_b2b' => 'Cat 1288 B2B',
            'slug' => 'cat-1288-'.uniqid(),
            'color' => '#6366f1',
            'organization_id' => $this->defaultOrganization->id,
        ]);

        Http::preventStrayRequests();
    }

    private function fakeCorrection(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => '<p>Contenu corrige par le fake.</p>']]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
        ]);
    }

    private function fakeGeneration(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'title' => 'Titre genere',
                    'summary' => 'Resume genere.',
                    'content' => '<h2>Introduction</h2><p>Contenu genere par le fake.</p>',
                ])]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
            ]),
        ]);
    }

    private function generatePayload(): array
    {
        return [
            'title' => 'Titre 1288',
            'summary' => 'Resume 1288',
            'category_id' => $this->category->id,
        ];
    }

    private function correctPayload(): array
    {
        return ['content' => '<p>Un contenu a corriger, assez long pour la validation.</p>'];
    }

    private function refusalMessage(): string
    {
        return trans('blog.ai_cross_org', [], 'fr');
    }

    /**
     * Doctrine de la garde economique : ce qui n'est pas parti n'est pas une
     * utilisation. Un refus d'appartenance n'ecrit rien nulle part.
     */
    private function assertNothingCreatedNorSpent(): void
    {
        $this->assertSame(0, BlogPost::query()->count(), 'Un refus ne cree aucun article.');
        $this->assertSame(0, AiProviderInvocation::query()->count(), 'Un refus n\'ecrit aucune ligne de ledger.');
        $this->assertSame(0, AiInteraction::query()->count(), 'Un refus n\'ecrit aucune trace produit.');
        Http::assertNothingSent();
    }

    // =====================================================================
    // A. L'ETRANGER EST REFUSE — surface courte, les deux modes
    // =====================================================================

    public function test_a_stranger_is_refused_on_ai_correct_and_nothing_is_written(): void
    {
        Http::fake();

        $this->actingAs($this->stranger)
            ->postJson(route('blog.ai-correct'), $this->correctPayload())
            ->assertStatus(403)
            ->assertExactJson(['error' => $this->refusalMessage()]);

        $this->assertNothingCreatedNorSpent();
    }

    public function test_a_stranger_is_refused_on_ai_generate_and_nothing_is_written(): void
    {
        Http::fake();

        // Categorie VALIDE de l'Organization par defaut : la seule barriere
        // que `generate` opposait — elle ne prouve pas l'appartenance.
        $this->actingAs($this->stranger)
            ->postJson(route('blog.ai-generate'), $this->generatePayload())
            ->assertStatus(403)
            ->assertExactJson(['error' => $this->refusalMessage()]);

        $this->assertNothingCreatedNorSpent();
    }

    /**
     * `resolveBlogPost()` transforme un `post_id` INCONNU en article temporaire
     * porte par l'Organization resolue, sans aucun controle : c'est le flux
     * creation sous un autre nom (appel provider debite au tenant resolu,
     * sans meme persister l'article). Il est ferme par la meme garde.
     */
    public function test_a_stranger_is_refused_with_an_unknown_post_id_on_both_modes(): void
    {
        Http::fake();

        $this->actingAs($this->stranger)
            ->postJson(route('blog.ai-correct'), ['post_id' => (string) Str::uuid()] + $this->correctPayload())
            ->assertStatus(403)
            ->assertExactJson(['error' => $this->refusalMessage()]);

        $this->actingAs($this->stranger)
            ->postJson(route('blog.ai-generate'), ['post_id' => (string) Str::uuid()] + $this->generatePayload())
            ->assertStatus(403)
            ->assertExactJson(['error' => $this->refusalMessage()]);

        $this->assertNothingCreatedNorSpent();
    }

    /**
     * La surface prefixee passe par le meme `handleAi()` : `ResolveOrganization`
     * lie l'Organization de l'URL sans verifier l'appartenance non plus. La
     * garde ferme les deux surfaces d'un seul geste.
     */
    public function test_a_stranger_is_refused_on_the_organization_prefixed_surface_too(): void
    {
        Http::fake();

        $this->actingAs($this->stranger)
            ->postJson(route('organization.blog.ai-correct', ['organization' => $this->defaultOrganization->slug]), $this->correctPayload())
            ->assertStatus(403)
            ->assertExactJson(['error' => $this->refusalMessage()]);

        $this->actingAs($this->stranger)
            ->postJson(route('organization.blog.ai-generate', ['organization' => $this->defaultOrganization->slug]), $this->generatePayload())
            ->assertStatus(403)
            ->assertExactJson(['error' => $this->refusalMessage()]);

        $this->assertNothingCreatedNorSpent();
    }

    // =====================================================================
    // B. LE MEMBRE LEGITIME PASSE TOUJOURS — les deux modes
    // =====================================================================

    public function test_a_member_still_corrects_and_the_ledger_line_belongs_to_the_default_organization(): void
    {
        $this->fakeCorrection();

        $this->actingAs($this->member)
            ->postJson(route('blog.ai-correct'), $this->correctPayload())
            ->assertOk()
            ->assertJsonStructure(['content', 'provider', 'model', 'limit', 'remaining']);

        Http::assertSentCount(1);
        $this->assertSame(0, BlogPost::query()->count(), 'La correction sans post_id ne persiste pas d\'article.');
        $ledger = AiProviderInvocation::query()->get();
        $this->assertCount(1, $ledger);
        $this->assertSame($this->defaultOrganization->id, $ledger->first()->organization_id);
        $this->assertSame($this->member->id, $ledger->first()->user_id);
        $this->assertSame('blog_correct', $ledger->first()->feature);
    }

    public function test_a_member_still_generates_and_the_article_belongs_to_the_default_organization(): void
    {
        $this->fakeGeneration();

        $response = $this->actingAs($this->member)
            ->postJson(route('blog.ai-generate'), $this->generatePayload())
            ->assertOk()
            ->assertJsonStructure(['content', 'remaining', 'title', 'summary', 'post_id', 'edit_url']);

        Http::assertSentCount(1);
        $post = BlogPost::query()->findOrFail($response->json('post_id'));
        $this->assertSame($this->defaultOrganization->id, $post->organization_id);
        $this->assertSame($this->member->id, $post->user_id);
        $this->assertSame(1, AiProviderInvocation::query()->count());
        $this->assertSame($this->defaultOrganization->id, AiProviderInvocation::query()->value('organization_id'));
    }

    public function test_a_member_still_passes_on_the_organization_prefixed_surface(): void
    {
        $this->fakeCorrection();

        $this->actingAs($this->member)
            ->postJson(route('organization.blog.ai-correct', ['organization' => $this->defaultOrganization->slug]), $this->correctPayload())
            ->assertOk()
            ->assertJsonStructure(['content', 'remaining']);

        Http::assertSentCount(1);
    }

    // =====================================================================
    // C. LE CHEMIN post_id D'UN ARTICLE EXISTANT : deja garde, inchange
    // =====================================================================

    /**
     * `resolveBlogPost()` sur un article EXISTANT appelle `checkPostAccess()` :
     * 404 si l'article n'est pas dans l'Organization resolue, 403 si la policy
     * `update` (auteur, co-auteur, editeur racine de Boucle, admin plateforme)
     * refuse. L'etranger n'est ni l'un ni l'autre : rien ne part.
     */
    public function test_an_existing_post_of_the_default_organization_stays_guarded_by_check_post_access(): void
    {
        Http::fake();

        $post = BlogPost::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->defaultOrganization->id,
            'title' => 'Article du membre',
            'slug' => 'article-du-membre-1288',
            'content' => '<p>'.str_repeat('Contenu existant. ', 10).'</p>',
            'status' => 'draft',
        ]);

        $this->actingAs($this->stranger)
            ->postJson(route('blog.ai-correct'), ['post_id' => $post->id] + $this->correctPayload())
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->postJson(route('blog.ai-generate'), ['post_id' => $post->id, 'title' => 'T', 'summary' => 'S'])
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $this->assertSame(1, BlogPost::query()->count(), 'Seul l\'article de fixture existe.');
    }
}
