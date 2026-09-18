<?php

namespace Tests\Feature\Dossiers;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Tests\TestCase;

/**
 * TASK-1532 — la recherche admin sur le VRAI moteur pgvector.
 *
 * La suite `TASK1532AdminKnowledgeSearchPolicyTest` mesure le PERIMETRE
 * interroge, avec un double qui enregistre son appel. Ce double ne borne rien
 * lui-meme : il rend les lignes qu'on lui donne. Prouver qu'un contenu
 * interdit ne SORT pas demande donc le vrai moteur, dont la borne est dans le
 * SQL (`whereIn('dossier_chunks.dossier_id', $dossierIds)`).
 *
 * Ici : aucun double. Trois Dossiers portant le MEME vecteur — un que l'admin
 * peut ouvrir, un prive d'un autre membre, un d'une autre Organization. Seul
 * le premier doit ressortir de la recherche admin.
 *
 * PostgreSQL uniquement : sous SQLite le test est ignore (pas d'entree
 * supplementaire dans la reference des echecs connus).
 */
class PgvectorTASK1532AdminKnowledgeSearchPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Admin knowledge search integration requires PostgreSQL pgvector.');
        }

        if (DB::table('pg_extension')->where('extname', 'vector')->doesntExist()) {
            $this->markTestSkipped('pgvector extension is not installed.');
        }
    }

    public function test_the_admin_search_never_returns_content_of_a_dossier_it_cannot_open(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $autreMembre = User::factory()->create(['organization_id' => $organization->id]);
        $etrangerUser = User::factory()->create(['organization_id' => $autreOrg->id]);
        $organization->update(['admin_id' => $admin->id]);
        $organization = $organization->fresh();
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1532',
        ]);

        config([
            'ai.providers.openrouter.driver' => 'openrouter',
            'ai.providers.openrouter.key' => 'platform-should-not-be-used',
            'ai.default_for_embeddings' => 'openrouter',
            'ai.caching.embeddings.cache' => false,
            'ai.providers.openrouter.models.embeddings.default' => 'openai/text-embedding-3-small',
            'ai.providers.openrouter.models.embeddings.dimensions' => 1536,
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_ids' => [$organization->id],
            'ai.knowledge.max_distance' => 2.0,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();

        $sien = $this->dossier($organization, $admin, 'DOSSIERADMIN', Dossier::VISIBILITY_PRIVATE);
        $prive = $this->dossier($organization, $autreMembre, 'DOSSIERPRIVEINTERDIT', Dossier::VISIBILITY_PRIVATE);
        $etranger = $this->dossier($autreOrg, $etrangerUser, 'DOSSIERETRANGER', Dossier::VISIBILITY_ORGANIZATION);

        $this->assertTrue($admin->cannot('view', $prive), 'premisse : etre admin ne suffit pas a ouvrir ce Dossier');

        // MEME vecteur pour les trois : seule la borne ACL/tenant, dans le SQL,
        // peut les departager. Si elle sautait, le test le verrait.
        $this->chunk($organization, $sien, $admin, $this->vector(0.0), 'PARTENAIRESAUTORISES du Dossier de l admin.');
        $this->chunk($organization, $prive, $autreMembre, $this->vector(0.0), 'SECRETPRIVE de la liste confidentielle.');
        $this->chunk($autreOrg, $etranger, $etrangerUser, $this->vector(0.0), 'SECRETETRANGER hors tenant.');

        $html = (string) $this->actingAs($admin)
            ->get(route('organization.admin.ai-knowledge.search', [
                'organization' => $organization->slug,
                'q' => 'partenaires',
            ]))
            ->assertOk()
            ->getContent();

        // Ce que l'admin a le droit de lire ressort ...
        $this->assertStringContainsString('PARTENAIRESAUTORISES', $html,
            'le Dossier que l admin peut ouvrir reste bien cherchable');

        // ... et rien d'autre : ni contenu, ni nom de Dossier, ni titre de
        // document, pour le prive comme pour l'etranger.
        foreach (['SECRETPRIVE', 'DOSSIERPRIVEINTERDIT', 'SECRETETRANGER', 'DOSSIERETRANGER'] as $interdit) {
            $this->assertStringNotContainsString($interdit, $html,
                "aucune trace de {$interdit} dans la recherche admin");
        }
    }

    /**
     * Meme forme que les autres suites pgvector : un vecteur entierement nul
     * n'a pas de direction, la distance cosinus vaut NaN et le filtre
     * `max_distance` ecarterait tout.
     *
     * @return list<float>
     */
    private function vector(float $second): array
    {
        $vector = array_fill(0, 1536, 0.0);
        $vector[0] = 1.0;
        $vector[1] = $second;

        return $vector;
    }

    private function dossier(Organization $organization, User $owner, string $name, string $visibility): Dossier
    {
        return Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => $name,
            'visibility' => $visibility,
        ]);
    }

    /** @param  list<float>  $vector */
    private function chunk(Organization $organization, Dossier $dossier, User $owner, array $vector, string $content): DossierChunk
    {
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Article '.Str::uuid(),
            'slug' => 'article-'.Str::uuid(),
            'content' => '<p>'.$content.'</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);

        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $owner->id,
            'position' => 1,
        ]);

        return DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'chunk_index' => 0,
            'content' => $content,
            'content_hash' => hash('sha256', $content.Str::uuid()),
            'token_count' => 8,
            'embedding' => $vector,
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }
}
