<?php

namespace Tests\Unit\Jobs;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Organization;
use App\Services\Dossiers\DossierArticleIndexer;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class IndexDossierArticleChunksTest extends TestCase
{
    public function test_job_keeps_only_three_uuid_payload_values(): void
    {
        $organization = Organization::factory()->create();
        $dossierId = '00000000-0000-0000-0000-000000000101';
        $blogPostId = '00000000-0000-0000-0000-000000000202';

        $job = new IndexDossierArticleChunks($organization->id, $dossierId, $blogPostId);

        $this->assertSame($organization->id, $job->organizationId);
        $this->assertSame($dossierId, $job->dossierId);
        $this->assertSame($blogPostId, $job->blogPostId);
        $this->assertObjectNotHasProperty('dossier', $job);
        $this->assertObjectNotHasProperty('blogPost', $job);
    }

    public function test_handle_delegates_once_to_indexer(): void
    {
        $indexer = $this->createMock(DossierArticleIndexer::class);
        $indexer->expects($this->once())
            ->method('synchronize')
            ->with('org-1', 'dossier-1', 'post-1')
            ->willReturn(2);

        (new IndexDossierArticleChunks('org-1', 'dossier-1', 'post-1'))->handle($indexer);
    }

    public function test_middleware_contains_without_overlapping(): void
    {
        $middleware = (new IndexDossierArticleChunks('org-1', 'dossier-1', 'post-1'))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_overlap_key_distinguishes_articles(): void
    {
        $first = new IndexDossierArticleChunks('org-1', 'dossier-1', 'post-1');
        $second = new IndexDossierArticleChunks('org-1', 'dossier-1', 'post-2');

        $this->assertNotSame($first->overlapKey(), $second->overlapKey());
        $this->assertSame('dossier-article-index:org-1:dossier-1:post-1', $first->overlapKey());
    }

    public function test_serialized_job_payload_does_not_contain_eloquent_models(): void
    {
        $job = new IndexDossierArticleChunks('org-1', 'dossier-1', 'post-1');
        $serialized = serialize($job);

        $this->assertStringNotContainsString(Dossier::class, $serialized);
        $this->assertStringNotContainsString(BlogPost::class, $serialized);
    }

    public function test_job_contains_no_provider_logic(): void
    {
        $reflection = new \ReflectionClass(IndexDossierArticleChunks::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Embeddings', $source);
        $this->assertStringNotContainsString('OpenAI', $source);
        $this->assertStringNotContainsString('Http::', $source);
    }
}
