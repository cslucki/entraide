<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Constitution;
use App\Ai\Context\ContextBuilder;
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
use App\Support\Ai\AiCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1533 — AI Context Inspector.
 *
 * L'ecran repond a « pourquoi BouclePro a-t-il produit CETTE reponse ? » en
 * observant le pipeline REEL. Ce que cette suite doit prouver n'est donc pas
 * qu'une page s'affiche, mais que ce qu'elle affiche est VRAI :
 *
 *  A. ACCES — admin autorise, membre refuse, admin d'une autre Organization
 *     refuse, SuperAdmin sur la MEME surface ; l'Organization vient de la ROUTE.
 *  B. CARTE DE CONTEXTE — peuplee AVANT toute question, et derivee des
 *     primitives reelles : aucune source qui ne soit declaree par le registre.
 *  C. EXECUTION — la question part sur le pipeline canonique, avec la doctrine
 *     ACTIVE, le credential de l'Organization, et aucun second appel provider.
 *     Le tour repond INLINE : rien ne transite par la session.
 *  D. SOURCES — utilisee, refusee, et le troisieme etat que le builder ne
 *     comptabilise nulle part : autorisee, consultee, restee vide. La
 *     provenance accompagne les sources UTILISEES, jamais une refusee.
 *  E. TELEMETRIE — relue sur le ledger canonique. Un compteur non rapporte
 *     s'affiche « — », jamais 0.
 *  F. INNOCUITE — aucun secret rendu, aucune ecriture metier, aucun surclassement
 *     verbal du mode ISOLATED en parcours membre complet.
 *  G. NON-REGRESSION — le bac a sable de doctrine (T1227) compose TOUJOURS
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
        // Seules les capabilities REELLEMENT executables sont proposees, et
        // elles le sont en controle segmente : jamais un menu deroulant, que le
        // CDC ecarte comme interaction centrale.
        $page->assertSee('data-inspector-capability-control', false);
        $page->assertSee('data-inspector-capability-option="'.CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER.'"', false);
        $page->assertSee('data-inspector-capability-option="'.CapabilityRegistry::CLARIFY_HELP_REQUEST.'"', false);
        $page->assertDontSee('data-inspector-capability-option="'.CapabilityRegistry::LOOP_SUMMARY.'"', false);
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

        $page = $this->actingAs($platformAdmin)->get($this->url());

        $page->assertOk();
        // Le MEME composant, pas un second moteur plateforme : memes ancres,
        // meme carte, meme controle de fonction.
        $page->assertSee('data-inspector-context-map', false);
        $page->assertSee('data-inspector-capability-control', false);
        $page->assertSee('data-inspector-form', false);
    }

    public function test_the_run_is_accounted_to_the_organization_of_the_route_and_leaves_nothing_behind(): void
    {
        $this->fakeClarifier();
        $platformAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->organization->id]);

        $run = $this->actingAs($platformAdmin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders());

        $run->assertOk();
        $run->assertSee('Cadrer nos usages', false);

        // Le tour a ete comptabilise pour l'Organization DE LA ROUTE.
        $invocation = AiProviderInvocation::query()->where('operation', AiProviderInvocation::OPERATION_GENERATION)->sole();
        $this->assertSame($this->organization->id, $invocation->organization_id);

        // Et il ne survit a AUCUNE requete suivante : le resultat ne vit que
        // dans la reponse HTTP qui l'a produit. Le cycle redirect/flash
        // precedent devait se proteger d'un rendu sous une autre Organization ;
        // ici ce support n'existe plus.
        $this->assertNull(session('context_inspector'));

        foreach ([$this->organization, $this->otherOrganization] as $organization) {
            $page = $this->actingAs($platformAdmin)->get($this->url('', $organization));
            $page->assertOk();
            // Les ancres que SEUL le partiel de tour porte : leur presence
            // signifierait qu'un resultat s'est rendu sur un GET.
            $page->assertDontSee('data-inspector-status="', false);
            $page->assertDontSee('data-inspector-run-state="', false);
            $page->assertDontSee('Cadrer nos usages');
        }
    }

    // =====================================================================
    // B. Carte de contexte — peuplee AVANT toute question
    // =====================================================================

    public function test_the_context_map_is_populated_before_any_run(): void
    {
        $page = $this->actingAs($this->admin)->get($this->url());

        $page->assertOk();
        $page->assertSee('data-inspector-context-map', false);

        // GOUVERNANCE : les quatre autorites qui dictent une reponse, et rien
        // de ce qui decrit l'execution ou la depense.
        foreach (['platform_constitution', 'organization_constitution', 'doctrine', 'capabilities'] as $node) {
            $page->assertSee('data-inspector-map-node="'.$node.'"', false);
        }
        $page->assertDontSee('data-inspector-map-node="provider"', false);
        $page->assertDontSee('data-inspector-map-node="consumption"', false);

        // Mycelium est la Constitution IA PLATEFORME, jamais une memoire
        // collective — regle canonique du MASTER.
        $page->assertSee(__('ai.inspector_map_node.platform_constitution'));
        $this->assertStringContainsString('plateforme', __('ai.inspector_map_node.platform_constitution'));
        // Et elle est VERROUILLEE pour cet Admin : l'etat vient de
        // NervousSystemMap, qui le derive de l'absence de route d'ecriture.
        $page->assertSee('data-inspector-map-node="platform_constitution" data-inspector-map-state="locked"', false);

        // Les briques absentes sont NOMMEES, jamais actives.
        foreach (['memory_compiler', 'entity_resolution', 'claim_verifier'] as $deferred) {
            $page->assertSee('data-inspector-map-deferred="'.$deferred.'"', false);
        }
        // Et rien qui existe reellement ne figure parmi les absents : la mise
        // en relation humaine (EligiblePeopleService) existe, l'annoncer
        // manquante serait un faux manque.
        $page->assertDontSee('data-inspector-map-deferred="people_matching"', false);

        // Aucun controle mort : les modes non implementes ne sont pas offerts.
        $page->assertDontSee('FULL SHELL', false);
        $page->assertDontSee('ABLATION', false);
        $page->assertSee('ISOLATED', false);
    }

    public function test_the_map_shows_exactly_the_sources_the_registry_declares(): void
    {
        // Le jeu attendu est DERIVE du registre canonique. Injecter une source
        // inexistante dans le view-model de la carte fait rougir ce test, et
        // en retirer une aussi — c'est la garde contre la carte fictive.
        $expected = [];
        foreach (OrganizationDoctrineSandbox::SUPPORTED as $capability) {
            foreach (app(CapabilityRegistry::class)->get($capability)->allowedSources as $source) {
                $expected[$source] = true;
            }
        }
        $expected = array_keys($expected);
        sort($expected);

        $html = $this->actingAs($this->admin)->get($this->url())->assertOk()->getContent();

        preg_match_all('/data-inspector-map-source="([^"]+)"/', $html, $matches);
        $rendered = array_values(array_unique($matches[1]));
        sort($rendered);

        $this->assertSame($expected, $rendered);
        $this->assertNotSame([], $expected, 'La carte doit montrer au moins une source.');

        // Et chacune est declaree implementee ou non d'apres ce que le builder
        // sait REELLEMENT produire, jamais d'apres une liste ecrite a la main.
        $available = app(ContextBuilder::class)->availableSources();
        foreach ($expected as $source) {
            $this->assertContains($source, $available, "La carte annonce une source que le builder ne produit pas : {$source}.");
        }
    }

    public function test_both_capability_source_sets_travel_in_the_initial_html(): void
    {
        // Le changement de fonction re-eclaire la carte SANS requete : les deux
        // jeux doivent donc etre presents des le chargement, et distincts.
        $registry = app(CapabilityRegistry::class);
        $knowledge = $registry->get(CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER)->allowedSources;
        $clarify = $registry->get(CapabilityRegistry::CLARIFY_HELP_REQUEST)->allowedSources;

        $this->assertNotSame($knowledge, $clarify, 'Les deux fonctions doivent declarer des sources differentes.');

        $html = $this->actingAs($this->admin)->get($this->url())->assertOk()->getContent();

        $this->assertSame(1, preg_match('/data-inspector-capability-sources="([^"]+)"/', $html, $matches));
        $payload = json_decode(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'), true);

        $this->assertSame($knowledge, $payload[CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER] ?? null);
        $this->assertSame($clarify, $payload[CapabilityRegistry::CLARIFY_HELP_REQUEST] ?? null);
        // Rien d'autre : la carte ne connait que les fonctions executables ici.
        $this->assertSame(OrganizationDoctrineSandbox::SUPPORTED, array_keys($payload));
    }

    // =====================================================================
    // C. Execution sur le pipeline reel, rendue inline
    // =====================================================================

    public function test_the_question_runs_the_real_pipeline_under_the_active_doctrine(): void
    {
        OrganizationAiDoctrine::activate($this->organization, self::DOCTRINE, $this->admin);
        $this->fakeClarifier();

        $loopsBefore = Loop::query()->count();

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders());

        $run->assertOk();

        // La doctrine ACTIVE est bien celle qui a guide la reponse, et non un
        // brouillon : c'est la composition du chemin de production.
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

        $run->assertSee('data-inspector-run-state="success"', false);
        $run->assertSee('data-inspector-doctrine="active"', false);
        $run->assertSee(__('ai.inspector_doctrine_active'));
        $run->assertSee(Constitution::VERSION);
        $run->assertSee('Cadrer nos usages', false);

        // Aucune ecriture metier : la question n'a rien cree.
        $this->assertSame($loopsBefore, Loop::query()->count());
        $this->assertSame(0, ServiceRequest::query()->count());
        $this->assertSame(0, LoopMessage::query()->count());
        // La doctrine active n'a pas bouge : l'Inspector ne regle rien.
        $this->assertSame(1, OrganizationAiDoctrine::query()->count());
    }

    public function test_the_post_answers_inline_with_a_server_marker_and_no_store(): void
    {
        $this->fakeClarifier();

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders());

        // 200 inline, jamais une redirection : c'est ce qui permet a la
        // provenance de ne jamais transiter par la session.
        $run->assertOk();
        $this->assertStringContainsString('text/html', (string) $run->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $run->headers->get('Cache-Control'));

        // Le marqueur serveur : sans lui, le client refuse d'injecter la
        // reponse (meme garde qu'ai-knowledge contre une page de login suivie).
        $run->assertSee('data-inspector-generated-at=', false);

        // Les quatre panneaux du tour voyagent ensemble : une trace et une
        // telemetrie obtenues par deux requetes pourraient decrire deux tours.
        foreach (['answer', 'trace', 'sources', 'telemetry'] as $pane) {
            $run->assertSee('data-inspector-pane="'.$pane.'"', false);
        }
        foreach (['composition', 'context', 'provider', 'issue'] as $step) {
            $run->assertSee('data-inspector-step="'.$step.'"', false);
        }
    }

    public function test_the_turn_is_identifiable_as_an_inspector_run_in_the_ledger(): void
    {
        $this->fakeClarifier();

        $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders())->assertOk();

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
        ], $this->fetchHeaders())->assertOk();

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

        // Une saisie invalide n'est pas un tour rate : rien n'a couru, donc
        // l'ecran reste dans son etat et seuls les champs parlent. 422, pas de
        // redirection, pas d'etat ERROR.
        $this->actingAs($this->admin)
            ->post($this->url('/'), ['capability' => CapabilityRegistry::LOOP_SUMMARY, 'question' => 'question ?'], $this->fetchHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('capability');

        $this->actingAs($this->admin)
            ->post($this->url('/'), ['capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST, 'question' => ''], $this->fetchHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');

        $this->assertSame(0, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // D. Sources : utilisee, vide, refusee — et la provenance
    // =====================================================================

    public function test_used_and_empty_sources_are_both_shown_for_what_they_are(): void
    {
        $dossier = $this->dossier();
        $this->search->rows = [$this->row($dossier)];
        LoopKnowledgeAgent::fake([new TextResponse('Reponse [S1].', new Usage(30, 12), new Meta('openai', 'gpt-4o-mini'))]);

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            'question' => 'Que contient la valise ?',
        ], $this->fetchHeaders());

        $run->assertOk();
        $run->assertSee('data-inspector-source="'.DossierRetrievalSource::NAME.'" data-inspector-source-state="used"', false);
        $run->assertSee(__('ai.inspector_source_used'));

        // Le manifeste etait AUTORISE et n'a rien rendu : ni utilise, ni
        // refuse. Le builder ne le comptabilise nulle part — sans ce troisieme
        // etat, il disparaitrait de l'ecran, et « rien a dire » se lirait
        // comme « jamais consulte ».
        $run->assertSee('data-inspector-source="'.DossierManifestSource::NAME.'" data-inspector-source-state="empty"', false);
        $run->assertSee(__('ai.inspector_source_empty'));

        // La carte recoit ces etats mesures — elle n'en invente aucun.
        $run->assertSee(DossierRetrievalSource::NAME.'&quot;:&quot;used', false);
        $run->assertSee(DossierManifestSource::NAME.'&quot;:&quot;empty', false);
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

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            'question' => 'Que contient la valise ?',
        ], $this->fetchHeaders());

        $run->assertOk();
        $run->assertSee('data-inspector-source="'.DossierRetrievalSource::NAME.'" data-inspector-source-state="denied"', false);
        $run->assertSee(__('ai.behavior_sandbox_source_denied.semantic_search_disabled'));

        // Un refus dit POURQUOI, jamais QUOI : ni le nom du Dossier, ni le
        // contenu que la source aurait pu rendre.
        $run->assertDontSee($dossier->name);
        $run->assertDontSee('la valise contient le materiel itinerant');

        // Et il n'ouvre RIEN : ni bloc de provenance, ni declencheur de
        // tiroir. Un tiroir vide se lirait comme « il y a quelque chose ».
        $run->assertDontSee('data-inspector-provenance', false);

        // La carte de contexte recoit le MEME etat. Sans cela, le refus pouvait
        // etre exact dans la liste des sources et s'allumer « utilisee » dans la
        // carte : deux affirmations contradictoires sur le meme tour.
        $run->assertSee(DossierRetrievalSource::NAME.'&quot;:&quot;denied', false);
        $run->assertDontSee(DossierRetrievalSource::NAME.'&quot;:&quot;used', false);
    }

    public function test_the_provenance_of_used_sources_travels_in_the_response_and_never_in_the_session(): void
    {
        $dossier = $this->dossier();
        $this->search->rows = [$this->row($dossier)];
        LoopKnowledgeAgent::fake([new TextResponse('Reponse [S1].', new Usage(30, 12), new Meta('openai', 'gpt-4o-mini'))]);

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
            'question' => 'Que contient la valise ?',
        ], $this->fetchHeaders());

        $run->assertOk();
        // La preuve de ce que la source a transmis, attachee a la source
        // UTILISEE, et disponible sans seconde requete : donc sans second
        // chemin d'acces a revalider.
        $run->assertSee('data-inspector-provenance="'.DossierRetrievalSource::NAME.'"', false);
        $run->assertSee('data-inspector-provenance-open="'.DossierRetrievalSource::NAME.'"', false);
        $run->assertSee('la valise contient le materiel itinerant');

        // Elle ne vit QUE la : du contenu documentaire du tenant n'a rien a
        // faire en session, ni dans un cache.
        $this->assertNull(session('context_inspector'));
        $this->assertStringContainsString('no-store', (string) $run->headers->get('Cache-Control'));

        $page = $this->actingAs($this->admin)->get($this->url());
        $page->assertOk();
        $page->assertDontSee('la valise contient le materiel itinerant');
    }

    /**
     * La garde PROPRE du partiel contre la provenance d'un refus.
     *
     * Le pipeline ne collecte jamais de provenance pour une source refusee : le
     * `ContextBuilder` l'ecarte AVANT toute collecte. La garantie est donc
     * STRUCTURELLE, et aucun scenario de bout en bout ne peut produire la
     * donnee interdite — un test end-to-end serait vert sans rien mesurer.
     *
     * Le partiel porte sa propre garde parce qu'une source refusee qui
     * exposerait un extrait transformerait un refus en oracle. On lui remet
     * donc ici la donnee qui ne devrait pas exister, et on verifie qu'il la
     * jette au lieu de la rendre.
     */
    public function test_the_run_partial_never_renders_the_provenance_of_a_denied_source(): void
    {
        $html = view('admin.org.partials.ai-context-inspector-run', [
            'organization' => $this->organization,
            'result' => [
                'status' => 'answered',
                'capability' => CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER,
                'answer' => 'Une reponse.',
                'sources_used' => [DossierManifestSource::NAME],
                'sources_denied' => [DossierRetrievalSource::NAME => 'semantic_search_disabled'],
                'constitution_version' => 'v1',
                'doctrine_label' => null,
                'ledger_entries' => 0,
                'correlation_id' => null,
                'provenance' => [
                    ['source' => DossierRetrievalSource::NAME, 'type' => 'retrieval', 'id' => 'chunk-interdit-1533', 'extrait' => 'SENTINELLE-REFUS-1533'],
                    ['source' => DossierManifestSource::NAME, 'type' => 'manifest', 'id' => 'chunk-autorise', 'extrait' => 'SENTINELLE-AUTORISEE-1533'],
                ],
            ],
            'allowedSources' => [DossierManifestSource::NAME, DossierRetrievalSource::NAME],
            'telemetry' => [],
            'generatedAt' => now()->toIso8601String(),
        ])->render();

        $this->assertStringNotContainsString('SENTINELLE-REFUS-1533', $html);
        $this->assertStringNotContainsString('chunk-interdit-1533', $html);
        $this->assertStringNotContainsString('data-inspector-provenance="'.DossierRetrievalSource::NAME.'"', $html);

        // La source UTILISEE garde la sienne : la garde filtre, elle ne
        // supprime pas tout — sinon elle serait vraie pour la mauvaise raison.
        $this->assertStringContainsString('SENTINELLE-AUTORISEE-1533', $html);
        $this->assertStringContainsString('data-inspector-provenance="'.DossierManifestSource::NAME.'"', $html);
    }

    public function test_a_refusal_before_the_call_says_that_nothing_reached_the_provider(): void
    {
        // Fonction coupee sur la plateforme : refus AVANT tout appel.
        config(['ai.clarify.enabled' => false]);
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('The SDK must not be called on a refusal.');
        });

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders());

        $run->assertOk();
        $run->assertSee('data-inspector-run-state="refused"', false);
        $run->assertSee('data-inspector-refusal="'.OrganizationDoctrineSandbox::REASON_FEATURE_DISABLED.'"', false);

        // Zero ligne au ledger, et l'ecran le DIT — « rien n'est parti » est
        // une mesure, pas une absence de mesure.
        $this->assertSame(0, AiProviderInvocation::query()->count());
        $run->assertSee('data-inspector-step="provider" data-inspector-step-state="not_reached"', false);
        $run->assertSee(__('ai.inspector_trace_provider_not_reached'));
        // Le contexte n'a jamais ete construit : l'etape n'est pas « vide ».
        $run->assertSee('data-inspector-step="context" data-inspector-step-state="not_reached"', false);
    }

    /**
     * « Source non demandee » n'est pas « source vide ».
     *
     * Un refus intervient avant `ContextBuilder::build()`. L'ecart « autorisee
     * moins utilisee moins refusee » vaut alors la TOTALITE des sources, et les
     * afficher vides ferait dire a l'ecran qu'elles ont ete consultees sans
     * rien trouver — pendant que sa propre trace annonce que l'etape n'a pas
     * ete atteinte. Deux affirmations contradictoires, dont une fausse.
     */
    public function test_a_refused_run_never_shows_its_sources_as_empty(): void
    {
        config(['ai.clarify.enabled' => false]);

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders());

        $run->assertOk();
        $run->assertSee('data-inspector-run-state="refused"', false);

        foreach (app(CapabilityRegistry::class)->get(CapabilityRegistry::CLARIFY_HELP_REQUEST)->allowedSources as $source) {
            $run->assertSee('data-inspector-source="'.$source.'" data-inspector-source-state="not_requested"', false);
            $run->assertDontSee('data-inspector-source="'.$source.'" data-inspector-source-state="empty"', false);
            $run->assertDontSee('data-inspector-source="'.$source.'" data-inspector-source-state="used"', false);
        }

        // La carte recoit le meme etat : elle ne peut pas contredire le tour.
        $run->assertDontSee('&quot;:&quot;empty', false);
    }

    // =====================================================================
    // E. Telemetrie — relue, jamais recalculee
    // =====================================================================

    public function test_the_telemetry_is_read_from_the_canonical_provider_ledger(): void
    {
        $this->fakeClarifier();

        // Le registre horodate `completed_at` avec l'horloge applicative et
        // `started_at` avec `microtime()`. Avancer l'horloge de 9 secondes
        // produit donc un ecart REEL de 9 secondes sur la ligne, sans la
        // retoucher apres coup.
        $this->travelTo(now()->addSeconds(9));

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders());

        $this->travelBack();

        $run->assertOk();

        $invocation = AiProviderInvocation::query()->sole();
        // Ce que l'ecran affiche est EXACTEMENT ce que le ledger a ecrit.
        $this->assertSame(120, $invocation->input_tokens);
        $this->assertSame(80, $invocation->output_tokens);

        $run->assertSee('data-inspector-invocation="'.AiProviderInvocation::OPERATION_GENERATION.'"', false);
        $run->assertSee('data-inspector-input-tokens="120"', false);
        $run->assertSee('data-inspector-output-tokens="80"', false);
        $run->assertSee('data-inspector-cost-status="'.$invocation->cost_status.'"', false);
        $run->assertSee('gpt-4o-mini');
        $run->assertSee('openai');

        // La latence est rendue A LA PRECISION DU LEDGER. Ces deux colonnes
        // s'horodatent a la seconde : la calculer en millisecondes affichait
        // « 9000 ms », une precision au millier pres jamais mesuree.
        $run->assertSee('data-inspector-latency="9"', false);
        $run->assertSee('9 s');
        $run->assertDontSee('9000 ms');
    }

    /**
     * La corrélation TRACE, elle n'AUTORISE pas.
     *
     * Lire le ledger par la seule clé de corrélation reviendrait à traiter un
     * identifiant technique comme une frontière de tenant. Rien ne garantit
     * qu'une corrélation ne soit portée que par une Organization : elle est
     * héritée par propagation asynchrone, et `AiCorrelation` le dit lui-même.
     *
     * Le seul moyen de MESURER cette borne est de placer, AVANT le tour, une
     * ligne étrangère portant la même clé — ce que l'épinglage de la
     * corrélation rend possible.
     */
    public function test_the_telemetry_never_reads_a_row_belonging_to_another_organization(): void
    {
        Context::add(AiCorrelation::CONTEXT_KEY, (string) Str::uuid());
        HelpRequestClarifierAgent::fake([$this->clarifierResponse(), $this->clarifierResponse()]);

        // Un tour REEL de l'autre Organization, sous la meme correlation.
        app(OrganizationDoctrineSandbox::class)->run(
            $this->otherOrganization, $this->otherAdmin, CapabilityRegistry::CLARIFY_HELP_REQUEST, '', 'jai besoin daide', null,
            asInspector: true,
        );

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders());

        $run->assertOk();

        // La premisse : les DEUX Organizations ont bien ecrit sous cette cle.
        $correlation = (string) Context::get(AiCorrelation::CONTEXT_KEY);
        $rows = AiProviderInvocation::query()->where('correlation_id', $correlation)->get();
        $this->assertCount(2, $rows, 'Le montage doit produire deux lignes de tenants differents sous la meme correlation.');
        $this->assertEqualsCanonicalizing(
            [$this->organization->id, $this->otherOrganization->id],
            $rows->pluck('organization_id')->all(),
        );

        // L'ecran n'en montre qu'UNE : celle de l'Organization de la route.
        $run->assertSee('data-inspector-telemetry-rows="1"', false);
        $this->assertSame(1, substr_count($run->getContent(), 'data-inspector-invocation="'));
    }

    public function test_a_counter_the_provider_never_reported_is_shown_as_unavailable_not_zero(): void
    {
        // Un echec fournisseur produit un usage REELLEMENT non observe : le
        // ledger ecrit NULL, la ou `ai_interactions` ecrit 0. Les deux lignes
        // existent pour ce meme tour — c'est le cas exact ou lire la mauvaise
        // autorite fabriquerait une mesure.
        HelpRequestClarifierAgent::fake(function (): never {
            throw new \RuntimeException('provider down');
        });

        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders());

        $run->assertOk();

        $invocation = AiProviderInvocation::query()->sole();
        $this->assertNull($invocation->input_tokens);
        $this->assertNull($invocation->output_tokens);
        // L'autre autorite, elle, a bien ecrit un zero : c'est ce zero qui ne
        // doit jamais atteindre l'ecran.
        $this->assertSame(0, AiInteraction::query()->sole()->input_tokens);

        // Les attributs d'ancrage restent VIDES : rien n'a ete mesure, et
        // l'ecran ne comble pas le trou avec un 0 qui serait faux.
        $run->assertSee('data-inspector-input-tokens=""', false);
        $run->assertSee('data-inspector-output-tokens=""', false);
        $run->assertSee('data-inspector-cost-status="'.AiProviderInvocation::COST_UNKNOWN.'"', false);
        $run->assertDontSee('data-inspector-input-tokens="0"', false);
        $run->assertDontSee('data-inspector-output-tokens="0"', false);

        // Et le tour est une ERREUR, pas une reussite muette.
        $run->assertSee('data-inspector-run-state="error"', false);
    }

    // =====================================================================
    // F. Innocuite
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

        $this->fakeClarifier();
        $run = $this->actingAs($this->admin)->post($this->url('/'), [
            'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
            'question' => 'jai besoin daide pour un atelier',
        ], $this->fetchHeaders());

        $run->assertOk();
        $run->assertDontSee(self::API_KEY);
        $run->assertDontSee('platform-key');
        // Le prompt compose n'est pas rendu non plus : expliquer, jamais
        // donner de quoi rejouer.
        $run->assertDontSee('Instructions capability');

        $this->actingAs($this->admin)->get($this->url())->assertOk()->assertDontSee(self::API_KEY);
    }

    /**
     * L'Inspector execute UNE fonction isolee. Il ne rejoue pas le parcours
     * complet d'un membre (routage du Shell, contexte de page, conversation),
     * et l'ecran n'a pas le droit de le laisser croire : un ecran de diagnostic
     * qui surestime ce qu'il montre est pire qu'un ecran absent.
     *
     * La regle se MESURE sur une liste de formulations, pas sur une regex
     * molle qui devinerait des synonymes.
     */
    public function test_the_screen_never_claims_to_reproduce_a_member_journey(): void
    {
        $banned = [
            'fr' => [
                'comme pour un membre',
                'ce qu\'un membre recevrait',
                'ce que recevrait un membre',
                'ce qu\'un membre recevrait',
                'identique au Shell',
                'comme le Shell',
                'parcours complet',
            ],
            'en' => [
                'as a member would',
                'what a member would receive',
                'same as the member shell',
                'identical to the Shell',
                'full member journey',
            ],
        ];

        foreach ($banned as $locale => $formulations) {
            $this->app->setLocale($locale);

            $this->fakeClarifier();
            $run = $this->actingAs($this->admin)->post($this->url('/'), [
                'capability' => CapabilityRegistry::CLARIFY_HELP_REQUEST,
                'question' => 'jai besoin daide pour un atelier',
            ], $this->fetchHeaders());

            $page = $this->actingAs($this->admin)->get($this->url());

            foreach ([$page->assertOk(), $run->assertOk()] as $response) {
                foreach ($formulations as $formulation) {
                    $response->assertDontSee($formulation);
                }
            }

            // A l'inverse, le mode reellement execute est NOMME.
            $page->assertSee('ISOLATED', false);
            $page->assertSee('data-inspector-mode="isolated"', false);

            AiInteraction::query()->delete();
            AiProviderInvocation::query()->delete();
        }
    }

    // =====================================================================
    // G. Points d'entree et non-regression
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

    /**
     * Les entetes du client reel : JSON pour les erreurs de validation, HTML
     * pour le tour. C'est cette negociation qui fait repondre Laravel en 422
     * plutot qu'en redirection.
     *
     * @return array<string, string>
     */
    private function fetchHeaders(): array
    {
        return [
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json, text/html;q=0.9',
        ];
    }

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
