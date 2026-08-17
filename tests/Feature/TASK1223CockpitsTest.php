<?php

namespace Tests\Feature;

use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\AiCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1223 — cockpits IA : USER / ADMIN ORGANIZATION / SUPERADMIN.
 *
 * Meme verite technique, trois niveaux de visibilite. Preuves centrales :
 *
 *  - USER : scope strict (soi-meme + son Organization) — appartenir a la meme
 *    Organization ne suffit PAS pour voir les usages d'un autre ;
 *  - ADMIN ORG : l'etat de SON Organization, la cle d'acces JAMAIS rendue ;
 *  - SUPERADMIN : des metadonnees multi-Organizations, JAMAIS un contenu
 *    tenant (chunk, prompt, document) ni une cle ;
 *  - partout : « — » != 0, known != unknown, rien d'invente.
 */
class TASK1223CockpitsTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'sk-task1223-secret-never-rendered';

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => self::API_KEY,
            'monthly_budget_usd' => 5.00,
        ]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    // =====================================================================
    // A. USER — « Mes usages IA »
    // =====================================================================

    public function test_a_member_sees_only_their_own_ledger_lines(): void
    {
        $sibling = User::factory()->create(['organization_id' => $this->organization->id]);

        $mine = $this->invocation($this->organization, $this->member, capability: 'clarify_help_request');
        $this->invocation($this->organization, $sibling, capability: 'loop_knowledge_answer');

        $response = $this->actingAs($this->member)->get(route('profile.ai-usage'));

        $response->assertOk();
        // Sa ligne, et RIEN de l'autre membre — meme Organization ne suffit pas.
        $this->assertSame(1, substr_count($response->getContent(), 'data-my-ai-usage-row'));
        $response->assertSee('clarify_help_request');
        $response->assertDontSee('loop_knowledge_answer');
    }

    public function test_lines_from_another_organization_are_never_shown(): void
    {
        $other = Organization::factory()->create();
        // Incoherence historique volontaire : une ligne d'une AUTRE org qui
        // pointe vers ce user. Le scope org la rend invisible.
        $this->invocation($other, $this->member, capability: 'foreign_org_line');

        $response = $this->actingAs($this->member)->get(route('profile.ai-usage'));

        $response->assertOk();
        $response->assertSee('data-my-ai-usage-empty', false);
        $response->assertDontSee('foreign_org_line');
    }

    public function test_zero_and_unknown_render_differently_for_the_user(): void
    {
        // Un vrai zero mesure…
        $this->invocation($this->organization, $this->member, cost: 0.0);
        // …et un cout non mesurable.
        $this->invocation($this->organization, $this->member, cost: null);

        $response = $this->actingAs($this->member)->get(route('profile.ai-usage'));

        $response->assertOk();
        // Le zero mesure s'affiche en dollars, l'inconnu s'affiche « — ».
        $response->assertSee('$0.0000000000');
        $response->assertSee('—');
    }

    public function test_the_user_page_never_contains_the_api_key(): void
    {
        $this->invocation($this->organization, $this->member);

        $response = $this->actingAs($this->member)->get(route('profile.ai-usage'));

        $response->assertOk();
        $response->assertDontSee(self::API_KEY);
    }

    public function test_guests_are_redirected_from_the_usage_page(): void
    {
        $this->get(route('profile.ai-usage'))->assertRedirect();
    }

    // =====================================================================
    // B. ADMIN ORGANIZATION — hub « IA & connaissances »
    // =====================================================================

    public function test_the_org_admin_hub_shows_the_four_blocks_and_readiness(): void
    {
        $admin = $this->orgAdmin();
        $this->invocation($this->organization, $this->member, cost: 0.12);

        $response = $this->actingAs($admin)->get($this->hubUrl());

        $response->assertOk();
        $response->assertSee('data-cockpit-config', false);
        $response->assertSee('data-cockpit-behavior', false);
        $response->assertSee('data-cockpit-knowledge', false);
        $response->assertSee('data-cockpit-consumption', false);
        $response->assertSee(__('ai.cockpit_config_ready'));
        $response->assertSee('$5.00');
    }

    public function test_the_hub_flags_a_missing_admin_prompt(): void
    {
        $admin = $this->orgAdmin();
        AdminAiPrompt::query()->where('scenario_id', 'like', 'chatloop_ai_summarize%')->delete();

        $response = $this->actingAs($admin)->get($this->hubUrl());

        $response->assertOk();
        $response->assertSee(__('ai.cockpit_behavior_prompt_missing'));
    }

    public function test_the_hub_never_renders_the_api_key(): void
    {
        $admin = $this->orgAdmin();

        $response = $this->actingAs($admin)->get($this->hubUrl());

        $response->assertOk();
        $response->assertDontSee(self::API_KEY);
        $response->assertSee(__('ai.cockpit_config_credential_set'));
    }

    public function test_a_plain_member_cannot_open_the_hub(): void
    {
        $this->actingAs($this->member)->get($this->hubUrl())->assertForbidden();
    }

    public function test_the_admin_of_another_organization_cannot_open_this_hub(): void
    {
        $other = Organization::factory()->create();
        $foreignAdmin = User::factory()->create(['organization_id' => $other->id]);
        $other->update(['admin_id' => $foreignAdmin->id]);

        $this->actingAs($foreignAdmin)->get($this->hubUrl())->assertForbidden();
    }

    // =====================================================================
    // C. SUPERADMIN — cockpit plateforme
    // =====================================================================

    public function test_the_platform_cockpit_shows_metadata_for_several_organizations(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->organization->id]);

        $orgB = Organization::factory()->create();
        $memberB = User::factory()->create(['organization_id' => $orgB->id]);

        $this->invocation($this->organization, $this->member, cost: 0.10);
        $this->invocation($orgB, $memberB, operation: 'embedding', embeddingOperation: 'ingestion', cost: 0.02);
        $this->invocation($orgB, $memberB, operation: 'embedding', embeddingOperation: 'query', cost: null);

        $response = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'));

        $response->assertOk();
        $response->assertSee('data-platform-org="'.$this->organization->id.'"', false);
        $response->assertSee('data-platform-org="'.$orgB->id.'"', false);
        // Total connu = 0.10 + 0.02 : les inconnus sont COMPTES, jamais sommes.
        $response->assertSee('$0.120000');
    }

    public function test_the_platform_cockpit_never_leaks_tenant_content_or_keys(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->organization->id]);

        $dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->member->id,
            'name' => 'Dossier prive 1223',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        // Un chunk au contenu sentinelle : la supervision voit le COMPTE,
        // jamais le contenu.
        DossierChunk::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => BlogPost::create([
                'organization_id' => $this->organization->id,
                'user_id' => $this->member->id,
                'title' => 'Article 1223',
                'slug' => 'article-1223-'.Str::uuid(),
                'content' => '<p>x</p>',
                'status' => 'published',
                'published_at' => now(),
            ])->id,
            'chunk_index' => 0,
            'content' => 'CONTENU-PRIVE-SENTINELLE-TASK1223',
            'content_hash' => str_repeat('a', 64),
            'embedding' => config('database.default') === 'pgsql' ? array_fill(0, 1536, 0.1) : json_encode([0.1]),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        $response = $this->actingAs($superAdmin)->get(route('admin.ai-organizations'));

        $response->assertOk();
        $response->assertDontSee('CONTENU-PRIVE-SENTINELLE-TASK1223');
        $response->assertDontSee(self::API_KEY);
        // Mais le COMPTE est bien la (au moins un extrait indexe).
        $response->assertSee('data-platform-card="known-cost"', false);
    }

    public function test_a_non_admin_cannot_open_the_platform_cockpit(): void
    {
        $this->actingAs($this->member)->get(route('admin.ai-organizations'))->assertForbidden();
    }

    // =====================================================================
    // D. by-user corrige (TASK-306) : fenetre et couts honnetes
    // =====================================================================

    public function test_by_user_sums_only_the_requested_window_and_splits_known_from_unknown(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->organization->id]);

        // Dans la fenetre : un cout connu et un inconnu.
        $this->generationTrace(costUsd: 0.50, costUnknown: false);
        $this->generationTrace(costUsd: null, costUnknown: true);
        // HORS fenetre : un gros cout qui ne doit PAS etre somme.
        $old = $this->generationTrace(costUsd: 9.99, costUnknown: false);
        $old->forceFill(['created_at' => now()->subMonths(2)])->saveQuietly();

        $response = $this->actingAs($superAdmin)->get(route('admin.ia-usage-by-user', [
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

        $response->assertOk();
        // La fenetre s'applique aux LIGNES sommees : 0.50, pas 10.49.
        $response->assertSee('$0.500000');
        $response->assertDontSee('$10.490000');
        $response->assertSee('1 non mesuré(s)');
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function hubUrl(): string
    {
        return route('organization.admin.ai-cockpit', ['organization' => $this->organization->slug]);
    }

    private function orgAdmin(): User
    {
        $admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->organization->update(['admin_id' => $admin->id]);

        return $admin;
    }

    private function invocation(
        Organization $organization,
        User $user,
        string $capability = 'clarify_help_request',
        string $operation = 'generation',
        ?string $embeddingOperation = null,
        ?float $cost = 0.001,
    ): AiProviderInvocation {
        return AiProviderInvocation::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'capability' => $capability,
            'process' => 'help_request.clarify',
            'operation' => $operation,
            'embedding_operation' => $embeddingOperation,
            'provider' => 'openai',
            'model' => $operation === 'generation' ? 'gpt-4o-mini' : 'text-embedding-3-small',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'input_tokens' => $operation === 'generation' ? 100 : null,
            'output_tokens' => $operation === 'generation' ? 50 : null,
            'total_tokens' => $operation === 'generation' ? 150 : 30,
            'provider_cost' => $cost,
            'currency' => $cost !== null ? 'USD' : null,
            'cost_status' => $cost !== null ? AiProviderInvocation::COST_KNOWN : AiProviderInvocation::COST_UNKNOWN,
            'cost_source' => $cost !== null ? AiCost::SOURCE_CATALOG_ESTIMATED : AiProviderInvocation::COST_UNKNOWN,
            'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);
    }

    private function generationTrace(?float $costUsd, ?bool $costUnknown): AiInteraction
    {
        return AiInteraction::create([
            'user_id' => $this->member->id,
            'organization_id' => $this->organization->id,
            'process' => 'help_request.clarify',
            'feature' => 'clarify_help_request',
            'model' => 'openai/gpt-4o-mini',
            'prompt' => 'x',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cost_usd' => $costUsd,
            'cost_unknown' => $costUnknown,
        ]);
    }
}
