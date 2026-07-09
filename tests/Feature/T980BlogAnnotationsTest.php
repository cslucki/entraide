<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\BlogPostAnnotation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class T980BlogAnnotationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $coAuthor;

    private User $otherUser;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $this->owner = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->coAuthor = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->otherUser = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Test Post',
            'content' => 'Test content for annotations.',
            'summary' => 'Test',
            'status' => 'draft',
        ]);

        $this->post->coAuthors()->attach($this->coAuthor->id, ['added_by' => $this->owner->id]);

        $this->actingAs($this->owner);

        app()['current_organization'] = $this->org;
    }

    public function test_owner_can_list_annotations(): void
    {
        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'Test content',
            'content' => 'Owner annotation',
        ]);

        $response = $this->getJson(route('blog.annotations.index', $this->post));
        $response->assertOk();
        $response->assertJsonCount(1, 'annotations');
        $response->assertJsonPath('annotations.0.content', 'Owner annotation');
        $response->assertJsonPath('annotations.0.selected_text', 'Test content');
    }

    public function test_owner_can_create_annotation(): void
    {
        $response = $this->postJson(route('blog.annotations.store', $this->post), [
            'selected_text' => 'selected passage',
            'content' => 'My annotation content',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('annotation.selected_text', 'selected passage');
        $response->assertJsonPath('annotation.content', 'My annotation content');
        $response->assertJsonPath('annotation.status', 'open');
        $response->assertJsonPath('annotation.can_edit', true);
        $response->assertJsonPath('annotation.can_delete', true);
        $response->assertJsonPath('annotation.can_resolve', true);
        $response->assertJsonPath('annotation.author_name', $this->owner->fullName);
        $this->assertDatabaseHas('blog_post_annotations', [
            'blog_post_id' => $this->post->id,
            'selected_text' => 'selected passage',
        ]);
    }

    public function test_coauthor_can_list_annotations(): void
    {
        BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'text',
            'content' => 'test',
        ]);

        $this->actingAs($this->coAuthor);
        app()['current_organization'] = $this->org;

        $response = $this->getJson(route('blog.annotations.index', $this->post));
        $response->assertOk();
        $response->assertJsonCount(1, 'annotations');
    }

    public function test_coauthor_can_create_annotation(): void
    {
        $this->actingAs($this->coAuthor);
        app()['current_organization'] = $this->org;

        $response = $this->postJson(route('blog.annotations.store', $this->post), [
            'selected_text' => 'coauthor selection',
            'content' => 'Co-author note',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('annotation.author_name', $this->coAuthor->fullName);
        $response->assertJsonPath('annotation.can_edit', true);
        $response->assertJsonPath('annotation.can_delete', true);
        $response->assertJsonPath('annotation.can_resolve', false);
    }

    public function test_non_coauthor_cannot_access_annotations(): void
    {
        $this->actingAs($this->otherUser);
        app()['current_organization'] = $this->org;

        $response = $this->getJson(route('blog.annotations.index', $this->post));
        $response->assertForbidden();

        $response = $this->postJson(route('blog.annotations.store', $this->post), [
            'selected_text' => 'text',
            'content' => 'content',
        ]);
        $response->assertForbidden();
    }

    public function test_cross_org_is_forbidden(): void
    {
        $otherOrg = Organization::factory()->create(['slug' => 'cross-org-annot']);
        $otherPost = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $otherOrg->id,
            'title' => 'Other Org Post',
            'content' => 'Cross org content',
            'summary' => 'Test',
        ]);
        app()['current_organization'] = $otherOrg;

        $this->actingAs($this->owner);

        $response = $this->getJson(route('blog.annotations.index', $otherPost));
        $response->assertOk();
    }

    public function test_annotation_stores_selected_text(): void
    {
        $response = $this->postJson(route('blog.annotations.store', $this->post), [
            'selected_text' => 'specific passage',
            'content' => 'note about passage',
            'start_offset' => 5,
            'end_offset' => 20,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('annotation.selected_text', 'specific passage');
        $response->assertJsonPath('annotation.start_offset', 5);
        $response->assertJsonPath('annotation.end_offset', 20);
    }

    public function test_author_can_update_own_annotation(): void
    {
        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'original',
            'content' => 'Original content',
        ]);

        $response = $this->putJson(route('blog.annotations.update', [$this->post, $annotation]), [
            'content' => 'Updated content',
        ]);

        $response->assertOk();
        $response->assertJsonPath('annotation.content', 'Updated content');
        $response->assertJsonPath('annotation.can_edit', true);
        $this->assertDatabaseHas('blog_post_annotations', [
            'id' => $annotation->id,
            'content' => 'Updated content',
        ]);
    }

    public function test_author_can_delete_own_annotation(): void
    {
        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'text',
            'content' => 'To delete',
        ]);

        $response = $this->deleteJson(route('blog.annotations.destroy', [$this->post, $annotation]));
        $response->assertOk();
        $this->assertDatabaseMissing('blog_post_annotations', ['id' => $annotation->id]);
    }

    public function test_other_coauthor_cannot_update_or_delete_annotation(): void
    {
        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'text',
            'content' => 'Owner annotation',
        ]);

        $this->actingAs($this->coAuthor);
        app()['current_organization'] = $this->org;

        $response = $this->putJson(route('blog.annotations.update', [$this->post, $annotation]), [
            'content' => 'Hacked content',
        ]);
        $response->assertForbidden();

        $response = $this->deleteJson(route('blog.annotations.destroy', [$this->post, $annotation]));
        $response->assertForbidden();
    }

    public function test_owner_can_resolve_annotation(): void
    {
        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->coAuthor->id,
            'selected_text' => 'text',
            'content' => 'Co-author annotation',
        ]);

        $response = $this->patchJson(route('blog.annotations.resolve', [$this->post, $annotation]));

        $response->assertOk();
        $response->assertJsonPath('annotation.status', 'resolved');
        $response->assertJsonPath('annotation.can_resolve', true);
        $response->assertJsonPath('annotation.resolved_by_name', $this->owner->fullName);

        $this->assertDatabaseHas('blog_post_annotations', [
            'id' => $annotation->id,
            'status' => 'resolved',
            'resolved_by' => $this->owner->id,
        ]);
    }

    public function test_coauthor_cannot_resolve_annotation(): void
    {
        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'text',
            'content' => 'Owner annotation',
        ]);

        $this->actingAs($this->coAuthor);
        app()['current_organization'] = $this->org;

        $response = $this->patchJson(route('blog.annotations.resolve', [$this->post, $annotation]));
        $response->assertForbidden();
    }

    public function test_admin_can_resolve_annotation(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
        ]);

        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->coAuthor->id,
            'selected_text' => 'text',
            'content' => 'test',
        ]);

        $this->actingAs($admin);
        app()['current_organization'] = $this->org;

        $response = $this->patchJson(route('blog.annotations.resolve', [$this->post, $annotation]));
        $response->assertOk();
        $response->assertJsonPath('annotation.status', 'resolved');
    }

    public function test_json_contains_permissions(): void
    {
        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'text',
            'content' => 'test',
        ]);

        $response = $this->getJson(route('blog.annotations.index', $this->post));
        $response->assertOk();
        $response->assertJsonStructure([
            'annotations' => [
                '*' => [
                    'id',
                    'selected_text',
                    'content',
                    'status',
                    'author_name',
                    'created_at',
                    'created_at_human',
                    'can_edit',
                    'can_delete',
                    'can_resolve',
                ],
            ],
        ]);
    }

    public function test_status_filter_open_and_resolved(): void
    {
        BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'open one',
            'content' => 'Open',
            'status' => 'open',
        ]);

        BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'resolved one',
            'content' => 'Resolved',
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $this->owner->id,
        ]);

        $response = $this->getJson(route('blog.annotations.index', $this->post));
        $response->assertOk();
        $response->assertJsonCount(2, 'annotations');

        $statuses = collect($response->json('annotations'))->pluck('status')->unique()->sort()->values();
        $this->assertEquals(['open', 'resolved'], $statuses->toArray());
    }

    public function test_admin_can_delete_any_annotation(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
        ]);

        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->coAuthor->id,
            'selected_text' => 'text',
            'content' => 'Co-author annotation',
        ]);

        $this->actingAs($admin);
        app()['current_organization'] = $this->org;

        $response = $this->deleteJson(route('blog.annotations.destroy', [$this->post, $annotation]));
        $response->assertOk();
        $this->assertDatabaseMissing('blog_post_annotations', ['id' => $annotation->id]);
    }

    public function test_admin_can_update_any_annotation(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
        ]);

        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->coAuthor->id,
            'selected_text' => 'text',
            'content' => 'Original',
        ]);

        $this->actingAs($admin);
        app()['current_organization'] = $this->org;

        $response = $this->putJson(route('blog.annotations.update', [$this->post, $annotation]), [
            'content' => 'Admin edited',
        ]);
        $response->assertOk();
        $response->assertJsonPath('annotation.content', 'Admin edited');
    }

    public function test_validation_requires_selected_text_and_content(): void
    {
        $response = $this->postJson(route('blog.annotations.store', $this->post), []);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['selected_text', 'content']);
    }

    public function test_annotation_span_preserved_after_content_sanitization(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $html = '<p>Some text <span data-annotation-id="'.$uuid.'" class="bp-annotation-mark">annotated passage</span> continues.</p>';
        $annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'annotated passage',
            'content' => 'A note',
        ]);

        $response = $this->put(
            route('blog.update', $this->post),
            [
                'title' => $this->post->title,
                'summary' => $this->post->summary,
                'content' => $html,
                'status' => 'draft',
                '_token' => csrf_token(),
            ]
        );

        $response->assertRedirect();
        $this->post->refresh();
        $this->assertStringContainsString(
            '<span data-annotation-id="'.$uuid.'" class="bp-annotation-mark">',
            $this->post->content
        );
        $this->assertStringContainsString('annotated passage', $this->post->content);
        $this->assertStringNotContainsString('style=', $this->post->content);
        $this->assertStringNotContainsString('onclick', $this->post->content);
    }

    public function test_non_annotation_span_is_stripped_after_sanitization(): void
    {
        $html = '<p>Text with <span class="some-class" style="color:red" onclick="alert(1)">bad wrapper</span> inside.</p>';

        $response = $this->put(
            route('blog.update', $this->post),
            [
                'title' => $this->post->title,
                'summary' => $this->post->summary,
                'content' => $html,
                'status' => 'draft',
                '_token' => csrf_token(),
            ]
        );

        $response->assertRedirect();
        $this->post->refresh();
        $this->assertStringContainsString('bad wrapper', $this->post->content);
        $this->assertStringNotContainsString('<span', $this->post->content);
        $this->assertStringNotContainsString('style=', $this->post->content);
        $this->assertStringNotContainsString('onclick', $this->post->content);
    }

    public function test_span_with_invalid_annotation_id_is_stripped(): void
    {
        $html = '<p><span data-annotation-id="not-a-uuid" class="bp-annotation-mark">bad id</span></p>';

        $response = $this->put(
            route('blog.update', $this->post),
            [
                'title' => $this->post->title,
                'summary' => $this->post->summary,
                'content' => $html,
                'status' => 'draft',
                '_token' => csrf_token(),
            ]
        );

        $response->assertRedirect();
        $this->post->refresh();
        $this->assertStringContainsString('bad id', $this->post->content);
        $this->assertStringNotContainsString('<span', $this->post->content);
    }

    public function test_annotation_span_without_bp_class_is_stripped(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $html = '<p><span data-annotation-id="'.$uuid.'" class="wrong-class">no bp-mark</span></p>';

        $response = $this->put(
            route('blog.update', $this->post),
            [
                'title' => $this->post->title,
                'summary' => $this->post->summary,
                'content' => $html,
                'status' => 'draft',
                '_token' => csrf_token(),
            ]
        );

        $response->assertRedirect();
        $this->post->refresh();
        $this->assertStringContainsString('no bp-mark', $this->post->content);
        $this->assertStringNotContainsString('<span', $this->post->content);
    }

    public function test_annotation_span_dangerous_attributes_are_removed(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $html = '<p><span data-annotation-id="'.$uuid.'" class="bp-annotation-mark" style="color:red" onclick="evil()" data-custom="xss">safe note</span></p>';

        $response = $this->put(
            route('blog.update', $this->post),
            [
                'title' => $this->post->title,
                'summary' => $this->post->summary,
                'content' => $html,
                'status' => 'draft',
                '_token' => csrf_token(),
            ]
        );

        $response->assertRedirect();
        $this->post->refresh();
        $this->assertStringContainsString('<span data-annotation-id="'.$uuid.'" class="bp-annotation-mark">', $this->post->content);
        $this->assertStringContainsString('safe note', $this->post->content);
        $this->assertStringNotContainsString('style=', $this->post->content);
        $this->assertStringNotContainsString('onclick', $this->post->content);
        $this->assertStringNotContainsString('data-custom', $this->post->content);
    }
}
