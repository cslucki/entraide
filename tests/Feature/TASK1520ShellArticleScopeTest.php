<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\Agents\LoopKnowledgeAgent;
use App\Ai\CapabilityRegistry;
use App\Models\AiShellMessage;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Services\Dossiers\DossierSemanticSearchService;
use App\Services\LoopService;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1520 — l'ARTICLE courant devient lisible par le moteur documentaire.
 *
 * Le patron de TASK-1519 est REPLIQUE, pas copie : branche pre-provider,
 * conditionnee au `PageContext`, policy rejouee, delegation au moteur existant.
 *
 * Ce qui differe : un Article n'a pas de corpus a fouiller, il EST le document.
 * Aucune recherche vectorielle, aucun embedding — le texte deja autorise
 * devient l'unique source.
 *
 * Ce que ces tests gardent avant tout : la garde des Articles PRIVES de Boucle.
 * Un manifeste de Boucle privee ne doit pas devenir lisible parce qu'un Shell
 * s'est ouvert dessus.
 */
class TASK1520ShellArticleScopeTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteur;

    private User $etranger;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->etranger = User::factory()->create(['organization_id' => $this->organization->id]);

        app()->instance('current_organization', $this->organization);

        $this->dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->auteur->id,
            'name' => 'Dossier de rattachement',
            'visibility' => 'organization',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-1520',
        ]);

        config([
            'ai.default_for_embeddings' => 'openrouter',
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
    }

    private function article(string $contenu, string $statut = 'published', ?User $auteur = null): BlogPost
    {
        $post = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => ($auteur ?? $this->auteur)->id,
            'title' => 'Un article de reference',
            'slug' => 'article-'.Str::random(8),
            'content' => $contenu,
            'status' => $statut,
            'published_at' => $statut === 'published' ? now()->subDay() : null,
        ]);

        DB::table('dossier_blog_posts')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'blog_post_id' => $post->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $post;
    }

    private function fakeAgent(string $texte): void
    {
        LoopKnowledgeAgent::fake([
            new TextResponse($texte, new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini')),
        ]);
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => 'Titre', 'clarified_request' => 'Demande clarifiee.', 'help_type' => 'information',
            'suggested_loop_id' => '', 'suggested_category_id' => '', 'suggestion_reason' => '',
            'questions_for_user' => [], 'confidence' => 0.9, 'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured, json_encode($structured, JSON_UNESCAPED_UNICODE),
            new Usage(120, 80), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }

    private function send(string $question, string $objectId, ?User $acteur = null): void
    {
        $acteur ??= $this->auteur;
        $context = app(AiShellPageContext::class)->resolve(
            $acteur, $this->organization, AiShellPageContext::KIND_ARTICLE, $objectId,
        );

        app(AiShellResponder::class)->respond($this->organization, $acteur, $question, $context);
    }

    private function lastAssistant(): AiShellMessage
    {
        return AiShellMessage::query()->where('role', 'assistant')
            ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
    }

    // ── Le contenu de l'Article devient lisible ─────────────────────────────

    public function test_the_shell_answers_from_the_current_article_without_any_retrieval(): void
    {
        $post = $this->article('Le protocole retenu impose une revue par les pairs a chaque etape.');

        // Aucune recherche : un Article EST le document.
        $this->mock(DossierSemanticSearchService::class)->shouldNotReceive('searchAcrossDossiers');

        $this->fakeAgent('Le protocole impose une revue par les pairs. [S1]');
        HelpRequestClarifierAgent::fake([]);

        $this->send('Que dit cet article du protocole ?', (string) $post->id);

        $message = $this->lastAssistant();

        $this->assertSame('article.answer', $message->metadata['producer'] ?? null);
        $this->assertStringContainsString('revue par les pairs', (string) $message->content);
    }

    public function test_the_answered_turn_carries_its_cards_and_its_verdict_pointer(): void
    {
        $post = $this->article('Un contenu suffisant pour etre lu.');

        $this->fakeAgent('Une reponse. [S1]');
        HelpRequestClarifierAgent::fake([]);

        $this->send('De quoi parle cet article ?', (string) $post->id);

        $meta = $this->lastAssistant()->metadata;

        $this->assertArrayHasKey('cards', $meta);
        $this->assertNotNull($meta['ai_interaction_id'] ?? null);
    }

    // ── Les gardes ──────────────────────────────────────────────────────────

    /**
     * LE test de cette TASK : le manifeste d'une Boucle PRIVEE ne devient pas
     * lisible parce qu'un Shell s'est ouvert dessus.
     */
    public function test_a_private_loop_manifesto_is_never_read_for_a_non_member(): void
    {
        $loop = (new LoopService)->createLoop($this->auteur, 'Boucle privee 1520');
        $loop->forceFill(['visibility' => 'private'])->save();

        $post = $this->article('Le manifeste secret de la Boucle privee.');
        $loop->forceFill(['manifesto_blog_post_id' => $post->id])->save();

        $this->fakeAgent('Le manifeste secret dit ceci. [S1]');
        $this->fakeClarifier();

        $this->send('Que dit ce manifeste ?', (string) $post->id, $this->etranger);

        $message = $this->lastAssistant();

        $this->assertNotSame('article.answer', $message->metadata['producer'] ?? null);
        $this->assertStringNotContainsString('manifeste secret', (string) $message->content);
    }

    /**
     * La MEME propriete, mais par le chemin forge — celui que le CDC interdit
     * de croire sur parole.
     *
     * Le test precedent passe par le resolveur, qui refuse en amont : la garde
     * INTERNE y est inatteignable, et un sabotage la laisse verte. Ici le
     * contexte est fabrique, et c'est la garde interne, seule, qui protege le
     * manifeste d'une Boucle privee.
     */
    public function test_a_private_loop_manifesto_resists_a_hand_built_context(): void
    {
        $loop = (new LoopService)->createLoop($this->auteur, 'Boucle privee forgee');
        $loop->forceFill(['visibility' => 'private'])->save();

        $post = $this->article('Le manifeste secret de la Boucle privee.');
        $loop->forceFill(['manifesto_blog_post_id' => $post->id])->save();

        $this->fakeAgent('Le manifeste secret dit ceci. [S1]');
        $this->fakeClarifier();

        $contexteForge = [
            'refused' => false,
            'organization' => ['id' => (string) $this->organization->id, 'name' => '', 'slug' => ''],
            'route' => 'organization.blog.show',
            'surface' => 'blog',
            'kind' => AiShellPageContext::KIND_ARTICLE,
            'object' => ['type' => 'article', 'id' => (string) $post->id, 'label' => 'Manifeste', 'url' => ''],
            'label' => 'Manifeste',
        ];

        app(AiShellResponder::class)->respond($this->organization, $this->etranger, 'Que dit ce manifeste ?', $contexteForge);

        $message = $this->lastAssistant();

        $this->assertNotSame('article.answer', $message->metadata['producer'] ?? null);
        $this->assertStringNotContainsString('manifeste secret', (string) $message->content);
    }

    public function test_a_draft_article_is_not_readable_by_someone_else(): void
    {
        $post = $this->article('Un brouillon confidentiel.', 'draft');

        $this->fakeAgent('Le brouillon dit ceci. [S1]');
        $this->fakeClarifier();

        $this->send('Que dit ce brouillon ?', (string) $post->id, $this->etranger);

        $this->assertNotSame('article.answer', $this->lastAssistant()->metadata['producer'] ?? null);
    }

    /**
     * Le CDC l'interdit nommement : ne jamais recharger un `BlogPost` depuis un
     * `object_id` persistant sans rejouer la garde. Le contexte est ici FORGE.
     */
    public function test_a_hand_built_article_context_never_becomes_an_access_right(): void
    {
        $post = $this->article('Un brouillon confidentiel.', 'draft');

        // Le faux modele est ARME : sans lui, la branche echouerait faute de
        // reponse preparee, et ce test passerait pour la mauvaise raison. Un
        // sabotage de la garde l'a prouve.
        $this->fakeAgent('Le brouillon confidentiel dit ceci. [S1]');
        $this->fakeClarifier();

        $contexteForge = [
            'refused' => false,
            'organization' => ['id' => (string) $this->organization->id, 'name' => '', 'slug' => ''],
            'route' => 'organization.blog.show',
            'surface' => 'blog',
            'kind' => AiShellPageContext::KIND_ARTICLE,
            'object' => ['type' => 'article', 'id' => (string) $post->id, 'label' => 'Un article', 'url' => ''],
            'label' => 'Un article',
        ];

        app(AiShellResponder::class)->respond($this->organization, $this->etranger, 'Que dit-il ?', $contexteForge);

        $message = $this->lastAssistant();

        $this->assertNotSame('article.answer', $message->metadata['producer'] ?? null,
            'un contexte forge ne doit jamais ouvrir un Article que la garde refuse');
        $this->assertStringNotContainsString('brouillon confidentiel', (string) $message->content);
    }

    public function test_an_article_attached_to_no_dossier_falls_back(): void
    {
        $post = BlogPost::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->auteur->id,
            'title' => 'Article orphelin',
            'slug' => 'article-orphelin-1520',
            'content' => 'Un contenu sans rattachement.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        // Meme precaution : le faux modele est arme, pour qu'un rattachement
        // invente produise REELLEMENT une reponse et fasse rougir le test.
        $this->fakeAgent('Un contenu sans rattachement. [S1]');
        $this->fakeClarifier();

        $this->send('Que dit cet article ?', (string) $post->id);

        $this->assertNotSame('article.answer', $this->lastAssistant()->metadata['producer'] ?? null,
            'sans Dossier de rattachement, la branche s efface plutot que d inventer une trace');
    }

    // ── Aucune capability elargie ───────────────────────────────────────────

    public function test_the_clarify_capability_never_gains_the_blog_source(): void
    {
        $definition = app(CapabilityRegistry::class)->get('clarify_help_request');

        $this->assertNotContains(CapabilityRegistry::SOURCE_BLOG_POST, $definition->allowedSources);
        $this->assertNotContains(CapabilityRegistry::SOURCE_DOSSIER_RETRIEVAL, $definition->allowedSources);
    }
}
