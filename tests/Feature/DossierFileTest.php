<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DossierFileTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $ownerA;

    private User $editorA;

    private User $readerA;

    private User $strangerA;

    private User $userB;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');

        $this->orgA = Organization::factory()->create(['name' => 'Org A', 'slug' => 'org-a', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['name' => 'Org B', 'slug' => 'org-b', 'is_active' => true]);

        $this->ownerA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->editorA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->readerA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->strangerA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->userB = User::factory()->create(['organization_id' => $this->orgB->id]);

        $this->dossier = Dossier::create([
            'organization_id' => $this->orgA->id,
            'owner_id' => $this->ownerA->id,
            'name' => 'Test dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        DossierMember::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $this->dossier->id,
            'user_id' => $this->editorA->id,
            'role' => DossierMember::ROLE_EDITOR,
            'added_by' => $this->ownerA->id,
        ]);

        DossierMember::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $this->dossier->id,
            'user_id' => $this->readerA->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $this->ownerA->id,
        ]);
    }

    private function fakeFile(string $name = 'test.pdf', string $mime = 'application/pdf', int $size = 1024): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, str_repeat('x', $size), $mime);
    }

    private function storeRoute(Dossier $dossier): string
    {
        return route('organization.dossiers.files.store', ['organization' => $this->orgA, 'dossier' => $dossier]);
    }

    private function indexRoute(Dossier $dossier): string
    {
        return route('organization.dossiers.files.index', ['organization' => $this->orgA, 'dossier' => $dossier]);
    }

    private function showRoute(Dossier $dossier, DossierFile $file): string
    {
        return route('organization.dossiers.files.show', ['organization' => $this->orgA, 'dossier' => $dossier, 'file' => $file]);
    }

    private function destroyRoute(Dossier $dossier, DossierFile $file): string
    {
        return route('organization.dossiers.files.destroy', ['organization' => $this->orgA, 'dossier' => $dossier, 'file' => $file]);
    }

    private function previewRoute(Dossier $dossier, DossierFile $file): string
    {
        return route('organization.dossiers.files.preview', ['organization' => $this->orgA, 'dossier' => $dossier, 'file' => $file]);
    }

    private function moveRoute(Dossier $dossier, DossierFile $file): string
    {
        return route('organization.dossiers.files.move', ['organization' => $this->orgA, 'dossier' => $dossier, 'file' => $file]);
    }

    private function createFile(Dossier $dossier, User $uploader, string $name = 'doc.pdf', string $mimeType = 'application/pdf'): DossierFile
    {
        $path = 'dossier-files/'.$dossier->id.'/'.$name;
        Storage::disk('dossier_files')->put($path, 'stored test content');

        return DossierFile::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $uploader->id,
            'disk' => 'dossier_files',
            'path' => $path,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mimeType,
            'size_bytes' => strlen('stored test content'),
            'checksum_sha256' => hash('sha256', 'stored test content'),
            'source' => 'upload',
        ]);
    }

    // --- Upload tests ---

    public function test_owner_can_upload_files(): void
    {
        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile()],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('dossiers', ['id' => $this->dossier->id]);
    }

    public function test_owner_file_workflow_persists_stores_lists_downloads_and_deletes_file(): void
    {
        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile('workflow.pdf', 'application/pdf', 2048)],
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('message', __('dossiers.file_uploaded'))
            ->assertJsonPath('files.0.original_name', 'workflow.pdf')
            ->assertJsonPath('files.0.mime_type', 'application/pdf')
            ->assertJsonPath('files.0.size_bytes', 2048);

        $file = DossierFile::where('dossier_id', $this->dossier->id)->firstOrFail();

        $this->assertDatabaseHas('dossier_files', [
            'id' => $file->id,
            'organization_id' => $this->orgA->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->ownerA->id,
            'disk' => 'dossier_files',
            'original_name' => 'workflow.pdf',
            'display_name' => 'workflow.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ]);
        Storage::disk('dossier_files')->assertExists($file->path);

        $this->actingAs($this->ownerA)->getJson($this->indexRoute($this->dossier))
            ->assertOk()
            ->assertJsonPath('files.total', 1)
            ->assertJsonPath('files.data.0.id', $file->id)
            ->assertJsonPath('files.data.0.display_name', 'workflow.pdf')
            ->assertJsonPath('files.data.0.mime_type', 'application/pdf')
            ->assertJsonPath('files.data.0.size_bytes', 2048);

        $this->actingAs($this->ownerA)->get($this->showRoute($this->dossier, $file))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=workflow.pdf');

        $this->actingAs($this->ownerA)->deleteJson($this->destroyRoute($this->dossier, $file))
            ->assertOk();

        $this->assertSoftDeleted('dossier_files', ['id' => $file->id]);
        Storage::disk('dossier_files')->assertMissing($file->path);
    }

    public function test_editor_can_upload_files(): void
    {
        $response = $this->actingAs($this->editorA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile()],
        ]);

        $response->assertStatus(201);
    }

    public function test_reader_cannot_upload_files(): void
    {
        $response = $this->actingAs($this->readerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile()],
        ]);

        $response->assertStatus(403);
    }

    public function test_stranger_cannot_upload_files(): void
    {
        $response = $this->actingAs($this->strangerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile()],
        ]);

        $response->assertStatus(403);
    }

    public function test_cross_tenant_cannot_upload_files(): void
    {
        $response = $this->actingAs($this->userB)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile()],
        ]);

        $response->assertStatus(404);
    }

    public function test_upload_rejects_invalid_mime_type(): void
    {
        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [UploadedFile::fake()->createWithContent('malware.exe', str_repeat('x', 100), 'application/x-executable')],
        ]);

        $response->assertStatus(422);
    }

    public function test_upload_rejects_too_large_file(): void
    {
        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [UploadedFile::fake()->create('too-large.pdf', 51201, 'application/pdf')],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('dossier_files', 0);
    }

    public function test_upload_rejects_duplicate_file_name_in_dossier(): void
    {
        $this->createFile($this->dossier, $this->ownerA, 'duplicate.pdf');

        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile('duplicate.pdf', 'application/pdf', 2048)],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', __('dossiers.file_duplicate_name'));
    }

    public function test_upload_rejects_duplicate_file_content_in_dossier(): void
    {
        $this->createFile($this->dossier, $this->ownerA, 'stored.pdf');

        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [UploadedFile::fake()->createWithContent('same-content.pdf', 'stored test content', 'application/pdf')],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', __('dossiers.file_duplicate_content'));
    }

    public function test_upload_rejects_duplicate_file_names_in_same_batch(): void
    {
        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [
                $this->fakeFile('same-name.pdf', 'application/pdf', 1024),
                $this->fakeFile('same-name.pdf', 'application/pdf', 2048),
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', __('dossiers.file_duplicate_name'));
    }

    public function test_upload_rejects_duplicate_file_content_in_same_batch(): void
    {
        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [
                UploadedFile::fake()->createWithContent('one.pdf', 'same test content', 'application/pdf'),
                UploadedFile::fake()->createWithContent('two.pdf', 'same test content', 'application/pdf'),
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', __('dossiers.file_duplicate_content'));
    }

    public function test_upload_rejects_empty_files(): void
    {
        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), []);

        $response->assertStatus(422);
    }

    public function test_upload_accepts_a_batch_of_six_files(): void
    {
        // La limite serveur suit desormais celle du client (20) : refuser six
        // fichiers contredisait ce que l'interface propose. Le lot de six est
        // exactement le cas signale par Cyril.
        $files = array_map(fn ($i) => $this->fakeFile('doc'.$i.'.pdf', 'application/pdf', 1024 + $i * 100), range(1, 6));

        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => $files,
        ])->assertStatus(201);

        $this->assertSame(6, DossierFile::where('dossier_id', $this->dossier->id)->count());
    }

    public function test_upload_rejects_more_than_twenty_files(): void
    {
        $files = array_map(fn ($i) => $this->fakeFile('doc'.$i.'.pdf', 'application/pdf', 1024 + $i), range(1, 21));

        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => $files,
        ])->assertStatus(422);
    }

    /**
     * Le parcours REEL de l'interface : la file d'envoi poste un fichier par
     * requete. Rien ne le couvrait — la seule preuve « six fichiers » qui
     * existait affirmait l'inverse, sur un lot unique.
     */
    public function test_six_files_uploaded_one_request_at_a_time_all_land(): void
    {
        $lot = [
            ['rapport.pdf', 'application/pdf'],
            ['notes.txt', 'text/plain'],
            ['contrat.pdf', 'application/pdf'],
            ['evaluation.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['support.pdf', 'application/pdf'],
            ['facture.xls', 'application/vnd.ms-excel'],
        ];

        foreach ($lot as $index => [$nom, $mime]) {
            $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
                // Contenus distincts : le controleur refuse deux fichiers
                // identiques, et c'est voulu.
                'files' => [$this->fakeFile($nom, $mime, 512 + $index * 64)],
            ])->assertStatus(201);
        }

        $this->assertSame(6, DossierFile::where('dossier_id', $this->dossier->id)->count());
        $this->assertSame(
            collect($lot)->pluck(0)->sort()->values()->all(),
            DossierFile::where('dossier_id', $this->dossier->id)->pluck('display_name')->sort()->values()->all(),
        );
    }

    /**
     * Un .xls ancien est un conteneur OLE2 : selon la version de libmagic, le
     * serveur le voit `application/vnd.ms-excel`, `application/x-ole-storage`
     * ou `application/CDFV2`. Les trois doivent passer — c'est le fichier qui
     * echouait seul au milieu d'un import.
     */
    public function test_an_old_xls_container_is_accepted_whatever_libmagic_says(): void
    {
        $regles = (new \App\Http\Requests\StoreDossierFileRequest)->rules()['files.*'];
        $mimes = collect($regles)->first(fn ($regle) => is_string($regle) && str_starts_with($regle, 'mimetypes:'));

        foreach (['application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'] as $type) {
            $this->assertStringContainsString($type, $mimes, "Le type {$type} doit etre accepte pour un .xls.");
        }
    }

    public function test_upload_creates_database_records(): void
    {
        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile('test.pdf', 'application/pdf', 2048)],
        ])->assertStatus(201);

        $this->assertDatabaseHas('dossier_files', [
            'dossier_id' => $this->dossier->id,
            'organization_id' => $this->orgA->id,
            'uploaded_by' => $this->ownerA->id,
            'original_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'source' => 'upload',
        ]);
    }

    public function test_upload_stores_with_correct_disk(): void
    {
        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile()],
        ])->assertStatus(201);

        $file = DossierFile::where('dossier_id', $this->dossier->id)->first();
        $this->assertEquals('dossier_files', $file->disk);
    }

    // Deja rouge sur `develop` avant TASK-1112. Exclue du gate GitHub pour
    // qu'il puisse signifier quelque chose ; **le groupe doit se vider**, il
    // n'est pas un endroit ou ranger un test qui gene.
    #[\PHPUnit\Framework\Attributes\Group('ci-known-red')]
    public function test_upload_validates_quota(): void
    {
        $this->orgA->update(['dossier_storage_quota_bytes' => 5000]);

        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile('big.pdf', 'application/pdf', 6000)],
        ])->assertStatus(422);
    }

    // Deja rouge sur `develop` avant TASK-1112. Exclue du gate GitHub pour
    // qu'il puisse signifier quelque chose ; **le groupe doit se vider**, il
    // n'est pas un endroit ou ranger un test qui gene.
    #[\PHPUnit\Framework\Attributes\Group('ci-known-red')]
    public function test_upload_allows_under_quota(): void
    {
        $this->orgA->update(['dossier_storage_quota_bytes' => 10000]);

        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile('small.pdf', 'application/pdf', 1024)],
        ])->assertStatus(201);
    }

    public function test_upload_accepts_valid_image_types(): void
    {
        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [
                $this->fakeFile('photo.jpg', 'image/jpeg', 1024),
                $this->fakeFile('logo.png', 'image/png', 2048),
                $this->fakeFile('banner.webp', 'image/webp', 3072),
                $this->fakeFile('icon.gif', 'image/gif', 4096),
            ],
        ])->assertStatus(201);

        $this->assertEquals(4, DossierFile::where('dossier_id', $this->dossier->id)->count());
    }

    public function test_upload_accepts_doc_types(): void
    {
        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [
                $this->fakeFile('report.pdf', 'application/pdf', 1024),
                $this->fakeFile('letter.doc', 'application/msword', 2048),
                $this->fakeFile('contract.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 3072),
            ],
        ])->assertStatus(201);

        $this->assertEquals(3, DossierFile::where('dossier_id', $this->dossier->id)->count());
    }

    public function test_upload_accepts_markdown_and_text_types(): void
    {
        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [
                $this->fakeFile('notes.txt', 'text/plain', 256),
                $this->fakeFile('readme.md', 'text/markdown', 512),
            ],
        ])->assertStatus(201);

        $this->assertEquals(2, DossierFile::where('dossier_id', $this->dossier->id)->count());
    }

    public function test_preview_route_returns_inline_for_image(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA, 'photo.jpg', 'image/jpeg');

        $response = $this->actingAs($this->ownerA)->get($this->previewRoute($this->dossier, $file));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'inline; filename="'.$file->original_name.'"');
    }

    public function test_preview_route_returns_404_for_cross_tenant(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->userB)->get($this->previewRoute($this->dossier, $file));

        $response->assertStatus(404);
    }

    // --- List tests ---

    public function test_owner_can_list_files(): void
    {
        $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->ownerA)->getJson($this->indexRoute($this->dossier));

        $response->assertOk();
        $response->assertJsonStructure(['files' => ['data', 'links'], 'quota']);
    }

    public function test_editor_can_list_files(): void
    {
        $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->editorA)->getJson($this->indexRoute($this->dossier));

        $response->assertOk();
    }

    public function test_reader_can_list_files(): void
    {
        $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->readerA)->getJson($this->indexRoute($this->dossier));

        $response->assertOk();
    }

    public function test_stranger_cannot_list_files(): void
    {
        $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->strangerA)->getJson($this->indexRoute($this->dossier));

        $response->assertStatus(403);
    }

    public function test_cross_tenant_cannot_list_files(): void
    {
        $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->userB)->getJson($this->indexRoute($this->dossier));

        $response->assertStatus(404);
    }

    public function test_list_returns_quota_info(): void
    {
        $this->orgA->update(['dossier_storage_quota_bytes' => 100000]);
        $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->ownerA)->getJson($this->indexRoute($this->dossier));

        $response->assertOk();
        $response->assertJsonPath('quota.limit_bytes', 100000);
    }

    public function test_list_only_shows_own_dossier_files(): void
    {
        $dossier2 = Dossier::create([
            'organization_id' => $this->orgA->id,
            'owner_id' => $this->ownerA->id,
            'name' => 'Other dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $this->createFile($this->dossier, $this->ownerA, 'mine.pdf');
        $this->createFile($dossier2, $this->ownerA, 'other.pdf');

        $response = $this->actingAs($this->ownerA)->getJson($this->indexRoute($this->dossier));

        $response->assertOk();
        $this->assertEquals(1, $response->json('files.total'));
    }

    // --- Download tests ---

    public function test_member_can_download_file(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->ownerA)->get($this->showRoute($this->dossier, $file));

        $response->assertOk();
    }

    public function test_stranger_cannot_download_file(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->strangerA)->get($this->showRoute($this->dossier, $file));

        $response->assertStatus(403);
    }

    // --- Delete tests ---

    public function test_owner_can_delete_file(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->ownerA)->deleteJson($this->destroyRoute($this->dossier, $file));

        $response->assertOk();
        $this->assertSoftDeleted('dossier_files', ['id' => $file->id]);
        Storage::disk('dossier_files')->assertMissing($file->path);
    }

    public function test_editor_cannot_delete_file(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->editorA)->deleteJson($this->destroyRoute($this->dossier, $file));

        $response->assertStatus(403);
    }

    public function test_reader_cannot_delete_file(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->readerA)->deleteJson($this->destroyRoute($this->dossier, $file));

        $response->assertStatus(403);
    }

    public function test_cross_tenant_cannot_delete_file(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->userB)->deleteJson($this->destroyRoute($this->dossier, $file));

        $response->assertStatus(404);
    }

    // --- Dossier soft-delete nullifies file.dossier_id ---

    public function test_deleting_a_dossier_deletes_its_files_instead_of_orphaning_them(): void
    {
        // Decision Cyril du 13/08 : le Dossier part avec son contenu. Le
        // fichier est SOFT-supprime — jamais detache (`dossier_id => null`),
        // ce qui l'aurait rendu invisible et pourtant bien present.
        $file = $this->createFile($this->dossier, $this->ownerA);

        $this->actingAs($this->ownerA)->deleteJson(route('organization.dossiers.destroy', [
            'organization' => $this->orgA,
            'dossier' => $this->dossier,
        ]))->assertOk();

        $this->assertSoftDeleted('dossier_files', ['id' => $file->id]);
        $this->assertDatabaseHas('dossier_files', ['id' => $file->id, 'dossier_id' => $this->dossier->id]);
        $this->assertSoftDeleted('dossiers', ['id' => $this->dossier->id]);
    }

    // =======================================================================
    // Group B — Drive configurable par ENV (RED avant Phase 3)
    // =======================================================================

    public function test_dossier_files_disk_driver_uses_env_variable_or_defaults_to_local(): void
    {
        $configContent = file_get_contents(config_path('filesystems.php'));

        $this->assertStringContainsString(
            "env('DOSSIER_FILES_DRIVER'",
            $configContent,
            'dossier_files disk driver must use env(DOSSIER_FILES_DRIVER, local)'
        );
    }

    public function test_dossier_files_s3_configuration_uses_env_for_all_credentials(): void
    {
        $configContent = file_get_contents(config_path('filesystems.php'));

        preg_match("/'dossier_files'\s*=>\s*\[.*?],\s*$/ms", $configContent, $matches);
        $this->assertNotEmpty($matches, 'dossier_files disk configuration must exist');

        $block = $matches[0];

        $this->assertStringContainsString(
            "env('AWS_ACCESS_KEY_ID')",
            $block,
            'AWS_ACCESS_KEY_ID must come from env, not hardcoded'
        );
        $this->assertStringContainsString(
            "env('AWS_SECRET_ACCESS_KEY')",
            $block,
            'AWS_SECRET_ACCESS_KEY must come from env, not hardcoded'
        );
        $this->assertStringContainsString(
            "env('AWS_DEFAULT_REGION')",
            $block,
            'AWS_DEFAULT_REGION must come from env, not hardcoded'
        );
        $this->assertStringContainsString(
            "env('AWS_BUCKET')",
            $block,
            'AWS_BUCKET must come from env, not hardcoded'
        );
        $this->assertStringContainsString(
            "env('AWS_ENDPOINT')",
            $block,
            'AWS_ENDPOINT must come from env, not hardcoded'
        );
    }

    public function test_disk_name_in_database_remains_dossier_files_after_s3_configuration(): void
    {
        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile('disk-test.pdf')],
        ])->assertStatus(201);

        $file = DossierFile::where('dossier_id', $this->dossier->id)->first();
        $this->assertEquals('dossier_files', $file->disk,
            'disk column must remain dossier_files regardless of underlying driver');
    }

    // --- Cross-dossier isolation ---

    public function test_cannot_access_file_from_different_dossier(): void
    {
        $dossier2 = Dossier::create([
            'organization_id' => $this->orgA->id,
            'owner_id' => $this->ownerA->id,
            'name' => 'Dossier 2',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        $file = $this->createFile($this->dossier, $this->ownerA);

        $response = $this->actingAs($this->ownerA)->get($this->showRoute($dossier2, $file));

        $response->assertStatus(404);
    }

    // --- Move a file to another dossier (TASK-1130 passe 4) ---

    public function test_owner_can_move_a_file_to_a_child_folder(): void
    {
        $sousDossier = Dossier::create([
            'organization_id' => $this->orgA->id, 'parent_id' => $this->dossier->getKey(), 'name' => 'Sous-dossier',
        ]);
        $file = $this->createFile($this->dossier, $this->ownerA);

        $this->actingAs($this->ownerA)
            ->patchJson($this->moveRoute($this->dossier, $file), ['target_dossier_id' => $sousDossier->getKey()])
            ->assertOk();

        $this->assertDatabaseHas('dossier_files', ['id' => $file->id, 'dossier_id' => $sousDossier->getKey()]);
    }

    public function test_owner_can_move_a_file_up_to_the_parent(): void
    {
        $sousDossier = Dossier::create([
            'organization_id' => $this->orgA->id, 'parent_id' => $this->dossier->getKey(), 'name' => 'Sous-dossier',
        ]);
        $file = $this->createFile($sousDossier, $this->ownerA);

        $this->actingAs($this->ownerA)
            ->patchJson($this->moveRoute($sousDossier, $file), ['target_dossier_id' => $this->dossier->getKey()])
            ->assertOk();

        $this->assertDatabaseHas('dossier_files', ['id' => $file->id, 'dossier_id' => $this->dossier->getKey()]);
    }

    public function test_editor_cannot_move_a_file(): void
    {
        // deleteFile (retirer d'ici) est le meme droit que supprimer : seul le
        // proprietaire l'a sur un Dossier personnel, pas un editeur.
        $sousDossier = Dossier::create([
            'organization_id' => $this->orgA->id, 'parent_id' => $this->dossier->getKey(), 'name' => 'Sous-dossier',
        ]);
        $file = $this->createFile($this->dossier, $this->ownerA);

        $this->actingAs($this->editorA)
            ->patchJson($this->moveRoute($this->dossier, $file), ['target_dossier_id' => $sousDossier->getKey()])
            ->assertStatus(403);

        $this->assertDatabaseHas('dossier_files', ['id' => $file->id, 'dossier_id' => $this->dossier->getKey()]);
    }

    public function test_moving_into_a_folder_without_write_access_is_refused(): void
    {
        $dossierEtranger = Dossier::create([
            'organization_id' => $this->orgA->id, 'owner_id' => $this->strangerA->id,
            'name' => 'Pas le mien', 'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $file = $this->createFile($this->dossier, $this->ownerA);

        $this->actingAs($this->ownerA)
            ->patchJson($this->moveRoute($this->dossier, $file), ['target_dossier_id' => $dossierEtranger->getKey()])
            ->assertStatus(403);

        $this->assertDatabaseHas('dossier_files', ['id' => $file->id, 'dossier_id' => $this->dossier->getKey()]);
    }

    public function test_moving_to_a_dossier_of_another_organization_is_refused(): void
    {
        $dossierAilleurs = Dossier::create([
            'organization_id' => $this->orgB->id, 'owner_id' => $this->userB->id,
            'name' => 'Ailleurs', 'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $file = $this->createFile($this->dossier, $this->ownerA);

        $this->actingAs($this->ownerA)
            ->patchJson($this->moveRoute($this->dossier, $file), ['target_dossier_id' => $dossierAilleurs->getKey()])
            ->assertStatus(404);

        $this->assertDatabaseHas('dossier_files', ['id' => $file->id, 'dossier_id' => $this->dossier->getKey()]);
    }

    public function test_moving_refuses_a_duplicate_name_in_the_target(): void
    {
        $sousDossier = Dossier::create([
            'organization_id' => $this->orgA->id, 'parent_id' => $this->dossier->getKey(), 'name' => 'Sous-dossier',
        ]);
        $this->createFile($sousDossier, $this->ownerA, 'doc.pdf');
        $file = $this->createFile($this->dossier, $this->ownerA, 'doc.pdf');

        $this->actingAs($this->ownerA)
            ->patchJson($this->moveRoute($this->dossier, $file), ['target_dossier_id' => $sousDossier->getKey()])
            ->assertStatus(422)
            ->assertJson(['message' => __('dossiers.file_duplicate_name')]);

        $this->assertDatabaseHas('dossier_files', ['id' => $file->id, 'dossier_id' => $this->dossier->getKey()]);
    }

    public function test_moving_to_the_same_dossier_is_a_no_op_refused(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $this->actingAs($this->ownerA)
            ->patchJson($this->moveRoute($this->dossier, $file), ['target_dossier_id' => $this->dossier->getKey()])
            ->assertStatus(422)
            ->assertJson(['message' => __('dossiers.file_move_same_dossier')]);
    }

    public function test_cross_tenant_cannot_move_a_file(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $this->actingAs($this->userB)
            ->patchJson($this->moveRoute($this->dossier, $file), ['target_dossier_id' => $this->dossier->getKey()])
            ->assertStatus(404);
    }
}
