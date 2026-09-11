<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Constitution;
use App\Ai\Context\DossierManifestSource;
use App\Ai\Context\DossierRetrievalSource;
use App\Models\AiConfig;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiDoctrine;
use App\Models\OrganizationAiSetting;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Ai\DTO\DoctrineSandboxResult;
use App\Services\Ai\OrganizationDoctrineSandbox;
use App\Services\Dossiers\DossierSemanticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1533 — AI Context Inspector V0.
 *
 * L'ecran repond a « pourquoi BouclePro a-t-il produit CETTE reponse ? » en
 * observant le pipeline REEL. Ce que cette suite doit prouver n'est donc pas
 * qu'une page s'affiche, mais que ce qu'elle affiche est VRAI :
 *
 *  A. ACCES — admin autorise, membre refuse, admin d'une autre Organization
 *     refuse, SuperAdmin par le meme chemin ; l'Organization vient de la ROUTE,
 *     et un resultat produit pour A ne se rend jamais sous B.
 *  B. EXECUTION — la question part sur le pipeline canonique, avec la doctrine
 *     ACTIVE (ce qu'un membre recevrait), le credential de l'Organization, et
 *     aucun second appel provider.
 *  C. SOURCES — utilisee, refusee, et le troisieme etat que le builder ne
 *     comptabilise nulle part : autorisee, consultee, restee vide.
 *  D. TELEMETRIE — relue sur le ledger canonique. Un compteur non rapporte
 *     s'affiche « — », jamais 0.
 *  E. INNOCUITE — aucun secret rendu, aucune ecriture metier.
 *  F. NON-REGRESSION — le bac a sable de doctrine (T1227) compose TOUJOURS
 *     sans la doctrine active quand son brouillon est vide.
 */
class TASK1533AiContextInspectorTest extends TestCase
{
    use RefreshDatabase;

    private const DOCTRINE = 'Tutoyer les membres. SENTINELLE-DOCTRINE-1533';

    private const API_KEY = 'sk-task1533-secret-credential';

    private Organization $organization;

    private Organization $otherOrganization;

    private User $admin;

    private User $member;

    private User $otherAdmin;

    private Task1533FakeSearch $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->otherOrganization = Organization::factory()->create();

        foreach ([$this->organization, $this->otherOrganization] as $organization) {
            OrganizationAiSetting::factory()->create([
                'organization_id' => $organization->id,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'api_key' => self::API_KEY.'-'.$organization->id,
                'monthly_budget_usd' => 5.00,
            ]);
        }

        $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->organization->update(['admin_id' => $this->admin->id]);
        $this->member = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->otherAdmin = User::factory()->create(['organization_id' => $this->otherOrganization->id]);
        $this->otherOrganization->update(['admin_id' => $this->otherAdmin->id]);

        app()->instance('current_organization', $this->organization);

        AiConfig::set('default_provider', 'openai');
        AiConfig::set('default_model', 'gpt-4o-mini');
        AiConfig::set('clarification_enabled', true);

        // T1530 : la CI n'a pas de `.env`. Les drapeaux dont depend ce chemin
        // se posent donc ICI, jamais par heritage de configuration locale.
        config([
            'ai.clarify.enabled' => true,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai.default_for_embeddings' => 'openai',
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id, $this->otherOrganization->id],
            'ai_pricing.overrides' => [],
        ]);

        $this->search = new Task1533FakeSearch;
        $this->app->instance(DossierSemanticSearchService::class, $this->search);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Acces et perimetre
    // =====================================================================

    public function test_an_organization_admin_reaches_the_inspector(): void
    {
        $page = $this->actingAs($this->admin)->get($this->url());

        $page->assertOk();
        $page->assertSee('data-inspector-form', false);
        $page->assertSee(__('ai.inspector_title'));
        // Seules les capabilities REELLEMENT executables sont proposees.
        $page->assertSee(__('ai.capability_label.loop_knowledge_answer'));
        $page->assertSee(__('ai.capability_label.clarify_help_request'));
        $page->assertDontSee(__('ai.capability_label.loop_summary'));
    }

    public function test_an_ordinary_member_is_refused_on_both_verbs(): void
    {
        $this->actingAs($this->member)->get($this->url())->assertForbidden();
        $this->actingAs($this->member)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ])->assertForbidden();

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_an_admin_of_another_organization_is_refused(): void
    {
        $this->actingAs($this->otherAdmin)->get($this->url())->assertForbidden();
        $this->actingAs($this->otherAdmin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ])->assertForbidden();

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    public function test_a_platform_admin_uses_the_same_organization_scoped_surface(): void
    {
        $platformAdmin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->otherOrganization->id,
        ]);

        $this->actingAs($platformAdmin)->get($this->url())->assertOk();
    }

    public function test_the_organization_comes_from_the_route_and_a_result_never_renders_under_another(): void
    {
        $this->fakeClarifier();
        $platformAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->organization->id]);

        $this->actingAs($platformAdmin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ])->assertRedirect($this->url());

        // Le tour a ete comptabilise pour l'Organization DE LA ROUTE.
        $invocation = AiProviderInvocation::query()->where('operation', AiProviderInvocation::OPERATION_GENERATION)->sole();
        $this->assertSame($this->organization->id, $invocation->organization_id);

        // Et il ne se rend nulle part ailleurs, meme pour le meme utilisateur
        // dans le meme navigateur.
        $elsewhere = $this->actingAs($platformAdmin)->get($this->url('', $this->otherOrganization));
        $elsewhere->assertOk();
        $elsewhere->assertDontSee('data-inspector-result', false);
        $elsewhere->assertDontSee('Cadrer nos usages');
    }

    // =====================================================================
    // B. Execution sur le pipeline reel
    // =====================================================================

    public function test_the_question_runs_the_real_pipeline_under_the_active_doctrine(): void
    {
        OrganizationAiDoctrine::activate($this->organization, self::DOCTRINE, $this->admin);
        $this->fakeClarifier();

        $loopsBefore = Loop::query()->count();

        $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ])->assertRedirect($this->url());

        // La doctrine ACTIVE est bien celle qui a guide la reponse — c'est ce
        // qu'un membre aurait recu, et non un brouillon.
        HelpRequestClarifierAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = (string) $prompt->agent->instructions();
            $this->assertStringContainsString('Constitution BouclePro IA', $instructions);
            $this->assertStringContainsString("Doctrine de l'Organization — v1", $instructions);
            $this->assertStringContainsString('SENTINELLE-DOCTRINE-1533', $instructions);
            $this->assertStringNotContainsString('brouillon (non publié)', $instructions);
            // Credential de l'Organization, resolu par l'autorite existante.
            $this->assertSame('org:'.$this->organization->id.':openai', $prompt->provider->name());

            return true;
        });

        $page = $this->actingAs($this->admin)->get($this->url());
        $page->assertOk();
        $page->assertSee('data-inspector-status="answered"', false);
        $page->assertSee('data-inspector-doctrine="active"', false);
        $page->assertSee(__('ai.inspector_doctrine_active'));
        $page->assertSee(Constitution::VERSION);
        $page->assertSee('Cadrer nos usages', false);

        // Aucune ecriture metier : la question n'a rien cree.
        $this->assertSame($loopsBefore, Loop::query()->count());
        $this->assertSame(0, ServiceRequest::query()->count());
        $this->assertSame(0, LoopMessage::query()->count());
        // La doctrine active n'a pas bouge : l'Inspector ne regle rien.
        $this->assertSame(1, OrganizationAiDoctrine::query()->count());
    }

    public function test_the_turn_is_identifiable_as_an_inspector_run_in_the_ledger(): void
    {
        $this->fakeClarifier();

        $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ])->assertRedirect($this->url());

        $interaction = AiInteraction::query()->sole();
        $this->assertSame(OrganizationDoctrineSandbox::FEATURE, $interaction->feature);
        $this->assertTrue($interaction->metadata['sandbox']);
        $this->assertTrue($interaction->metadata['inspector']);

        // Le bac a sable de doctrine, lui, ne se nomme PAS Inspector.
        AiInteraction::query()->delete();
        app(OrganizationDoctrineSandbox::class)->run(
            $this->organization, $this->admin, CapabilityRegistry::CLARIFY_HELP_REQUEST, 'brouillon', 'jai besoin daide',
        );
        $this->assertArrayNotHasKey('inspector', AiInteraction::query()->sole()->metadata);
    }

    public function test_the_inspector_opens_no_second_provider_path(): void
    {
        // Le compteur est pris AU PLUS PRES du fournisseur : un second chemin
        // d'appel qui n'ecrirait rien au ledger resterait invisible aux
        // comptages de lignes ci-dessous, mais pas ici.
        $calls = 0;
        HelpRequestClarifierAgent::fake(function () use (&$calls) {
            $calls++;

            return $this->clarifierResponse();
        });

        $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ])->assertRedirect($this->url());

        $this->assertSame(1, $calls, 'Le tour a sollicite le fournisseur plus d’une fois.');

        // UN tour = UNE generation, portee par la primitive existante et son
        // credential d'Organization. Un second chemin d'appel — agent invoque
        // en direct, resolver reimplemente — se verrait ici : soit une ligne
        // de plus, soit une ligne qui ne porte pas cette `feature`.
        $generations = AiProviderInvocation::query()
            ->where('operation', AiProviderInvocation::OPERATION_GENERATION)
            ->get();
        $this->assertCount(1, $generations);
        $this->assertSame(AiProviderInvocation::CREDENTIAL_ORGANIZATION, $generations->first()->credential_source);

        $interactions = AiInteraction::query()->get();
        $this->assertCount(1, $interactions);
        $this->assertSame(OrganizationDoctrineSandbox::FEATURE, $interactions->first()->feature);

    }

    public function test_the_endpoint_validates_its_inputs_before_calling_anything(): void
    {
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('The SDK must not be called.');
        });

        $this->actingAs($this->admin)->from($this->url())
            ->post($this->url('/'), ['capability' => CapabilityRegistry::LOOP_SUMMARY, 'question' => 'question ?'])
            ->assertSessionHasErrors('capability');
        $this->actingAs($this->admin)->from($this->url())
            ->post($this->url('/'), ['capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST, 'question' => ''])
            ->assertSessionHasErrors('question');

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // C. Sources : utilisee, vide, refusee
    // =====================================================================

    public function test_used_and_empty_sources_are_both_shown_for_what_they_are(): void
    {
        $dossier = $this->dossier();
        $this->search->rows = [$this->row($dossier)];
        LoopKnowledgeAgent::fake([new TextResponse('Reponse [S1].', new Usage(30, 12), new Meta('openai', 'gpt-4o-mini'))]);

        $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            'question' => 'Que contient la valise ?',
        ])->assertRedirect($this->url());

        $page = $this->actingAs($this->admin)->get($this->url());
        $page->assertOk();
        $page->assertSee('data-inspector-source="'.DossierRetrievalSource::NAME.'" data-inspector-source-state="used"', false);
        $page->assertSee(__('ai.inspector_source_used'));

        // Le manifeste etait AUTORISE et n'a rien rendu : ni utilise, ni
        // refuse. Le builder ne le comptabilise nulle part — sans ce troisieme
        // etat, il disparaitrait de l'ecran, et « rien a dire » se lirait
        // comme « jamais consulte ».
        $page->assertSee('data-inspector-source="'.DossierManifestSource::NAME.'" data-inspector-source-state="empty"', false);
        $page->assertSee(__('ai.inspector_source_empty'));
    }

    public function test_a_denied_source_is_named_by_its_reason_and_leaks_nothing(): void
    {
        $dossier = $this->dossier();
        $this->search->rows = [$this->row($dossier)];
        // La recherche documentaire est coupee pour cette Organization : la
        // source est REFUSEE, pas vide.
        config(['ai.dossiers.semantic_search.enabled' => false]);
        LoopKnowledgeAgent::fake(function (): never {
            throw new \RuntimeException('The SDK must not be called without sources.');
        });

        $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            'question' => 'Que contient la valise ?',
        ])->assertRedirect($this->url());

        $page = $this->actingAs($this->admin)->get($this->url());
        $page->assertOk();
        $page->assertSee('data-inspector-source="'.DossierRetrievalSource::NAME.'" data-inspector-source-state="denied"', false);
        $page->assertSee(__('ai.behavior_sandbox_source_denied.semantic_search_disabled'));

        // Un refus dit POURQUOI, jamais QUOI : ni le nom du Dossier, ni le
        // contenu que la source aurait pu rendre.
        $page->assertDontSee($dossier->name);
        $page->assertDontSee('la valise contient le materiel itinerant');
    }

    // =====================================================================
    // D. Telemetrie — relue, jamais recalculee
    // =====================================================================

    public function test_the_telemetry_is_read_from_the_canonical_provider_ledger(): void
    {
        $this->fakeClarifier();

        $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ])->assertRedirect($this->url());

        $invocation = AiProviderInvocation::query()->sole();
        $invocation->forceFill([
            'input_tokens' => 120,
            'output_tokens' => 80,
            'provider_cost' => '0.00042000',
            'cost_status' => AiProviderInvocation::COST_KNOWN,
            'currency' => 'USD',
            'started_at' => now()->subSeconds(9),
            'completed_at' => now(),
        ])->save();

        $page = $this->actingAs($this->admin)->get($this->url());
        $page->assertOk();
        $page->assertSee('data-inspector-invocation="'.AiProviderInvocation::OPERATION_GENERATION.'"', false);
        $page->assertSee('data-inspector-input-tokens="120"', false);
        $page->assertSee('data-inspector-output-tokens="80"', false);
        $page->assertSee('data-inspector-cost-status="'.AiProviderInvocation::COST_KNOWN.'"', false);
        // Le montant rendu est CELUI du ledger, a sa precision — l'ecran ne le
        // reformate pas, et surtout ne le recalcule pas.
        $page->assertSee($invocation->fresh()->provider_cost.' USD');
        $page->assertSee('gpt-4o-mini');
        $page->assertSee('openai');

        // La latence est rendue A LA PRECISION DU LEDGER. Ces deux colonnes
        // s'horodatent a la seconde : la calculer en millisecondes affichait
        // « 9000 ms », une precision au millier pres jamais mesuree.
        $page->assertSee('data-inspector-latency="9"', false);
        $page->assertSee('9 s');
        $page->assertDontSee('9000 ms');
    }

    public function test_a_counter_the_provider_never_reported_is_shown_as_unavailable_not_zero(): void
    {
        $this->fakeClarifier();

        $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ])->assertRedirect($this->url());

        $invocation = AiProviderInvocation::query()->sole();
        $invocation->forceFill([
            'input_tokens' => null,
            'output_tokens' => null,
            'total_tokens' => null,
            'provider_cost' => null,
            'cost_status' => AiProviderInvocation::COST_UNKNOWN,
            'completed_at' => null,
        ])->save();

        $page = $this->actingAs($this->admin)->get($this->url());
        $page->assertOk();
        // Les attributs d'ancrage restent VIDES : rien n'a ete mesure, et
        // l'ecran ne comble pas le trou avec un 0 qui serait faux.
        $page->assertSee('data-inspector-input-tokens=""', false);
        $page->assertSee('data-inspector-output-tokens=""', false);
        $page->assertSee('data-inspector-latency=""', false);
        $page->assertSee('data-inspector-cost-status="'.AiProviderInvocation::COST_UNKNOWN.'"', false);
    }

    // =====================================================================
    // E. Innocuite
    // =====================================================================

    public function test_no_credential_reaches_the_dto_or_the_html(): void
    {
        $this->fakeClarifier();

        $result = app(OrganizationDoctrineSandbox::class)->run(
            $this->organization, $this->admin, CapabilityRegistry::CLARIFY_HELP_REQUEST, '', 'jai besoin daide', null,
            asInspector: true,
        );

        $this->assertSame(DoctrineSandboxResult::STATUS_ANSWERED, $result->status);
        $this->assertStringNotContainsString(self::API_KEY, json_encode($result->toArray()));

        session()->flash('context_inspector', $result->toArray());
        $page = $this->actingAs($this->admin)->get($this->url());
        $page->assertOk();
        $page->assertDontSee(self::API_KEY);
        $page->assertDontSee('platform-key');
        // Le prompt compose n'est pas rendu non plus : expliquer, jamais
        // donner de quoi rejouer.
        $page->assertDontSee('Instructions capability');
    }

    // =====================================================================
    // F. Points d'entree et non-regression
    // =====================================================================

    public function test_the_cockpit_and_the_navigation_open_the_inspector(): void
    {
        $cockpit = $this->actingAs($this->admin)->get(route('organization.admin.ai-cockpit', ['organization' => $this->organization->slug]));

        $cockpit->assertOk();
        $cockpit->assertSee('data-cockpit-inspector-open', false);
        $cockpit->assertSee($this->url(), false);
        // La barre laterale y mene aussi — sans elle, la page ne serait
        // atteignable que par son URL.
        $cockpit->assertSee(__('navigation.org_admin_ai_context_inspector'));
    }

    public function test_the_doctrine_sandbox_still_composes_without_the_active_doctrine(): void
    {
        // NON-REGRESSION T1227 : le bac a sable repond a « que ferait CE texte
        // avant publication ? ». Un brouillon vide y veut dire SANS doctrine —
        // meme lorsqu'une doctrine active existe. Si l'extension T1533 fuyait
        // dans ce chemin, l'essai montrerait un prompt qui n'est pas celui
        // qu'on lui demande d'evaluer.
        OrganizationAiDoctrine::activate($this->organization, self::DOCTRINE, $this->admin);
        $this->fakeClarifier();

        $result = app(OrganizationDoctrineSandbox::class)->run(
            $this->organization, $this->admin, CapabilityRegistry::CLARIFY_HELP_REQUEST, '', 'jai besoin daide',
        );

        $this->assertNull($result->doctrineLabel);
        HelpRequestClarifierAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $this->assertStringNotContainsString('SENTINELLE-DOCTRINE-1533', (string) $prompt->agent->instructions());

            return true;
        });
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function url(string $suffix = '', ?Organization $organization = null): string
    {
        $organization ??= $this->organization;

        return rtrim(route('organization.admin.ai-context-inspector', ['organization' => $organization->slug]).$suffix, '/');
    }

    private function dossier(): Dossier
    {
        return Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->admin->id,
            'name' => 'Dossier SENTINELLE-1533-'.Str::random(4),
            'visibility' => Dossier::VISIBILITY_ORGANIZATION,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Dossier $dossier): array
    {
        return [
            'chunk_id' => (string) Str::uuid(),
            'dossier_id' => $dossier->id,
            'dossier_name' => $dossier->name,
            'blog_post_id' => (string) Str::uuid(),
            'title' => 'Article A',
            'slug' => 'article-a',
            'dossier_file_id' => null,
            'filename' => null,
            'chunk_index' => 0,
            'source_type' => 'article',
            'content' => 'la valise contient le materiel itinerant',
            'distance' => 0.2,
        ];
    }

    private function fakeClarifier(): void
    {
        HelpRequestClarifierAgent::fake([$this->clarifierResponse()]);
    }

    private function clarifierResponse(): StructuredTextResponse
    {
        $structured = [
            'title' => 'Cadrer nos usages',
            'clarified_request' => 'Je cherche de l’aide pour cadrer nos usages.',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];

        return new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_UNESCAPED_UNICODE),
            new Usage(120, 80),
            new Meta('openai', 'gpt-4o-mini'),
        );
    }
}

/**
 * Double du moteur pgvector : lignes canoniques, perimetre exact demande.
 */
class Task1533FakeSearch extends DossierSemanticSearchService
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var array<string, mixed>|null */
    public ?array $lastCall = null;

    public function __construct() {}

    public function searchAcrossDossiers(string $organizationId, array $dossierIds, string $query, string $embeddingInstance, int $limit = 5, array $traceMetadata = [], ?int $candidateLimit = null, ?array $onlyDossierFileIds = null): array
    {
        $this->lastCall = compact('organizationId', 'dossierIds', 'query', 'embeddingInstance', 'limit');

        return array_slice($this->rows, 0, $candidateLimit ?? $limit);
    }
}
