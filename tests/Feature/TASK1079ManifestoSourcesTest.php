<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopManifestoSource;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopManifestoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manifesto publication and its links to Dossiers documents.
 *
 * The product rule under test: the Manifesto carries the text, Dossiers keeps
 * the documents, and linking one to the other never copies, moves or re-owns
 * a file.
 */
class TASK1079ManifestoSourcesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'visibility' => 'public',
        ]);
        LoopMember::factory()->owner()->create(['loop_id' => $this->loop->id, 'user_id' => $this->owner->id]);
        app()->instance('current_organization', $this->org);
    }

    private function service(): LoopManifestoService
    {
        return app(LoopManifestoService::class);
    }

    private function manifesto(string $status = 'draft'): BlogPost
    {
        $post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Manifeste de la Boucle',
            'slug' => 'manifeste-'.uniqid(),
            'content' => 'Notre raison d\'être.',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
        $this->loop->forceFill(['manifesto_blog_post_id' => $post->id])->save();

        return $post;
    }

    private function file(?Organization $org = null): DossierFile
    {
        $org ??= $this->org;
        $dossier = Dossier::factory()->create(['organization_id' => $org->id, 'owner_id' => $this->owner->id]);

        return DossierFile::factory()->create([
            'organization_id' => $org->id, 'dossier_id' => $dossier->id, 'uploaded_by' => $this->owner->id,
        ]);
    }

    // ── Publication ─────────────────────────────────────────────────────────

    public function test_a_new_manifesto_starts_as_a_draft(): void
    {
        $manifesto = $this->manifesto();

        $this->assertSame('draft', $manifesto->status);
        $this->assertFalse($this->loop->fresh()->hasPublicManifesto());
    }

    public function test_publishing_is_an_explicit_action(): void
    {
        $this->manifesto();

        $this->assertTrue($this->service()->publish($this->loop));
        $this->assertSame('published', $this->loop->fresh()->manifesto->status);
        $this->assertTrue($this->loop->fresh()->hasPublicManifesto());
    }

    public function test_publishing_twice_is_a_no_op(): void
    {
        $this->manifesto('published');

        $this->assertFalse($this->service()->publish($this->loop->fresh()));
    }

    public function test_going_back_to_draft_removes_it_from_public_view(): void
    {
        $this->manifesto('published');

        $this->assertTrue($this->service()->unpublish($this->loop->fresh()));
        $this->assertSame('draft', $this->loop->fresh()->manifesto->status);
        $this->assertFalse($this->loop->fresh()->hasPublicManifesto());
    }

    public function test_a_loop_without_a_manifesto_has_nothing_public(): void
    {
        $this->assertFalse($this->loop->hasPublicManifesto());
        $this->assertFalse($this->service()->publish($this->loop));
    }

    public function test_a_private_loop_never_exposes_its_manifesto_even_published(): void
    {
        $this->manifesto('published');
        $this->loop->update(['visibility' => 'private']);

        // Confidentiality of the Loop wins over the publication state.
        $this->assertFalse($this->loop->fresh()->hasPublicManifesto());
    }

    // ── Rattachement de sources ─────────────────────────────────────────────

    public function test_a_document_can_be_linked_as_a_source(): void
    {
        $file = $this->file();

        $outcome = $this->service()->attachSource($this->loop, $file->id, $this->owner);

        $this->assertSame(LoopManifestoService::RESULT_ATTACHED, $outcome['result']);
        $this->assertDatabaseHas('loop_manifesto_sources', [
            'loop_id' => $this->loop->id, 'dossier_file_id' => $file->id, 'added_by' => $this->owner->id,
        ]);
    }

    public function test_linking_never_copies_moves_or_reowns_the_file(): void
    {
        $file = $this->file();
        $before = $file->only(['organization_id', 'dossier_id', 'uploaded_by', 'disk', 'path', 'checksum_sha256']);

        $this->service()->attachSource($this->loop, $file->id, $this->owner);

        $this->assertSame($before, $file->fresh()->only(array_keys($before)));
        // One row of link, and still exactly one file.
        $this->assertSame(1, DossierFile::where('id', $file->id)->count());
        $this->assertSame(1, DossierFile::where('checksum_sha256', $file->checksum_sha256)->count());
    }

    public function test_linking_the_same_document_twice_creates_no_duplicate(): void
    {
        $file = $this->file();

        $first = $this->service()->attachSource($this->loop, $file->id, $this->owner);
        $second = $this->service()->attachSource($this->loop, $file->id, $this->owner);

        $this->assertSame(LoopManifestoService::RESULT_ATTACHED, $first['result']);
        $this->assertSame(LoopManifestoService::RESULT_ALREADY_ATTACHED, $second['result']);
        $this->assertSame(1, LoopManifestoSource::where('loop_id', $this->loop->id)->count());
    }

    public function test_a_document_of_another_organization_is_refused(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreign = $this->file($otherOrg);

        $outcome = $this->service()->attachSource($this->loop, $foreign->id, $this->owner);

        $this->assertSame(LoopManifestoService::RESULT_CROSS_TENANT, $outcome['result']);
        $this->assertSame(0, LoopManifestoSource::where('loop_id', $this->loop->id)->count());
    }

    public function test_an_unknown_document_is_refused(): void
    {
        $outcome = $this->service()->attachSource($this->loop, (string) Str::uuid(), $this->owner);

        $this->assertSame(LoopManifestoService::RESULT_NOT_FOUND, $outcome['result']);
    }

    public function test_detaching_removes_the_link_and_keeps_the_document(): void
    {
        $file = $this->file();
        $this->service()->attachSource($this->loop, $file->id, $this->owner);

        $this->assertTrue($this->service()->detachSource($this->loop, $file->id));
        $this->assertSame(0, LoopManifestoSource::where('loop_id', $this->loop->id)->count());
        $this->assertNotNull(DossierFile::find($file->id), 'Detaching must never delete the document');
    }

    public function test_a_soft_deleted_document_does_not_break_the_card(): void
    {
        $file = $this->file();
        $this->service()->attachSource($this->loop, $file->id, $this->owner);

        $file->delete();

        // The row may survive a soft delete; listing must simply skip it rather
        // than blow up on a null relation.
        $this->assertCount(0, $this->service()->sourcesFor($this->loop->fresh()));
    }

    public function test_a_hard_deleted_document_takes_its_link_with_it(): void
    {
        $file = $this->file();
        $this->service()->attachSource($this->loop, $file->id, $this->owner);

        $file->forceDelete();

        $this->assertSame(0, LoopManifestoSource::where('loop_id', $this->loop->id)->count());
    }

    public function test_candidates_are_scoped_to_the_organization_and_exclude_linked_ones(): void
    {
        $otherOrg = Organization::factory()->create();
        $mine = $this->file();
        $linked = $this->file();
        $foreign = $this->file($otherOrg);
        $this->service()->attachSource($this->loop, $linked->id, $this->owner);

        $ids = $this->service()->candidateFiles($this->loop)->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($linked->id, $ids, 'An already linked document must not be offered again');
        $this->assertNotContains($foreign->id, $ids, 'A document of another Organization must never be offered');
    }

    public function test_sources_survive_a_change_of_designated_manifesto(): void
    {
        $file = $this->file();
        $this->manifesto();
        $this->service()->attachSource($this->loop, $file->id, $this->owner);

        // Anchored on the Loop, not on the article: clearing the designation
        // must not throw the gathered sources away.
        $this->loop->forceFill(['manifesto_blog_post_id' => null])->save();

        $this->assertCount(1, $this->service()->sourcesFor($this->loop->fresh()));
    }
}
