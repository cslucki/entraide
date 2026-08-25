<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopRootDocumentService;
use App\Services\LoopService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Root Dossier and root document.
 *
 * Every Loop owns exactly one Dossier, that Dossier owns exactly one reference
 * document, and the document is live for its Loop from the moment the Loop is
 * created. The user never has to understand that a Blog engine is involved.
 */
class TASK1082RootDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['loops_enabled' => true, 'is_active' => true]);
        $this->user = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);
    }

    private function service(): LoopRootDocumentService
    {
        return app(LoopRootDocumentService::class);
    }

    private function makeLoop(string $type = 'project'): Loop
    {
        return app(LoopService::class)->createLoop($this->user, 'Boucle '.uniqid(), 'Une description courte.', 'private', null, Loop::ACCESS_REQUEST, null, $type);
    }

    // ── Création ────────────────────────────────────────────────────────────

    public function test_creating_a_loop_creates_its_dossier_and_its_document(): void
    {
        $loop = $this->makeLoop();

        $dossier = Dossier::where('loop_id', $loop->id)->first();
        $this->assertNotNull($dossier);
        $this->assertNull($dossier->owner_id, 'A Loop Dossier is held by the Loop, not by a person.');
        $this->assertSame($loop->organization_id, $dossier->organization_id);

        $post = BlogPost::find($dossier->root_blog_post_id);
        $this->assertNotNull($post);
        $this->assertSame('published', $post->status, 'Live for its Loop, with no second Publish step.');
        $this->assertSame(BlogPost::AUDIENCE_LOOP, $post->audience);
        $this->assertFalse((bool) $post->listed_in_blog);
    }

    public function test_the_document_carries_the_label_of_its_type_and_the_description(): void
    {
        $project = $this->makeLoop('project');
        $dialogue = $this->makeLoop('general');

        $this->assertStringContainsString('Manifeste', BlogPost::find(Dossier::where('loop_id', $project->id)->value('root_blog_post_id'))->title);
        $this->assertStringContainsString('Cadre du dialogue', BlogPost::find(Dossier::where('loop_id', $dialogue->id)->value('root_blog_post_id'))->title);

        // The description is copied once, into the introduction, and never
        // synchronised afterwards.
        $content = BlogPost::find(Dossier::where('loop_id', $project->id)->value('root_blog_post_id'))->content;
        $this->assertStringContainsString('Une description courte.', $content);
    }

    public function test_the_document_is_attached_to_the_root_dossier(): void
    {
        $loop = $this->makeLoop();
        $dossier = Dossier::where('loop_id', $loop->id)->first();

        $this->assertDatabaseHas('dossier_blog_posts', [
            'blog_post_id' => $dossier->root_blog_post_id,
            'dossier_id' => $dossier->id,
        ]);
    }

    public function test_the_legacy_designation_is_kept_in_step(): void
    {
        $loop = $this->makeLoop();

        $this->assertSame(
            Dossier::where('loop_id', $loop->id)->value('root_blog_post_id'),
            $loop->fresh()->manifesto_blog_post_id,
        );
    }

    public function test_a_loop_created_from_the_admin_also_gets_its_document(): void
    {
        $loop = app(LoopService::class)->createLoopForOrg($this->user, $this->org->id, 'Boucle admin', null, 'private');

        $this->assertNotNull(Dossier::where('loop_id', $loop->id)->value('root_blog_post_id'));
    }

    // ── Idempotence et concurrence ──────────────────────────────────────────

    public function test_ensuring_twice_creates_nothing_twice(): void
    {
        $loop = $this->makeLoop();

        $this->service()->ensureRootDocument($loop->fresh());
        $this->service()->ensureRootDocument($loop->fresh());

        $this->assertSame(1, Dossier::where('loop_id', $loop->id)->count());
        $this->assertSame(1, BlogPost::where('organization_id', $this->org->id)->where('audience', BlogPost::AUDIENCE_LOOP)->count());
    }

    public function test_a_loop_never_shares_another_loops_dossier(): void
    {
        $a = $this->makeLoop();
        $b = $this->makeLoop();

        $this->assertNotSame(
            Dossier::where('loop_id', $a->id)->value('id'),
            Dossier::where('loop_id', $b->id)->value('id'),
        );
    }

    // ── Invariantes ─────────────────────────────────────────────────────────

    public function test_a_dossier_cannot_have_two_holders(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The XOR constraint only exists on PostgreSQL.');
        }

        $loop = $this->makeLoop();

        $this->expectException(QueryException::class);

        DB::table('dossiers')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->org->id,
            'owner_id' => $this->user->id,
            'loop_id' => $loop->id,
            'name' => 'Deux porteurs',
            'visibility' => 'private',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_dossier_cannot_have_no_holder(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The XOR constraint only exists on PostgreSQL.');
        }

        $this->expectException(QueryException::class);

        DB::table('dossiers')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'loop_id' => null,
            'name' => 'Sans porteur',
            'visibility' => 'private',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_loop_cannot_have_two_root_dossiers(): void
    {
        $loop = $this->makeLoop();

        $this->expectException(QueryException::class);

        DB::table('dossiers')->insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'loop_id' => $loop->id,
            'name' => 'Second Dossier',
            'visibility' => 'private',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_sync_visibility_leaves_a_loop_dossier_alone(): void
    {
        $loop = $this->makeLoop();
        $dossier = Dossier::where('loop_id', $loop->id)->first();

        // It holds no dossier_members, so deriving visibility from them would
        // flip its stored value on every call. L'invariant est que la colonne
        // ne bouge pas — sa valeur stockee est inerte pour un Dossier de
        // Boucle (`effectiveVisibility()` court-circuite), et depuis TASK-1121
        // le service ecrit `private`, plus la valeur historique `shared`.
        $avant = $dossier->visibility;

        $dossier->syncVisibility();

        $this->assertSame($avant, $dossier->fresh()->visibility);
        $this->assertSame(Dossier::VISIBILITY_PRIVATE, $avant);
        $this->assertSame(Dossier::VISIBILITY_LOOP, $dossier->effectiveVisibility());
        $this->assertSame(0, $dossier->dossierMembers()->count());
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_an_article_of_another_organization_cannot_become_the_root_document(): void
    {
        $loop = $this->makeLoop();
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        $foreign = BlogPost::create([
            'user_id' => User::factory()->create(['organization_id' => $otherOrg->id])->id,
            'organization_id' => $otherOrg->id,
            'title' => 'Etranger', 'slug' => 'etranger-'.uniqid(), 'content' => 'x', 'status' => 'draft',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service()->designate($loop, $foreign);
    }

    public function test_the_picker_never_offers_an_article_of_another_organization(): void
    {
        $loop = $this->makeLoop();
        $otherOrg = Organization::factory()->create(['loops_enabled' => true]);
        BlogPost::create([
            'user_id' => User::factory()->create(['organization_id' => $otherOrg->id])->id,
            'organization_id' => $otherOrg->id,
            'title' => 'Invisible', 'slug' => 'invisible-'.uniqid(), 'content' => 'x', 'status' => 'draft',
        ]);

        $this->assertSame(
            0,
            $this->service()->eligibleArticles($loop)->where('organization_id', $otherOrg->id)->count(),
        );
    }

    public function test_the_picker_excludes_another_loops_root_document(): void
    {
        $a = $this->makeLoop();
        $b = $this->makeLoop();

        $rootOfB = Dossier::where('loop_id', $b->id)->value('root_blog_post_id');

        $this->assertNull($this->service()->eligibleArticles($a)->firstWhere('id', $rootOfB));
    }

    // ── Remplacement ────────────────────────────────────────────────────────

    public function test_replacing_keeps_the_previous_document_in_the_dossier(): void
    {
        $loop = $this->makeLoop();
        $former = Dossier::where('loop_id', $loop->id)->value('root_blog_post_id');

        $replacement = BlogPost::create([
            'user_id' => $this->user->id, 'organization_id' => $this->org->id,
            'title' => 'Nouveau', 'slug' => 'nouveau-'.uniqid(), 'content' => 'x', 'status' => 'draft',
        ]);

        $this->service()->replace($loop, $replacement);

        $this->assertSame($replacement->id, Dossier::where('loop_id', $loop->id)->value('root_blog_post_id'));
        $this->assertDatabaseHas('blog_posts', ['id' => $former]);
        $this->assertDatabaseHas('dossier_blog_posts', ['blog_post_id' => $former]);
        $this->assertSame($replacement->id, $loop->fresh()->manifesto_blog_post_id);
    }

    public function test_an_adopted_article_leaves_the_blog_listing(): void
    {
        $loop = $this->makeLoop();
        $article = BlogPost::create([
            'user_id' => $this->user->id, 'organization_id' => $this->org->id,
            'title' => 'Publie ailleurs', 'slug' => 'ailleurs-'.uniqid(), 'content' => 'x',
            'status' => 'published', 'published_at' => now(),
            'audience' => BlogPost::AUDIENCE_PUBLIC, 'listed_in_blog' => true,
        ]);

        $this->service()->designate($loop, $article);

        $fresh = $article->fresh();
        $this->assertSame(BlogPost::AUDIENCE_LOOP, $fresh->audience);
        $this->assertFalse((bool) $fresh->listed_in_blog);
        // Content, history and author are untouched.
        $this->assertSame('Publie ailleurs', $fresh->title);
        $this->assertSame($this->user->id, $fresh->user_id);
    }

    // ── Blog ────────────────────────────────────────────────────────────────

    public function test_a_root_document_never_appears_in_the_blog_listing(): void
    {
        $loop = $this->makeLoop();
        $root = Dossier::where('loop_id', $loop->id)->value('root_blog_post_id');

        $this->assertNull(BlogPost::published()->find($root));
        // But it is still a published article for direct access paths.
        $this->assertNotNull(BlogPost::publiclyReadable()->find($root));
    }

    public function test_an_ordinary_article_keeps_its_generic_behaviour(): void
    {
        $article = BlogPost::create([
            'user_id' => $this->user->id, 'organization_id' => $this->org->id,
            'title' => 'Article normal', 'slug' => 'normal-'.uniqid(), 'content' => 'x',
            'status' => 'published', 'published_at' => now(),
        ]);

        // Defaults unchanged: public and listed, exactly as before.
        $this->assertSame(BlogPost::AUDIENCE_PUBLIC, $article->fresh()->audience);
        $this->assertTrue((bool) $article->fresh()->listed_in_blog);
        $this->assertNotNull(BlogPost::published()->find($article->id));
    }

    // ── Indexation (TASK-1307) ────────────────────────────────────────────

    /**
     * Avant TASK-1307, `designate()` ne dispatchait rien : un document racine
     * (`listed_in_blog = false`) restait invisible du RAG indefiniment, sauf
     * edition humaine ulterieure qui declenche `BlogPostObserver::updated()`.
     * `designate()` est le SEUL endroit qui cree/deplace le lien Article <->
     * Dossier pour un document racine — le meme geste que
     * `DossierArticleController::store()`/`createAndAttach()` doit donc
     * dispatcher la meme indexation.
     */
    public function test_creating_a_loop_dispatches_indexing_for_its_root_document(): void
    {
        Queue::fake();

        $loop = $this->makeLoop();
        $dossier = Dossier::where('loop_id', $loop->id)->firstOrFail();

        Queue::assertPushed(
            IndexDossierArticleChunks::class,
            fn (IndexDossierArticleChunks $job): bool => $job->organizationId === $this->org->id
                && $job->dossierId === $dossier->id
                && $job->blogPostId === $dossier->root_blog_post_id,
        );
    }

    /** `designate()` reassigning the root document dispatches indexing for the newly designated article too. */
    public function test_replacing_the_root_document_dispatches_indexing_for_the_new_one(): void
    {
        $loop = $this->makeLoop();
        $replacement = BlogPost::create([
            'user_id' => $this->user->id, 'organization_id' => $this->org->id,
            'title' => 'Nouveau manifeste', 'slug' => 'nouveau-manifeste-'.uniqid(), 'content' => 'x',
            'status' => 'published', 'published_at' => now(),
        ]);

        Queue::fake();
        $this->service()->replace($loop, $replacement);

        Queue::assertPushed(
            IndexDossierArticleChunks::class,
            fn (IndexDossierArticleChunks $job): bool => $job->blogPostId === $replacement->id,
        );
    }
}
