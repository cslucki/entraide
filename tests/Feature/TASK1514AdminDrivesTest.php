<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierFileChunks;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1514 — `/admin/drives` : les fichiers de TOUTES les Organizations.
 *
 * Cyril : « aucune page qui contient la liste des fichiers, et ceci avec une
 * selection selon l'organisation ».
 *
 * Ce que ces tests gardent :
 *
 *  - le droit vient du middleware `admin`, et de lui seul : le filtre
 *    `?organization=` est une LECTURE, il n'accorde rien ;
 *  - un slug inconnu ne filtre PAS en silence — sinon l'ecran ressemblerait a
 *    une Organization vide ;
 *  - l'ecriture exige l'Organization DANS l'URL et compare avant de
 *    dispatcher : `DossierFile` n'a aucun scope global, le route-model-binding
 *    accepterait sinon le fichier d'un autre tenant ;
 *  - aucun `var(--bp-*)` : `layouts/admin` n'emet pas ces jetons, la couleur
 *    y serait un no-op silencieux (mesure de TASK-1506).
 */
class TASK1514AdminDrivesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1514', 'name' => 'Alpha 1514', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1514', 'name' => 'Beta 1514', 'is_active' => true]);

        $platform = Organization::factory()->create(['slug' => 'plateforme-1514']);
        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $platform->id]);
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
            'size_bytes' => 512,
            'checksum_sha256' => hash('sha256', $name),
            'source' => 'upload',
        ]);
    }

    private function page(array $query = []): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.drives', $query))
            ->assertOk()
            ->getContent();
    }

    // ── Le droit ────────────────────────────────────────────────────────────

    public function test_a_user_who_is_not_super_admin_is_refused(): void
    {
        $member = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);

        $this->actingAs($member)->get(route('admin.drives'))->assertForbidden();
    }

    // ── Le filtre est une lecture ───────────────────────────────────────────

    public function test_without_a_filter_the_page_shows_every_organization(): void
    {
        $this->file($this->orgA, 'chez-alpha.txt');
        $this->file($this->orgB, 'chez-beta.txt');

        $html = $this->page();

        $this->assertStringContainsString('chez-alpha.txt', $html);
        $this->assertStringContainsString('chez-beta.txt', $html);
        $this->assertStringContainsString('Alpha 1514', $html, 'sans la colonne Organisation, une liste inter-tenants ne veut rien dire');
        $this->assertStringContainsString('Beta 1514', $html);
    }

    public function test_the_filter_restricts_the_list_to_one_organization(): void
    {
        $this->file($this->orgA, 'chez-alpha.txt');
        $this->file($this->orgB, 'chez-beta.txt');

        $html = $this->page(['organization' => $this->orgA->slug]);

        $this->assertStringContainsString('chez-alpha.txt', $html);
        $this->assertStringNotContainsString('chez-beta.txt', $html);
    }

    /**
     * Un slug demande mais introuvable ne doit pas filtrer en silence : sans
     * un mot, l'ecran ressemblerait a une Organization vide.
     */
    public function test_an_unknown_slug_is_named_instead_of_silently_ignored(): void
    {
        $this->file($this->orgA, 'chez-alpha.txt');

        $html = $this->page(['organization' => 'organisation-qui-n-existe-pas']);

        $this->assertStringContainsString('data-drives-unknown-organization', $html);
        $this->assertStringContainsString('chez-alpha.txt', $html, 'la liste reste complete : le filtre n a pas ete applique');
    }

    // ── L'ecriture ──────────────────────────────────────────────────────────

    public function test_reindexing_queues_the_job_on_the_dedicated_queue(): void
    {
        // L'Observer dispatche deja a la creation : on fige la file APRES la
        // fixture, sinon on mesurerait SON job et non celui du controleur.
        $file = $this->file($this->orgA, 'a-reindexer.txt');
        Queue::fake();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.drives.reindex', ['organization' => $this->orgA->slug, 'file' => $file->id]))
            ->assertRedirect();

        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
    }

    /**
     * L'Organization de l'URL et celle du fichier doivent concorder :
     * `DossierFile` n'a AUCUN scope global, le binding accepterait sinon
     * n'importe quel UUID.
     */
    public function test_a_mismatched_organization_and_file_is_refused_and_queues_nothing(): void
    {
        $foreign = $this->file($this->orgB, 'chez-beta.txt');
        Queue::fake();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.drives.reindex', ['organization' => $this->orgA->slug, 'file' => $foreign->id]))
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_a_non_ingestible_file_cannot_be_queued(): void
    {
        $photo = $this->file($this->orgA, 'photo.png', 'image/png');
        Queue::fake();

        $this->actingAs($this->superAdmin)
            ->post(route('admin.drives.reindex', ['organization' => $this->orgA->slug, 'file' => $photo->id]))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_a_non_super_admin_cannot_reindex(): void
    {
        $file = $this->file($this->orgA, 'un.txt');
        $member = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
        Queue::fake();

        $this->actingAs($member)
            ->post(route('admin.drives.reindex', ['organization' => $this->orgA->slug, 'file' => $file->id]))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    // ── La page ─────────────────────────────────────────────────────────────

    /**
     * `layouts/admin` n'emet AUCUN jeton de theme : une couleur ecrite
     * `var(--bp-primary)` y serait un no-op silencieux (TASK-1506).
     */
    public function test_the_page_never_relies_on_theme_tokens_the_admin_layout_does_not_emit(): void
    {
        $this->file($this->orgA, 'un.txt');

        $html = $this->page();

        $this->assertSame(1, preg_match('/<main\b.*?<\/main>/s', $html, $m), 'contenu principal introuvable');
        $this->assertStringNotContainsString('var(--bp-', $m[0]);
        $this->assertStringContainsString('bg-indigo-600', $m[0], 'la palette du superadmin est indigo');
    }

    public function test_every_cell_carries_its_mobile_label(): void
    {
        $this->file($this->orgA, 'un.txt');

        $html = $this->page();

        $this->assertSame(1, preg_match('/<tr[^>]*data-drives-row.*?<\/tr>/s', $html, $m));
        preg_match_all('/<td\b[^>]*>/', $m[0], $tds);

        $this->assertNotEmpty($tds[0]);
        foreach ($tds[0] as $td) {
            $this->assertStringContainsString('data-label=', $td, 'contrat TASK-1503 : sans libelle, la cellule perd son sens sur mobile');
        }
    }

    public function test_both_locales_carry_the_platform_labels(): void
    {
        $fr = require lang_path('fr/drives.php');
        $en = require lang_path('en/drives.php');

        $this->assertSame(array_keys($en), array_keys($fr), 'les deux langues doivent porter exactement les memes cles');

        foreach (['platform_title', 'platform_subtitle', 'filter_organization', 'filter_organization_all', 'unknown_organization'] as $key) {
            $this->assertArrayHasKey($key, $fr, "cle {$key} absente du francais");
            $this->assertNotSame('', trim((string) $fr[$key]));
        }

        $this->assertStringContainsString('appliqué', (string) $fr['unknown_organization'], 'texte visible en francais : les accents ne sont pas facultatifs');
    }
}
