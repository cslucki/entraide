<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1512 — le bandeau de la console RAG disait « L'indexation IA est
 * disponible » sur une Organization ou ZERO document etait indexe.
 *
 * La phrase etait vraie — elle ne parlait que de la configuration (activation,
 * credential, budget) — et pourtant elle induisait en erreur : elle a coute une
 * heure a Cyril, qui cherchait un parametre manquant alors que tout etait
 * correct et que rien n'etait indexe.
 *
 * Ce que ces tests gardent :
 *
 *  1. quand rien n'est indexe, le bandeau le DIT, et ne se contente plus
 *     d'annoncer la disponibilite ;
 *  2. quand des sources sont indexees, il le chiffre ;
 *  3. et surtout — arbitrage MASTER — il n'invente JAMAIS un etat « en
 *     attente » : la table `jobs` ne porte ni `organization_id` ni identifiant
 *     de source, et un job consomme ne laisse aucune ligne. Deduire l'attente
 *     de « eligible et 0 chunk » remplacerait un vert trompeur par un orange
 *     trompeur.
 */
class TASK1512KnowledgeBannerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.dossiers.semantic_search.enabled' => true,
            'ai.dossiers.semantic_search.organization_slugs' => ['org-t1512'],
            'ai.default_for_embeddings' => 'openrouter',
        ]);

        $this->organization = Organization::factory()->create([
            'slug' => 'org-t1512', 'is_active' => true, 'is_public' => true, 'locale' => 'fr',
        ]);

        $this->admin = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->organization->update(['admin_id' => $this->admin->id]);

        // Le credential doit exister ET appartenir a la MEME famille que
        // l'index, sinon le bandeau bascule sur le cas « pas de credential »
        // et ne mesure plus ce que ce test veut mesurer.
        OrganizationAiSetting::create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-not-a-real-key',
            'is_enabled' => true,
        ]);
    }

    /**
     * Le HTML ECHAPPE les apostrophes : « l'indexation » y devient
     * « l&#039;indexation ». Comparer a la forme brute donnerait une garde
     * faussement rouge — d'ou le `e()` sur chaque texte attendu.
     */
    private function console(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('organization.admin.ai-knowledge', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    private function eligibleFile(): DossierFile
    {
        $dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->admin->id,
        ]);

        return DossierFile::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->admin->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/t1512.txt',
            'original_name' => 't1512.txt',
            'display_name' => 't1512.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 42,
            'checksum_sha256' => hash('sha256', 't1512'),
            'source' => 'upload',
        ]);
    }

    /**
     * LE defaut signale : configuration complete, zero document indexe, et un
     * bandeau vert qui laissait croire que tout allait bien.
     */
    public function test_the_banner_says_nothing_is_indexed_instead_of_only_announcing_availability(): void
    {
        $this->eligibleFile();

        $html = $this->console();

        $this->assertStringContainsString('data-knowledge-infra="nothing_indexed"', $html);
        $this->assertStringContainsString(e(__('ai.observatory_infra_ok_nothing_indexed', ['total' => 1])), $html);

        // L'ancienne phrase, seule, ne doit plus apparaitre.
        $this->assertStringNotContainsString(e(__('ai.observatory_infra_ok')), $html, 'annoncer la disponibilite sans dire que rien n est indexe etait le defaut');
        $this->assertStringNotContainsString('data-knowledge-infra="available"', $html);
    }

    public function test_the_banner_counts_the_indexed_sources_once_indexing_happened(): void
    {
        $file = $this->eligibleFile();

        DossierChunk::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'dossier_id' => $file->dossier_id,
            'blog_post_id' => null,
            'dossier_file_id' => $file->id,
            'chunk_index' => 0,
            'content' => 'contenu indexe',
            'content_hash' => hash('sha256', 'contenu indexe'),
            'token_count' => 2,
            // `embedding` est NOT NULL : en SQLite la colonne est du texte, en
            // pgsql un `vector(1536)`. Ce test ne mesure pas la recherche, il
            // mesure le COMPTAGE des sources indexees — une valeur de forme
            // suffit, et elle doit exister.
            'embedding' => '[]',
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        $html = $this->console();

        $this->assertStringContainsString('data-knowledge-infra="available"', $html);
        $this->assertStringContainsString(e(__('ai.observatory_infra_ok_indexed', ['indexed' => 1, 'total' => 1])), $html);
    }

    /** Sans aucune source eligible, « rien n'est indexe » serait un faux reproche. */
    public function test_an_organization_without_any_eligible_source_is_told_so_plainly(): void
    {
        $html = $this->console();

        $this->assertStringContainsString('data-knowledge-infra="no_source"', $html);
        $this->assertStringContainsString(e(__('ai.observatory_infra_ok_no_source')), $html);
    }

    /**
     * L'arbitrage MASTER, encode. Un fichier eligible sans chunk ne prouve
     * RIEN sur la queue : le bandeau ne doit pas se dire « en attente ».
     */
    public function test_the_banner_never_claims_a_pending_state_it_cannot_prove(): void
    {
        $this->eligibleFile();

        $html = $this->console();

        foreach (['data-knowledge-infra="pending"', 'data-knowledge-infra="awaiting"', 'data-knowledge-infra="queued"'] as $invented) {
            $this->assertStringNotContainsString($invented, $html, 'la table `jobs` ne permet pas de prouver l attente d une source : ne pas l affirmer');
        }
    }

    public function test_both_locales_carry_the_three_banner_messages(): void
    {
        $fr = require lang_path('fr/ai.php');
        $en = require lang_path('en/ai.php');

        foreach (['observatory_infra_ok_indexed', 'observatory_infra_ok_nothing_indexed', 'observatory_infra_ok_no_source'] as $key) {
            $this->assertArrayHasKey($key, $fr, "cle {$key} absente du francais");
            $this->assertArrayHasKey($key, $en, "cle {$key} absente de l anglais");
            $this->assertNotSame('', trim((string) $fr[$key]));
        }

        // Texte visible en francais : les accents ne sont pas facultatifs.
        $this->assertStringContainsString('éligibles', (string) $fr['observatory_infra_ok_nothing_indexed']);
        $this->assertStringContainsString('indexée', (string) $fr['observatory_infra_ok_nothing_indexed']);
    }
}
