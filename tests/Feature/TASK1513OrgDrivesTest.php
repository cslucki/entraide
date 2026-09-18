<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierFileChunks;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\Dossiers\OrganizationFileInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1513 — la page « Fichiers » de la console d'Organization.
 *
 * Deux choses s'y mesurent, et la premiere est la plus importante :
 *
 *  1. LE TENANT. `DossierFile` ne porte PAS `BelongsToOrganizationScope` —
 *     seulement `HasOrganizationId`, qui ECRIT `organization_id` sans jamais
 *     FILTRER en lecture. Il n'existe donc aucun garde-fou fail-closed : une
 *     requete sans borne lirait toute la plateforme, silencieusement. Chaque
 *     test de cette classe peuple DEUX Organizations.
 *
 *  2. Les trois etats, et surtout ce qu'ils ne disent pas : `not_indexed` ne
 *     s'appelle PAS « en attente ». La table `jobs` ne porte ni
 *     `organization_id` ni identifiant de source, et un job consomme ne laisse
 *     aucune ligne (arbitrage MASTER, TASK-1512).
 */
class TASK1513OrgDrivesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1513', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1513', 'is_active' => true]);

        $this->adminA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->orgA->update(['admin_id' => $this->adminA->id]);
    }

    private function file(Organization $organization, string $name, string $mime = 'text/plain'): DossierFile
    {
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = Dossier::factory()->create(['organization_id' => $organization->id, 'owner_id' => $owner->id]);

        return DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.Str::uuid().'.bin',
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mime,
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $name),
            'source' => 'upload',
        ]);
    }

    private function chunkFor(DossierFile $file): void
    {
        DossierChunk::create([
            'organization_id' => $file->organization_id,
            'dossier_id' => $file->dossier_id,
            'blog_post_id' => null,
            'dossier_file_id' => $file->id,
            'chunk_index' => 0,
            'content' => 'extrait',
            'content_hash' => hash('sha256', 'extrait'.$file->id),
            'token_count' => 1,
            // `vector(1536)` en pgsql : une chaine courte y serait refusee, et
            // le defaut serait invisible en SQLite (colonne texte).
            'embedding' => array_fill(0, 1536, 0.0),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    private function page(?array $query = null): string
    {
        return $this->actingAs($this->adminA)
            ->get(route('organization.admin.drives', ['organization' => $this->orgA->slug] + ($query ?? [])))
            ->assertOk()
            ->getContent();
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_an_admin_never_sees_a_file_of_another_organization(): void
    {
        $this->file($this->orgA, 'chez-moi.txt');
        $this->file($this->orgB, 'chez-le-voisin.txt');

        $html = $this->page();

        $this->assertStringContainsString('chez-moi.txt', $html);
        $this->assertStringNotContainsString('chez-le-voisin.txt', $html, 'aucun garde-fou fail-closed sur DossierFile : la borne doit etre explicite');
    }

    /**
     * Le `organization_id` de la jointure des chunks n'est pas decoratif :
     * sans lui, le chunk d'un autre tenant portant le meme identifiant de
     * fichier viendrait gonfler le compteur.
     */
    public function test_a_chunk_of_another_tenant_never_inflates_the_count(): void
    {
        $mine = $this->file($this->orgA, 'a-moi.txt');

        // Un chunk de orgB qui pointe le fichier de orgA : impossible en
        // pratique, mais c'est exactement ce que la jointure doit exclure.
        DossierChunk::create([
            'organization_id' => $this->orgB->id,
            'dossier_id' => $mine->dossier_id,
            'blog_post_id' => null,
            'dossier_file_id' => $mine->id,
            'chunk_index' => 0,
            'content' => 'intrus',
            'content_hash' => hash('sha256', 'intrus'),
            'token_count' => 1,
            'embedding' => array_fill(0, 1536, 0.0),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        $rows = app(OrganizationFileInventory::class)->forOrganization($this->orgA)->getCollection();

        $this->assertCount(1, $rows);
        $this->assertSame(0, $rows->first()['chunks'], 'le chunk d un autre tenant ne doit pas compter');
        $this->assertSame(OrganizationFileInventory::STATE_NOT_INDEXED, $rows->first()['state']);
    }

    public function test_a_member_who_is_not_admin_is_refused(): void
    {
        $member = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);

        $this->actingAs($member)
            ->get(route('organization.admin.drives', ['organization' => $this->orgA->slug]))
            ->assertForbidden();
    }

    // ── Les trois etats ─────────────────────────────────────────────────────

    public function test_the_three_states_are_derived_and_a_non_ingestible_file_is_still_listed(): void
    {
        $indexed = $this->file($this->orgA, 'indexe.txt');
        $this->chunkFor($indexed);
        $this->file($this->orgA, 'pas-encore.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->file($this->orgA, 'photo.png', 'image/png');

        $rows = app(OrganizationFileInventory::class)->forOrganization($this->orgA)->getCollection()
            ->keyBy(fn (array $row) => $row['name']);

        $this->assertSame(OrganizationFileInventory::STATE_INDEXED, $rows['indexe.txt']['state']);
        $this->assertSame(OrganizationFileInventory::STATE_NOT_INDEXED, $rows['pas-encore.docx']['state']);

        // La difference avec `OrganizationRagOverview::sources()`, qui exclut
        // les formats non ingerables : ici, ne pas les montrer serait un
        // silence, pas une reponse.
        $this->assertSame(OrganizationFileInventory::STATE_NOT_INGESTIBLE, $rows['photo.png']['state']);
        $this->assertCount(3, $rows);
    }

    /** Un `.docx` arrive parfois en `application/zip` : l'extension tranche. */
    public function test_a_docx_stored_with_a_generic_mime_is_not_declared_unindexable(): void
    {
        $this->file($this->orgA, 'Rapport.docx', 'application/zip');

        $row = app(OrganizationFileInventory::class)->forOrganization($this->orgA)->getCollection()->first();

        $this->assertSame(OrganizationFileInventory::STATE_NOT_INDEXED, $row['state']);
    }

    public function test_the_state_filter_selects_exactly_the_right_rows(): void
    {
        $indexed = $this->file($this->orgA, 'indexe.txt');
        $this->chunkFor($indexed);
        $this->file($this->orgA, 'pas-encore.txt');
        $this->file($this->orgA, 'photo.png', 'image/png');

        $inventory = app(OrganizationFileInventory::class);

        foreach ([
            OrganizationFileInventory::STATE_INDEXED => 'indexe.txt',
            OrganizationFileInventory::STATE_NOT_INDEXED => 'pas-encore.txt',
            OrganizationFileInventory::STATE_NOT_INGESTIBLE => 'photo.png',
        ] as $state => $expected) {
            $rows = $inventory->forOrganization($this->orgA, ['state' => $state])->getCollection();

            $this->assertCount(1, $rows, "le filtre {$state} doit rendre une seule ligne");
            $this->assertSame($expected, $rows->first()['name']);
        }
    }

    /** Le nom de colonne d'un tri ne se prend pas dans l'URL. */
    public function test_an_unknown_sort_falls_back_to_the_default_instead_of_reaching_sql(): void
    {
        $this->file($this->orgA, 'un.txt');

        $rows = app(OrganizationFileInventory::class)
            ->forOrganization($this->orgA, ['sort' => 'dossier_files.id; drop table users', 'direction' => 'peu importe'])
            ->getCollection();

        $this->assertCount(1, $rows);
    }

    // ── La page ─────────────────────────────────────────────────────────────

    public function test_every_cell_carries_its_mobile_label(): void
    {
        $this->file($this->orgA, 'un.txt');

        $html = $this->page();

        $this->assertSame(1, preg_match('/<tr[^>]*data-drives-row.*?<\/tr>/s', $html, $m));
        $cells = preg_match_all('/<td\b[^>]*>/', $m[0], $tds);

        $this->assertGreaterThan(0, $cells);
        foreach ($tds[0] as $td) {
            $this->assertStringContainsString('data-label=', $td, 'sans libelle, la cellule perd son sens sur mobile (contrat TASK-1503)');
        }
    }

    // ── La seule ecriture ───────────────────────────────────────────────────

    public function test_reindexing_queues_the_job_on_the_dedicated_queue(): void
    {
        // L'Observer dispatche deja a la creation du fichier : figer la file
        // AVANT la fixture capturerait CE job, et le test serait vert sans que
        // le controleur ait rien fait. On fige apres.
        $file = $this->file($this->orgA, 'a-reindexer.txt');
        Queue::fake();

        $this->actingAs($this->adminA)
            ->post(route('organization.admin.drives.reindex', ['organization' => $this->orgA->slug, 'file' => $file->id]))
            ->assertRedirect();

        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
    }

    /**
     * Sans scope global, le route-model-binding accepterait l'UUID d'un
     * fichier d'une autre Organization : la verification est explicite, et
     * AUCUN job ne doit partir.
     */
    public function test_reindexing_a_file_of_another_organization_is_refused_and_queues_nothing(): void
    {
        $foreign = $this->file($this->orgB, 'pas-a-moi.txt');
        Queue::fake();

        $this->actingAs($this->adminA)
            ->post(route('organization.admin.drives.reindex', ['organization' => $this->orgA->slug, 'file' => $foreign->id]))
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_a_non_ingestible_file_cannot_be_queued(): void
    {
        $photo = $this->file($this->orgA, 'photo.png', 'image/png');
        Queue::fake();

        $this->actingAs($this->adminA)
            ->post(route('organization.admin.drives.reindex', ['organization' => $this->orgA->slug, 'file' => $photo->id]))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /** Une ecriture ne se pre-charge pas. */
    public function test_the_reindex_endpoint_refuses_a_get(): void
    {
        $file = $this->file($this->orgA, 'un.txt');

        $this->actingAs($this->adminA)
            ->get('/org/'.$this->orgA->slug.'/admin/drives/'.$file->id.'/reindex')
            ->assertStatus(405);
    }

    public function test_both_locales_carry_every_label_of_this_screen(): void
    {
        $fr = require lang_path('fr/drives.php');
        $en = require lang_path('en/drives.php');

        $this->assertNotEmpty($en);
        $this->assertSame(array_keys($en), array_keys($fr), 'les deux langues doivent porter exactement les memes cles');

        foreach ($fr as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "cle {$key} vide en francais");
        }

        $this->assertStringContainsString('État', (string) $fr['filter_state'], 'texte visible en francais : les accents ne sont pas facultatifs');
        $this->assertStringContainsString('indexé', (string) $fr['state_not_ingestible_hint']);
    }
}
