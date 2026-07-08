<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T985BlogTodoCardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $coAuthor;

    private User $otherUser;

    private BlogPost $post;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->coAuthor = User::factory()->create(['organization_id' => $this->org->id]);
        $this->otherUser = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Test Todo Card Post',
            'content' => 'Test content.',
            'status' => 'draft',
        ]);

        $this->post->coAuthors()->attach($this->coAuthor->id, ['role' => 'coauthor', 'added_by' => $this->owner->id]);
    }

    public function test_owner_can_create_todo(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Ma première tâche',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('todo.title', 'Ma première tâche');
        $response->assertJsonPath('todo.status', 'todo');
        $this->assertDatabaseHas('blog_todos', [
            'blog_post_id' => $this->post->id,
            'title' => 'Ma première tâche',
        ]);
    }

    public function test_coauthor_can_create_todo(): void
    {
        $this->actingAs($this->coAuthor);

        $response = $this->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Tâche co-auteur',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('blog_todos', [
            'blog_post_id' => $this->post->id,
            'title' => 'Tâche co-auteur',
            'user_id' => $this->coAuthor->id,
        ]);
    }

    public function test_non_coauthor_cannot_create_todo(): void
    {
        $this->actingAs($this->otherUser);

        $response = $this->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Tâche non autorisée',
        ]);

        $response->assertForbidden();
    }

    public function test_guest_cannot_create_todo(): void
    {
        $response = $this->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Tâche invité',
        ]);

        $response->assertUnauthorized();
    }

    public function test_assigned_user_can_update_todo(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche à modifier',
            'status' => 'todo',
        ]);

        $response = $this->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", [
            'title' => 'Titre modifié',
            'status' => 'in_progress',
        ]);

        $response->assertOk();
        $response->assertJsonPath('todo.title', 'Titre modifié');
        $response->assertJsonPath('todo.status', 'in_progress');
    }

    public function test_owner_can_update_any_todo(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->otherUser->id,
            'title' => 'Tâche de OtherUser',
            'status' => 'todo',
        ]);

        $response = $this->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", [
            'title' => 'Modification par le owner',
        ]);

        $response->assertOk();
        $response->assertJsonPath('todo.title', 'Modification par le owner');
    }

    public function test_owner_can_delete_any_todo(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->otherUser->id,
            'title' => 'Tâche protégée',
        ]);

        $response = $this->deleteJson("/blog/{$this->post->slug}/todos/{$todo->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('blog_todos', ['id' => $todo->id]);
    }

    public function test_non_editor_cannot_update_todo(): void
    {
        $this->actingAs($this->otherUser);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche owner',
        ]);

        $response = $this->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", [
            'title' => 'Modification interdite',
        ]);

        $response->assertForbidden();
    }

    public function test_non_editor_cannot_delete_todo(): void
    {
        $this->actingAs($this->otherUser);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche protégée',
        ]);

        $response = $this->deleteJson("/blog/{$this->post->slug}/todos/{$todo->id}");

        $response->assertForbidden();
    }

    public function test_assigned_user_can_delete_todo(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche à supprimer',
        ]);

        $response = $this->deleteJson("/blog/{$this->post->slug}/todos/{$todo->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('blog_todos', ['id' => $todo->id]);
    }

    public function test_coauthor_can_update_any_todo(): void
    {
        $this->actingAs($this->coAuthor);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche du owner',
        ]);

        $response = $this->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", [
            'status' => 'done',
        ]);

        $response->assertOk();
        $response->assertJsonPath('todo.status', 'done');
    }

    public function test_assigned_user_can_change_status(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche avec statut',
            'status' => 'todo',
        ]);

        $response = $this->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", [
            'status' => 'done',
        ]);
        $response->assertOk();
        $response->assertJsonPath('todo.status', 'done');

        $response = $this->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", [
            'status' => 'in_progress',
        ]);
        $response->assertOk();
        $response->assertJsonPath('todo.status', 'in_progress');
    }

    public function test_todos_persist_after_reload(): void
    {
        $this->actingAs($this->owner);

        $this->postJson("/blog/{$this->post->slug}/todos", ['title' => 'Tâche persistante']);
        $this->postJson("/blog/{$this->post->slug}/todos", ['title' => 'Tâche persistante 2']);

        $response = $this->getJson("/blog/{$this->post->slug}/todos");
        $response->assertOk();
        $response->assertJsonCount(2, 'todos');
    }

    public function test_index_returns_todos_with_threads(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche avec thread',
        ]);

        $todo->threads()->create([
            'user_id' => $this->owner->id,
            'body' => 'Commentaire de test',
        ]);

        $response = $this->getJson("/blog/{$this->post->slug}/todos");
        $response->assertOk();
        $response->assertJsonPath('todos.0.threads.0.body', 'Commentaire de test');
    }

    public function test_thread_can_be_added(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche thread',
        ]);

        $response = $this->postJson("/blog/{$this->post->slug}/todos/{$todo->id}/threads", [
            'body' => 'Nouveau commentaire',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('thread.body', 'Nouveau commentaire');
        $this->assertDatabaseHas('blog_todo_threads', [
            'todo_id' => $todo->id,
            'body' => 'Nouveau commentaire',
        ]);
    }

    public function test_thread_author_can_delete_thread(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche thread deletion',
        ]);

        $thread = $todo->threads()->create([
            'user_id' => $this->owner->id,
            'body' => 'Commentaire à supprimer',
        ]);

        $response = $this->deleteJson("/blog/{$this->post->slug}/todos/{$todo->id}/threads/{$thread->id}");
        $response->assertOk();
        $this->assertDatabaseMissing('blog_todo_threads', ['id' => $thread->id]);
    }

    public function test_non_author_cannot_delete_thread(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Tâche thread protection',
        ]);

        $thread = $todo->threads()->create([
            'user_id' => $this->coAuthor->id,
            'body' => 'Commentaire de co-auteur',
        ]);

        $this->actingAs($this->owner);

        $response = $this->deleteJson("/blog/{$this->post->slug}/todos/{$todo->id}/threads/{$thread->id}");
        $response->assertForbidden();
    }

    public function test_cross_org_is_blocked(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $otherPost = BlogPost::create([
            'user_id' => $otherUser->id,
            'organization_id' => $otherOrg->id,
            'title' => 'Cross org post',
            'content' => 'Content.',
            'status' => 'draft',
        ]);

        $this->actingAs($this->owner);

        $response = $this->postJson("/blog/{$otherPost->slug}/todos", [
            'title' => 'Cross-org',
        ]);
        $response->assertNotFound();
    }

    public function test_i18n_keys_exist(): void
    {
        $keys = [
            'sidebar_todo', 'todo_title', 'todo_empty', 'todo_create',
            'todo_placeholder', 'todo_status_todo', 'todo_status_in_progress',
            'todo_status_done', 'todo_assign', 'todo_unassigned',
            'todo_created', 'todo_updated', 'todo_deleted', 'todo_not_owner',
            'todo_thread_placeholder', 'todo_thread_add', 'todo_thread_added',
            'todo_thread_deleted', 'todo_thread_not_owner', 'todo_confirm_delete',
        ];

        foreach ($keys as $key) {
            $this->assertNotEmpty(__("blog.{$key}"), "i18n key blog.{$key} is empty or missing");
        }
    }

    public function test_org_scoped_owner_can_create_todo(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/org/{$this->org->slug}/blog/{$this->post->slug}/todos", [
            'title' => 'Tâche org scoped',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('todo.title', 'Tâche org scoped');
    }

    public function test_create_with_assigned_to(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Tâche assignée',
            'assigned_to' => $this->coAuthor->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('todo.assigned_to', $this->coAuthor->id);
        $this->assertDatabaseHas('blog_todos', [
            'blog_post_id' => $this->post->id,
            'title' => 'Tâche assignée',
            'assigned_to' => $this->coAuthor->id,
        ]);
    }

    public function test_change_assignee(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Changement assigné',
        ]);

        $response = $this->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", [
            'assigned_to' => $this->coAuthor->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('todo.assigned_to', $this->coAuthor->id);
        $this->assertDatabaseHas('blog_todos', [
            'id' => $todo->id,
            'assigned_to' => $this->coAuthor->id,
        ]);
    }

    public function test_unassign_todo(): void
    {
        $this->actingAs($this->owner);

        $todo = $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->owner->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Désassignation',
        ]);

        $response = $this->putJson("/blog/{$this->post->slug}/todos/{$todo->id}", [
            'assigned_to' => null,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('blog_todos', [
            'id' => $todo->id,
            'assigned_to' => null,
        ]);
    }

    public function test_invalid_assignee_returns_422(): void
    {
        $this->actingAs($this->owner);

        $response = $this->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Mauvais assigné',
            'assigned_to' => 'non-existent-uuid',
        ]);

        $response->assertStatus(422);
    }
}
