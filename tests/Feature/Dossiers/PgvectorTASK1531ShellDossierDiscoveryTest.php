<?php

namespace Tests\Feature\Dossiers;

use App\Ai\Agents\LoopKnowledgeAgent;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierChunk;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\AiShellResponder;
use App\Support\Ai\AiShellPageContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1531 — la decouverte sur le VRAI moteur pgvector.
 *
 * La suite `TASK1531ShellDossierDiscoveryTest` mesure le ROUTAGE et l'ACL avec
 * un double qui enregistre son appel. Un double ne prouve aucun retrieval :
 * ici, aucun double. Vrais chunks, vrais vecteurs, vraie requete pgvector,
 * depuis le Shell et depuis une page neutre.
 *
 * Ce qui est mesure et qu'un double ne pouvait pas montrer :
 *  - la recherche trouve le Dossier par le CONTENU, sans jamais lire son nom ;
 *  - le Dossier interdit et celui d'un autre tenant ont beau porter le MEME
 *    vecteur, ils ne sortent pas — la borne est dans le SQL, pas dans un tri ;
 *  - un seul embedding de requete est calcule, sur l'instance du tenant.
 *
 * PostgreSQL uniquement : sous SQLite le test est ignore (pas d'entree
 * supplementaire dans la reference des echecs connus).
 */
class PgvectorTASK1531ShellDossierDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Shell dossier discovery integration requires PostgreSQL pgvector.');
        }

        if (DB::table('pg_extension')->where('extname', 'vector')->doesntExist()) {
            $this->markTestSkipped('pgvector extension is not installed.');
        }
    }

    public function test_the_shell_discovers_an_authorized_dossier_by_content_and_never_leaves_the_tenant(): void
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $member = User::factory()->create(['organization_id' => $organization->id]);
        $autreMembre = User::factory()->create(['organization_id' => $organization->id]);
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);
        app()->instance('current_organization', $organization);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-tenant-1531',
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
            'ai.knowledge.max_distance' => 1.0,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        Embeddings::fake(fn (EmbeddingsPrompt $prompt): array => array_map(fn (): array => $this->vector(0.0), $prompt->inputs))
            ->preventStrayEmbeddings();
        LoopKnowledgeAgent::fake(fn (): TextResponse => new TextResponse(
            'Les partenaires sont ceux de l extrait. [S1]', new Usage(20, 10), new Meta('openrouter', 'openai/gpt-4o-mini'),
        ));

        // Le Dossier autorise ne s'appelle PAS « ARIA » : la decouverte doit
        // le trouver par le CONTENU de ses documents. Un appariement de titre
        // l'aurait manque en silence — c'est precisement ce qu'on refuse.
        $visible = $this->dossier($organization, $member, Dossier::VISIBILITY_PRIVATE, 'Projet europeen 2026');
        $interdit = $this->dossier($organization, $autreMembre, Dossier::VISIBILITY_PRIVATE, 'DOSSIERPRIVEINTERDIT');
        $lointain = $this->dossier($autreOrg, $etranger, Dossier::VISIBILITY_ORGANIZATION, 'DOSSIERETRANGER');

        // MEME vecteur pour les trois : seule la borne ACL/tenant peut les
        // departager. Si elle sautait, le test le verrait.
        $attendu = $this->chunk($organization, $visible, $member, $this->vector(0.0),
            'Les partenaires ARIA sont ACMEPUB et ZORGLUBPUB.');
        $this->chunk($organization, $interdit, $autreMembre, $this->vector(0.0),
            'SECRETINTERDIT du Dossier prive.');
        $this->chunk($autreOrg, $lointain, $etranger, $this->vector(0.0),
            'SECRETETRANGER du Dossier d une autre Organization.');

        $context = app(AiShellPageContext::class)->resolve($member, $organization, null, null);
        $this->assertNotSame(AiShellPageContext::KIND_DOSSIER, $context['kind'] ?? null);

        $this->actingAs($member);
        $reponse = app(AiShellResponder::class)
            ->respond($organization, $member, 'Qui sont les partenaires ARIA ?', $context)['answer'];

        $this->assertSame(AiShellResponder::PRODUCER_DOSSIER_DISCOVERY, $reponse->metadata['producer'],
            'la decouverte doit repondre depuis le corpus reel');
        $this->assertSame((string) $visible->id, $reponse->metadata['page_context']['object_id'],
            'le Dossier trouve est celui dont le CONTENU parle d ARIA, pas celui dont le nom y ressemble');

        // La provenance publique n'expose AUCUN identifiant interne (pas de
        // chunk_id) : ce qu'on mesure, c'est que l'extrait REELLEMENT retrouve
        // en base est celui qui est cite, et qu'il est rattache au Dossier
        // autorise.
        $sources = (string) json_encode($reponse->metadata['sources'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($attendu->content, $sources,
            'l extrait reellement retrouve par pgvector est celui qui est cite');
        $this->assertStringContainsString('Projet europeen 2026', $sources,
            'et il est rattache au Dossier autorise, trouve par son contenu et non par son nom');

        // Ni le contenu ni le NOM des Dossiers hors perimetre ne sortent.
        foreach (['SECRETINTERDIT', 'SECRETETRANGER', 'DOSSIERPRIVEINTERDIT', 'DOSSIERETRANGER'] as $interditDansLaSortie) {
            $this->assertStringNotContainsString($interditDansLaSortie, $reponse->content);
            $this->assertStringNotContainsString($interditDansLaSortie, json_encode($reponse->metadata, JSON_UNESCAPED_UNICODE));
        }

        // UN seul embedding de requete, sur l'instance du TENANT — jamais la
        // cle plateforme, quel que soit le nombre de Dossiers candidats.
        Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->inputs === ['Qui sont les partenaires ARIA ?']
            && $prompt->provider->name() === 'org:'.$organization->id.':openrouter'
            && $prompt->model === 'openai/text-embedding-3-small');
    }

    /**
     * Meme forme que `PgvectorDossierRetrievalSourceTest::vector()`, et pour
     * une raison mesuree : un vecteur ENTIEREMENT nul n'a pas de direction, la
     * distance cosinus vaut NaN, et pgvector rend alors des lignes que le
     * filtre `max_distance` ecarte toutes. La premiere composante reste donc a
     * 1.0 et seule la seconde varie.
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

    private function dossier(Organization $organization, User $owner, string $visibility, string $name): Dossier
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
