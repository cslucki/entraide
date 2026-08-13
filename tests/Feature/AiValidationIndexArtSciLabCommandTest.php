<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierArticleChunks;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

class AiValidationIndexArtSciLabCommandTest extends TestCase
{
    public function test_command_requires_ai_validation_environment(): void
    {
        $this->app->detectEnvironment(fn (): string => 'testing');
        Bus::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cette commande exige APP_ENV=ai-validation.');

        $this->artisan('ai-validation:index-artscilab');
    }

    public function test_command_dispatches_the_existing_pipeline_only_for_artscilab(): void
    {
        $this->app->detectEnvironment(fn (): string => 'ai-validation');
        $this->configureSafeGuard();
        $this->configureOpenRouter();
        Bus::fake();
        [$organization, $dossier, $post] = $this->linkedArticle('artscilab-demo');
        $this->linkedArticle('another-tenant');

        $this->artisan('ai-validation:index-artscilab')
            ->expectsOutputToContain('Maximum d’invocations SDK externes : 1/25')
            ->assertSuccessful();

        Bus::assertDispatched(IndexDossierArticleChunks::class, fn (IndexDossierArticleChunks $job): bool => $job->organizationId === $organization->id
            && $job->dossierId === $dossier->id
            && $job->blogPostId === $post->id
            && filled($job->correlationId));
        Bus::assertDispatchedTimes(IndexDossierArticleChunks::class, 1);
    }

    public function test_command_refuses_to_queue_jobs_without_openrouter_credential(): void
    {
        $this->app->detectEnvironment(fn (): string => 'ai-validation');
        $this->configureSafeGuard();
        $this->configureOpenRouter();
        config()->set('ai.providers.openrouter.key', null);
        Bus::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPENROUTER_API_KEY manque');

        $this->artisan('ai-validation:index-artscilab');
    }

    private function configureSafeGuard(): void
    {
        config()->set('database.connections.bouclepro_ai_validation', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'database' => 'bouclepro_ai_validation',
        ]);
    }

    private function configureOpenRouter(): void
    {
        config()->set('ai.default_for_embeddings', 'openrouter');
        config()->set('ai.providers.openrouter.key', 'fake-openrouter-key');
        config()->set('ai.providers.openrouter.models.embeddings.default', 'openai/text-embedding-3-small');
        config()->set('ai.providers.openrouter.models.embeddings.dimensions', 1536);
        config()->set('ai.providers.openai.key', null);
    }

    /** @return array{Organization, Dossier, BlogPost} */
    private function linkedArticle(string $slug): array
    {
        $organization = Organization::factory()->create(['slug' => $slug]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $user->id,
            'name' => 'Validation dossier '.$slug,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $post = BlogPost::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'title' => 'Validation post '.$slug,
            'slug' => 'validation-post-'.$slug,
            'content' => '<p>Validation content</p>',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        DossierBlogPost::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => $post->id,
            'added_by' => $user->id,
            'position' => 1,
        ]);

        return [$organization, $dossier, $post];
    }
}
