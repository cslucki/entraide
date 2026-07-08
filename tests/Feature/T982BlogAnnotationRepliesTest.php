<?php

namespace Tests\Feature;

use App\Models\BlogAnnotationReply;
use App\Models\BlogPost;
use App\Models\BlogPostAnnotation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class T982BlogAnnotationRepliesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $coauthor;

    private User $otherUser;

    private User $crossUser;

    private BlogPost $post;

    private BlogPostAnnotation $annotation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();

        $this->owner = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->coauthor = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->otherUser = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);

        $this->crossUser = User::factory()->create();

        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Test Post',
            'content' => 'Test content for annotations.',
            'summary' => 'Test',
            'status' => 'draft',
        ]);

        $this->post->coAuthors()->attach($this->coauthor->id, ['added_by' => $this->owner->id]);

        app()['current_organization'] = $this->org;

        $this->annotation = BlogPostAnnotation::create([
            'blog_post_id' => $this->post->id,
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'selected_text' => 'Test content',
            'content' => 'Owner annotation',
        ]);
    }

    // ─── store ───

    public function test_owner_can_create_reply(): void
    {
        $this->actingAs($this->owner);

        $resp = $this->post(route('blog.annotations.replies.store', [
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]), ['content' => 'Merci pour cette note.']);

        $resp->assertStatus(201);
        $this->assertDatabaseHas('blog_annotation_replies', [
            'annotation_id' => $this->annotation->id,
            'user_id' => $this->owner->id,
            'content' => 'Merci pour cette note.',
        ]);
    }

    public function test_coauthor_can_create_reply(): void
    {
        $this->actingAs($this->coauthor);

        $resp = $this->post(route('blog.annotations.replies.store', [
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]), ['content' => 'Je confirme.']);

        $resp->assertStatus(201);
        $this->assertDatabaseHas('blog_annotation_replies', ['content' => 'Je confirme.']);
    }

    public function test_non_coauthor_cannot_create_reply(): void
    {
        $this->actingAs($this->otherUser);

        $resp = $this->post(route('blog.annotations.replies.store', [
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]), ['content' => 'Spam.']);

        $resp->assertStatus(403);
        $this->assertDatabaseMissing('blog_annotation_replies', ['content' => 'Spam.']);
    }

    public function test_guest_cannot_create_reply(): void
    {
        $resp = $this->post(route('blog.annotations.replies.store', [
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]), ['content' => 'Hack.']);

        $resp->assertStatus(302);
    }

    public function test_cross_org_user_cannot_create_reply(): void
    {
        $this->actingAs($this->crossUser);

        $resp = $this->post(route('blog.annotations.replies.store', [
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]), ['content' => 'Sneaky.']);

        $resp->assertStatus(403);
        $this->assertDatabaseMissing('blog_annotation_replies', ['content' => 'Sneaky.']);
    }

    public function test_reply_requires_content(): void
    {
        $this->actingAs($this->owner);

        $resp = $this->postJson(route('blog.annotations.replies.store', [
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]), ['content' => '']);

        $resp->assertStatus(422);
    }

    public function test_reply_max_5000_characters(): void
    {
        $this->actingAs($this->owner);

        $resp = $this->postJson(route('blog.annotations.replies.store', [
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]), ['content' => str_repeat('A', 5001)]);

        $resp->assertStatus(422);
    }

    // ─── index ───

    public function test_lists_replies_for_annotation(): void
    {
        $this->actingAs($this->owner);

        $reply = BlogAnnotationReply::create([
            'annotation_id' => $this->annotation->id,
            'user_id' => $this->coauthor->id,
            'content' => 'Réponse de test',
        ]);

        $resp = $this->getJson(route('blog.annotations.replies.index', [
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]));

        $resp->assertStatus(200);
        $resp->assertJsonFragment(['content' => 'Réponse de test']);
    }

    // ─── update ───

    public function test_reply_author_can_update_own_reply(): void
    {
        $this->actingAs($this->coauthor);

        $reply = BlogAnnotationReply::create([
            'annotation_id' => $this->annotation->id,
            'user_id' => $this->coauthor->id,
            'content' => 'Original.',
        ]);

        $resp = $this->put(route('blog.annotations.replies.update', [
            'post' => $this->post,
            'annotation' => $this->annotation,
            'reply' => $reply,
        ]), ['content' => 'Modifié.']);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('blog_annotation_replies', [
            'id' => $reply->id,
            'content' => 'Modifié.',
        ]);
    }

    public function test_admin_can_update_any_reply(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $reply = BlogAnnotationReply::create([
            'annotation_id' => $this->annotation->id,
            'user_id' => $this->coauthor->id,
            'content' => 'Original.',
        ]);

        $resp = $this->put(route('blog.annotations.replies.update', [
            'post' => $this->post,
            'annotation' => $this->annotation,
            'reply' => $reply,
        ]), ['content' => 'Par admin.']);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('blog_annotation_replies', [
            'id' => $reply->id,
            'content' => 'Par admin.',
        ]);
    }

    public function test_other_user_cannot_update_reply(): void
    {
        $this->actingAs($this->otherUser);

        $reply = BlogAnnotationReply::create([
            'annotation_id' => $this->annotation->id,
            'user_id' => $this->coauthor->id,
            'content' => 'Original.',
        ]);

        $resp = $this->put(route('blog.annotations.replies.update', [
            'post' => $this->post,
            'annotation' => $this->annotation,
            'reply' => $reply,
        ]), ['content' => 'Nope.']);

        $resp->assertStatus(403);
    }

    // ─── destroy ───

    public function test_reply_author_can_delete_own_reply(): void
    {
        $this->actingAs($this->coauthor);

        $reply = BlogAnnotationReply::create([
            'annotation_id' => $this->annotation->id,
            'user_id' => $this->coauthor->id,
            'content' => 'Original.',
        ]);

        $resp = $this->delete(route('blog.annotations.replies.destroy', [
            'post' => $this->post,
            'annotation' => $this->annotation,
            'reply' => $reply,
        ]));

        $resp->assertStatus(204);
        $this->assertDatabaseMissing('blog_annotation_replies', ['id' => $reply->id]);
    }

    public function test_admin_can_delete_any_reply(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $reply = BlogAnnotationReply::create([
            'annotation_id' => $this->annotation->id,
            'user_id' => $this->coauthor->id,
            'content' => 'Original.',
        ]);

        $resp = $this->delete(route('blog.annotations.replies.destroy', [
            'post' => $this->post,
            'annotation' => $this->annotation,
            'reply' => $reply,
        ]));

        $resp->assertStatus(204);
        $this->assertDatabaseMissing('blog_annotation_replies', ['id' => $reply->id]);
    }

    public function test_other_user_cannot_delete_reply(): void
    {
        $this->actingAs($this->otherUser);

        $reply = BlogAnnotationReply::create([
            'annotation_id' => $this->annotation->id,
            'user_id' => $this->coauthor->id,
            'content' => 'Original.',
        ]);

        $resp = $this->delete(route('blog.annotations.replies.destroy', [
            'post' => $this->post,
            'annotation' => $this->annotation,
            'reply' => $reply,
        ]));

        $resp->assertStatus(403);
    }

    // ─── org scoped ───

    public function test_org_scoped_owner_can_create_reply(): void
    {
        $this->actingAs($this->owner);
        $orgSlug = $this->org->slug;

        $resp = $this->post(route('organization.blog.annotations.replies.store', [
            'organization' => $orgSlug,
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]), ['content' => 'Org reply.']);

        $resp->assertStatus(201);
        $this->assertDatabaseHas('blog_annotation_replies', ['content' => 'Org reply.']);
    }

    public function test_org_scoped_cross_org_user_cannot_create_reply(): void
    {
        $this->actingAs($this->crossUser);
        $orgSlug = $this->org->slug;

        $resp = $this->post(route('organization.blog.annotations.replies.store', [
            'organization' => $orgSlug,
            'post' => $this->post,
            'annotation' => $this->annotation,
        ]), ['content' => 'Bad.']);

        $resp->assertStatus(403);
        $this->assertDatabaseMissing('blog_annotation_replies', ['content' => 'Bad.']);
    }

    public function test_serialize_returns_replies_in_annotation_list(): void
    {
        $this->actingAs($this->owner);

        BlogAnnotationReply::create([
            'annotation_id' => $this->annotation->id,
            'user_id' => $this->coauthor->id,
            'content' => 'Reply in list.',
        ]);

        $resp = $this->getJson(route('blog.annotations.index', ['post' => $this->post]));

        $resp->assertStatus(200);
        $resp->assertJsonFragment(['content' => 'Reply in list.']);
        $resp->assertJsonStructure([
            'annotations' => [
                '*' => ['replies' => [['id', 'content', 'author_name', 'author_id', 'created_at', 'created_at_human', 'can_edit', 'can_delete']]],
            ],
        ]);
    }
}
