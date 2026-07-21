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
            'files' => [UploadedFile::fake()->create('too-large.pdf', 20481, 'application/pdf')],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('dossier_files', 0);
    }

    public function test_upload_rejects_empty_files(): void
    {
        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), []);

        $response->assertStatus(422);
    }

    public function test_upload_rejects_more_than_five_files(): void
    {
        $files = array_map(fn () => $this->fakeFile('doc'.rand(1, 9).'.pdf'), range(1, 6));

        $response = $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => $files,
        ]);

        $response->assertStatus(422);
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

    public function test_upload_validates_quota(): void
    {
        $this->orgA->update(['dossier_storage_quota_bytes' => 5000]);

        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [$this->fakeFile('big.pdf', 'application/pdf', 6000)],
        ])->assertStatus(422);
    }

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
                $this->fakeFile('photo.jpg', 'image/jpeg'),
                $this->fakeFile('logo.png', 'image/png'),
                $this->fakeFile('banner.webp', 'image/webp'),
                $this->fakeFile('icon.gif', 'image/gif'),
            ],
        ])->assertStatus(201);

        $this->assertEquals(4, DossierFile::where('dossier_id', $this->dossier->id)->count());
    }

    public function test_upload_accepts_doc_types(): void
    {
        $this->actingAs($this->ownerA)->postJson($this->storeRoute($this->dossier), [
            'files' => [
                $this->fakeFile('report.pdf', 'application/pdf'),
                $this->fakeFile('letter.doc', 'application/msword'),
                $this->fakeFile('contract.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
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

    public function test_soft_deleting_dossier_nullifies_file_dossier_id(): void
    {
        $file = $this->createFile($this->dossier, $this->ownerA);

        $this->actingAs($this->ownerA)->deleteJson(route('organization.dossiers.destroy', [
            'organization' => $this->orgA,
            'dossier' => $this->dossier,
        ]))->assertRedirect();

        $this->assertDatabaseHas('dossier_files', [
            'id' => $file->id,
            'dossier_id' => null,
        ]);
        $this->assertDatabaseMissing('dossier_files', [
            'id' => $file->id,
            'dossier_id' => $this->dossier->id,
        ]);
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
}
