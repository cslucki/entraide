<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le compteur d'éléments d'un Dossier doit additionner les fichiers, les
 * Articles ET les sous-dossiers (TASK-1149, LOT B).
 *
 * Régression : `show.blade.php` comptait `files_count + dossier_blog_posts_count`
 * en oubliant `children_count` — un Dossier ne contenant qu'un sous-dossier
 * (0 fichier, 0 Article) affichait « Vide ».
 */
class TASK1149DossierFolderCounterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org A', 'slug' => 'org-a', 'is_active' => true]);
        $this->ownerA = User::factory()->create(['organization_id' => $this->orgA->id]);
    }

    private function dossier(string $name, ?string $parentId = null, ?User $owner = null): Dossier
    {
        return Dossier::create([
            'organization_id' => $this->orgA->id,
            'parent_id' => $parentId,
            'owner_id' => $owner?->id,
            'name' => $name,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function file(Dossier $dossier, User $uploader, string $name): DossierFile
    {
        return DossierFile::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $uploader->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/'.$name,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => 'text/plain',
            'size_bytes' => 3,
            'checksum_sha256' => hash('sha256', 'abc'),
            'source' => 'upload',
        ]);
    }

    private function article(User $author, string $title): BlogPost
    {
        return BlogPost::create([
            'organization_id' => $this->orgA->id,
            'user_id' => $author->id,
            'title' => $title,
            'content' => 'Content.',
            'status' => 'draft',
        ]);
    }

    private function attach(Dossier $dossier, BlogPost $post, User $user, int $position): DossierBlogPost
    {
        return DossierBlogPost::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $user->id,
            'position' => $position,
        ]);
    }

    public function test_folder_with_only_children_shows_non_empty_count(): void
    {
        $racine = $this->dossier('Mes documents', null, $this->ownerA);
        $enfant = $this->dossier('Sous-dossier', $racine->id);
        $petitEnfant = $this->dossier('Petit-fils', $enfant->id);

        $response = $this->actingAs($this->ownerA)
            ->get(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $racine->id]));

        $response->assertOk();
        $response->assertSee('data-folder-count="'.$enfant->id.'" data-count="1"', false);
        $response->assertDontSee('data-folder-count="'.$petitEnfant->id.'"', false);
    }

    public function test_folder_count_sums_files_articles_and_children(): void
    {
        $racine = $this->dossier('Mes documents', null, $this->ownerA);
        $enfant = $this->dossier('Sous-dossier', $racine->id);
        $this->dossier('Petit-fils', $enfant->id);
        $this->file($enfant, $this->ownerA, 'note.txt');
        $this->attach($enfant, $this->article($this->ownerA, 'Article lié'), $this->ownerA, 1);

        $response = $this->actingAs($this->ownerA)
            ->get(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $racine->id]));

        $response->assertOk();
        $response->assertSee('data-folder-count="'.$enfant->id.'" data-count="3"', false);
    }

    public function test_folder_with_no_content_still_shows_zero(): void
    {
        $racine = $this->dossier('Mes documents', null, $this->ownerA);
        $vide = $this->dossier('Dossier vide', $racine->id);

        $response = $this->actingAs($this->ownerA)
            ->get(route('organization.dossiers.show', ['organization' => $this->orgA, 'dossier' => $racine->id]));

        $response->assertOk();
        $response->assertSee('data-folder-count="'.$vide->id.'" data-count="0"', false);
    }
}
