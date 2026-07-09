<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T990BlogMediaEmbedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private BlogPost $post;

    private Organization $org;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->category = Category::factory()->create(['organization_id' => $this->org->id]);
    }

    private function createPost(array $attrs = []): BlogPost
    {
        return BlogPost::create(array_merge([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'category_id' => $this->category->id,
            'title' => 'Test Media Embed',
            'slug' => 'test-media-embed',
            'summary' => 'Test media',
            'content' => '<p>Intro</p>',
            'status' => 'published',
            'published_at' => now(),
        ], $attrs));
    }

    public function test_editor_page_renders_media_button(): void
    {
        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->get(route('blog.edit', ['post' => $post]));

        $response->assertOk();
        $response->assertSee(__('blog.editor_media_embed'), false);
    }

    public function test_youtube_embed_html_preserved_on_save(): void
    {
        $embedHtml = '<div data-media-embed style="aspect-ratio: 16 / 9"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="100%" height="100%" frameborder="0" allowfullscreen="true" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" style="width: 100%; height: 100%" title="Embedded media"></iframe></div>';
        $content = '<p>Check this out:</p>'.$embedHtml;

        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->put(route('blog.update', ['post' => $post]), [
            'title' => 'Test Media',
            'summary' => 'Media test',
            'content' => $content,
            'status' => 'published',
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect();
        $post->refresh();

        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $post->content);
        $this->assertStringContainsString('data-media-embed', $post->content);
        $this->assertStringContainsString('aspect-ratio', $post->content);
        $this->assertStringContainsString('allowfullscreen', $post->content);
    }

    public function test_vimeo_embed_html_preserved_on_save(): void
    {
        $embedHtml = '<div data-media-embed style="aspect-ratio: 16 / 9"><iframe src="https://player.vimeo.com/video/123456789" width="100%" height="100%" frameborder="0" allowfullscreen="true" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" style="width: 100%; height: 100%" title="Embedded media"></iframe></div>';
        $content = '<p>Video:</p>'.$embedHtml;

        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->put(route('blog.update', ['post' => $post]), [
            'title' => 'Test Vimeo',
            'summary' => 'Vimeo test',
            'content' => $content,
            'status' => 'published',
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect();
        $post->refresh();

        $this->assertStringContainsString('player.vimeo.com/video/123456789', $post->content);
        $this->assertStringContainsString('data-media-embed', $post->content);
    }

    public function test_unauthorized_iframe_removed(): void
    {
        $evilEmbed = '<div data-media-embed style="aspect-ratio: 16 / 9"><iframe src="https://evil.com/malware" width="100%" height="100%" frameborder="0" allowfullscreen="true"></iframe></div>';
        $content = '<p>Try this:</p>'.$evilEmbed;

        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->put(route('blog.update', ['post' => $post]), [
            'title' => 'Test Evil',
            'summary' => 'Evil test',
            'content' => $content,
            'status' => 'published',
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect();
        $post->refresh();

        $this->assertStringNotContainsString('evil.com', $post->content);
        $this->assertStringNotContainsString('iframe', $post->content);
    }

    public function test_embed_renders_in_published_page(): void
    {
        $embedHtml = '<div data-media-embed style="aspect-ratio: 16 / 9"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="100%" height="100%" frameborder="0" allowfullscreen="true" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" style="width: 100%; height: 100%" title="Embedded media"></iframe></div>';
        $content = '<p>Watch:</p>'.$embedHtml;

        $post = $this->createPost(['content' => $content]);
        $response = $this->get(route('blog.show', ['post' => $post]));

        $response->assertOk();
        $response->assertSee('youtube.com/embed/dQw4w9WgXcQ', false);
        $response->assertSee('data-media-embed', false);
    }

    public function test_dailymotion_embed_preserved(): void
    {
        $embedHtml = '<div data-media-embed style="aspect-ratio: 16 / 9"><iframe src="https://www.dailymotion.com/embed/video/x123abc" width="100%" height="100%" frameborder="0" allowfullscreen="true" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" style="width: 100%; height: 100%" title="Embedded media"></iframe></div>';
        $content = '<p>Dailymotion:</p>'.$embedHtml;

        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->put(route('blog.update', ['post' => $post]), [
            'title' => 'Test Daily',
            'summary' => 'Daily test',
            'content' => $content,
            'status' => 'published',
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect();
        $post->refresh();

        $this->assertStringContainsString('dailymotion.com/embed/video/x123abc', $post->content);
        $this->assertStringContainsString('data-media-embed', $post->content);
    }

    public function test_media_embed_with_nocookie_domain_preserved(): void
    {
        $embedHtml = '<div data-media-embed style="aspect-ratio: 16 / 9"><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" width="100%" height="100%" frameborder="0" allowfullscreen="true" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" style="width: 100%; height: 100%" title="Embedded media"></iframe></div>';
        $content = '<p>Private:</p>'.$embedHtml;

        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->put(route('blog.update', ['post' => $post]), [
            'title' => 'Test Nocookie',
            'summary' => 'Nocookie test',
            'content' => $content,
            'status' => 'published',
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect();
        $post->refresh();

        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $post->content);
    }

    public function test_snapshot_preserves_embed(): void
    {
        $embedHtml = '<div data-media-embed style="aspect-ratio: 16 / 9"><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="100%" height="100%" frameborder="0" allowfullscreen="true" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" style="width: 100%; height: 100%" title="Embedded media"></iframe></div>';
        $content = '<p>Video:</p>'.$embedHtml;

        $post = $this->createPost(['content' => $content]);
        $this->actingAs($this->owner)->put(route('blog.update', ['post' => $post]), [
            'title' => 'Test Updated',
            'summary' => 'Updated',
            'content' => $content.'<p>Added</p>',
            'status' => 'published',
            'category_id' => $this->category->id,
        ]);

        $snapshot = $post->snapshots()->latest()->first();
        $this->assertNotNull($snapshot);
        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $snapshot->content);
        $this->assertStringContainsString('data-media-embed', $snapshot->content);
    }

    public function test_style_stripped_from_non_div_elements(): void
    {
        $malicious = '<p style="color: red">Text</p><span style="font-size: 100px">Big</span>';
        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->put(route('blog.update', ['post' => $post]), [
            'title' => 'Test Style Strip',
            'summary' => 'Style strip',
            'content' => $malicious,
            'status' => 'published',
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect();
        $post->refresh();

        $this->assertStringNotContainsString('style=', $post->content);
    }

    public function test_embed_without_data_attribute_stripped(): void
    {
        $bareDiv = '<div><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe></div>';
        $content = '<p>Watch:</p>'.$bareDiv;

        $post = $this->createPost(['status' => 'draft']);
        $response = $this->actingAs($this->owner)->put(route('blog.update', ['post' => $post]), [
            'title' => 'Test Bare',
            'summary' => 'Bare test',
            'content' => $content,
            'status' => 'published',
            'category_id' => $this->category->id,
        ]);

        $response->assertRedirect();
        $post->refresh();

        $this->assertStringContainsString('youtube.com/embed/dQw4w9WgXcQ', $post->content);
    }
}
