<?php

namespace Tests\Feature\Knowledge;

use App\Ai\Agents\LoopConversationKnowledgeAgent;
use App\Models\DerivedKnowledgeNote;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1537 — les deux defauts que seule une utilisation REELLE a revelees.
 *
 * Aucun des deux n'etait visible sous SQLite avec des doubles. Le premier
 * demandait PostgreSQL, le second un etat que le chemin nominal ne produit
 * jamais. C'est exactement ce qu'une campagne de validation contre un vrai
 * environnement est censee trouver.
 */
class TASK1537DerivationRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true, 'slug' => 'tenant-de-validation']);
        app()->instance('current_organization', $this->organization);

        $this->alice = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alice Renard']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->alice->id,
            'name' => 'Chantier Belleville',
            'visibility' => 'private',
        ]);

        LoopMember::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->alice->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(LoopRootDocumentService::class)->ensureRootDossier($this->loop->fresh());

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1537',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$this->organization->id],
            'ai_pricing.overrides' => [],
        ]);

        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(
            fn (): array => array_fill(0, 1536, 0.01),
            $prompt->inputs,
        ))->preventStrayEmbeddings();

        LoopMessage::create([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->alice->id,
            'body' => 'Le budget travaux vote pour Belleville est de 486 000 euros.',
            'type' => 'user',
        ]);
    }

    public function test_la_commande_accepte_un_slug_d_organization(): void
    {
        $this->fakeAgent('Budget travaux Belleville : 486 000 euros.');

        // Sous PostgreSQL, comparer une chaine non-UUID a une colonne `uuid`
        // fait tomber TOUTE la requete — « invalid input syntax for type uuid »
        // — y compris la branche `slug` qui, elle, aurait trouve. L'option
        // annoncait « slug ou identifiant » et ne supportait en fait que
        // l'identifiant. SQLite, au typage lache, ne l'aurait jamais dit.
        $this->artisan('knowledge:derive-loop-conversations', [
            '--organization' => 'tenant-de-validation',
        ])->assertSuccessful();

        $this->assertSame(1, DerivedKnowledgeNote::query()->count(),
            'la Boucle du tenant designe par son slug a bien ete derivee');
    }

    public function test_la_commande_accepte_aussi_un_identifiant(): void
    {
        $this->fakeAgent('Budget travaux Belleville : 486 000 euros.');

        $this->artisan('knowledge:derive-loop-conversations', [
            '--organization' => (string) $this->organization->id,
        ])->assertSuccessful();

        $this->assertSame(1, DerivedKnowledgeNote::query()->count());
    }

    public function test_un_slug_inconnu_est_refuse_sans_exception(): void
    {
        $this->artisan('knowledge:derive-loop-conversations', [
            '--organization' => 'ce-tenant-n-existe-pas',
        ])->assertSuccessful();

        $this->assertSame(0, DerivedKnowledgeNote::query()->count());
    }

    public function test_le_numero_de_version_se_derive_de_tout_l_historique(): void
    {
        $this->fakeAgent('Budget travaux Belleville : 486 000 euros.');
        $deriver = app(LoopConversationKnowledgeDeriver::class);

        $v1 = $deriver->derive($this->loop->fresh());
        $this->assertSame(1, $v1->version);

        // L'etat que le chemin nominal ne produit jamais — supersede et
        // creation vivent dans la meme transaction — mais qu'une purge, une
        // reprise ou une intervention manuelle peut laisser : plus AUCUNE
        // note active, alors que l'historique existe.
        $v1->forceFill(['status' => DerivedKnowledgeNote::STATUS_SUPERSEDED, 'superseded_at' => now()])->save();
        $v1->forceFill(['source_fingerprint' => str_repeat('a', 64)])->save();

        $this->fakeAgent('Budget travaux Belleville : 486 000 euros, revise.');
        $v2 = $deriver->derive($this->loop->fresh());

        // Sans le correctif, la version repartait a 1 et heurtait l'unicite
        // (organization, source_type, source_loop_id, subject_key, version)
        // sur la v1 archivee.
        $this->assertNotNull($v2, 'la derivation ne doit pas echouer sur une contrainte d unicite');
        $this->assertSame(2, $v2->version);
        $this->assertSame(DerivedKnowledgeNote::STATUS_ACTIVE, $v2->status);
        $this->assertSame(2, DerivedKnowledgeNote::query()->count());
    }

    private function fakeAgent(string $texte): void
    {
        LoopConversationKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            $texte, new Usage(30, 12), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));
    }
}
