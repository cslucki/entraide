<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class T1012BlogTodoCollaborationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $author;

    private User $coAuthor;

    private User $secondCoAuthor;

    private User $outsider;

    private User $otherOrgUser;

    private BlogPost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_default' => true]);
        $this->otherOrg = Organization::factory()->create();

        $this->author = User::factory()->create(['organization_id' => $this->org->id]);
        $this->coAuthor = User::factory()->create(['organization_id' => $this->org->id]);
        $this->secondCoAuthor = User::factory()->create(['organization_id' => $this->org->id]);
        $this->outsider = User::factory()->create(['organization_id' => $this->org->id]);
        $this->otherOrgUser = User::factory()->create(['organization_id' => $this->otherOrg->id]);

        app()->instance('current_organization', $this->org);

        $this->post = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->org->id,
            'title' => 'Collaborative todo post',
            'summary' => 'Summary',
            'content' => 'Original content',
            'status' => 'draft',
        ]);

        $this->post->coAuthors()->attach($this->coAuthor->id, ['role' => 'coauthor', 'added_by' => $this->author->id]);
        $this->post->coAuthors()->attach($this->secondCoAuthor->id, ['role' => 'coauthor', 'added_by' => $this->author->id]);
    }

    public function test_todo_persists_independently_from_blog_post_save(): void
    {
        $originalUpdatedAt = $this->post->updated_at;

        $response = $this->actingAs($this->author)->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Immediate todo',
            'assigned_to' => $this->author->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('todo.title', 'Immediate todo')
            ->assertJsonPath('todo.assigned_to', $this->author->id)
            ->assertJsonPath('todo.can_edit', true)
            ->assertJsonPath('todo.can_assign', true)
            ->assertJsonPath('todo.can_change_status', true)
            ->assertJsonPath('todo.can_complete', true)
            ->assertJsonPath('todo.can_delete', true);

        $this->assertDatabaseHas('blog_todos', [
            'blog_post_id' => $this->post->id,
            'title' => 'Immediate todo',
        ]);
        $this->assertSame('Original content', $this->post->fresh()->content);
        $this->assertTrue($this->post->fresh()->updated_at->equalTo($originalUpdatedAt));

        $this->actingAs($this->coAuthor)->getJson("/blog/{$this->post->slug}/todos")
            ->assertOk()
            ->assertJsonPath('todos.0.title', 'Immediate todo')
            ->assertJsonPath('todos.0.can_edit', false)
            ->assertJsonPath('todos.0.can_assign', false)
            ->assertJsonPath('todos.0.can_change_status', false)
            ->assertJsonPath('todos.0.can_complete', false)
            ->assertJsonPath('todos.0.can_reopen', false)
            ->assertJsonPath('todos.0.can_delete', false);
    }

    public function test_assignable_users_are_restricted_to_author_and_active_coauthors_in_same_organization(): void
    {
        $this->actingAs($this->author)->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Author assigned',
            'assigned_to' => $this->author->id,
        ])->assertCreated()->assertJsonPath('todo.assigned_to', $this->author->id);

        $this->actingAs($this->author)->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Coauthor assigned',
            'assigned_to' => $this->coAuthor->id,
        ])->assertCreated()->assertJsonPath('todo.assigned_to', $this->coAuthor->id);

        $this->actingAs($this->coAuthor)->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Coauthor self assigned',
            'assigned_to' => $this->coAuthor->id,
        ])->assertCreated()->assertJsonPath('todo.assigned_to', $this->coAuthor->id);

        $this->actingAs($this->coAuthor)->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Forbidden coauthor assignment',
            'assigned_to' => $this->author->id,
        ])->assertForbidden();

        $this->actingAs($this->author)->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Outsider assignment',
            'assigned_to' => $this->outsider->id,
        ])->assertUnprocessable();

        $this->actingAs($this->author)->postJson("/blog/{$this->post->slug}/todos", [
            'title' => 'Other org assignment',
            'assigned_to' => $this->otherOrgUser->id,
        ])->assertUnprocessable();
    }

    public function test_complete_and_reopen_are_restricted_to_assigned_user_or_author_for_unassigned_todo(): void
    {
        $authorTodo = $this->todo('Author todo', $this->author->id);
        $coAuthorTodo = $this->todo('Coauthor todo', $this->coAuthor->id);
        $unassignedTodo = $this->todo('Unassigned todo', null);

        $this->actingAs($this->author)->putJson("/blog/{$this->post->slug}/todos/{$authorTodo->id}", ['status' => 'done'])
            ->assertOk()->assertJsonPath('todo.status', 'done');

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$authorTodo->id}", ['status' => 'todo'])
            ->assertForbidden();

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['status' => 'done'])
            ->assertOk()->assertJsonPath('todo.status', 'done');

        $this->actingAs($this->author)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['status' => 'todo'])
            ->assertForbidden();

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['status' => 'todo'])
            ->assertOk()->assertJsonPath('todo.status', 'todo');

        $this->actingAs($this->outsider)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['status' => 'done'])
            ->assertForbidden();

        $this->actingAs($this->author)->putJson("/blog/{$this->post->slug}/todos/{$unassignedTodo->id}", ['status' => 'done'])
            ->assertOk()->assertJsonPath('todo.status', 'done');

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$unassignedTodo->id}", ['status' => 'todo'])
            ->assertForbidden();
    }

    public function test_every_status_mutation_requires_status_ownership(): void
    {
        $authorTodo = $this->todo('Author todo', $this->author->id);
        $coAuthorTodo = $this->todo('Coauthor todo', $this->coAuthor->id);
        $unassignedTodo = $this->todo('Unassigned todo', null);

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$authorTodo->id}", ['status' => 'in_progress'])
            ->assertForbidden();
        $this->assertSame('todo', $authorTodo->fresh()->status);

        $this->actingAs($this->author)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['status' => 'in_progress'])
            ->assertForbidden();
        $this->assertSame('todo', $coAuthorTodo->fresh()->status);

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('todo.status', 'in_progress');
        $this->assertSame('in_progress', $coAuthorTodo->fresh()->status);

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['status' => 'todo'])
            ->assertOk()->assertJsonPath('todo.status', 'todo');
        $this->assertSame('todo', $coAuthorTodo->fresh()->status);

        $coAuthorTodo->update(['status' => 'in_progress']);
        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['status' => 'done'])
            ->assertOk()->assertJsonPath('todo.status', 'done');
        $this->assertSame('done', $coAuthorTodo->fresh()->status);

        $this->actingAs($this->secondCoAuthor)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['status' => 'in_progress'])
            ->assertForbidden();
        $this->assertSame('done', $coAuthorTodo->fresh()->status);

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$authorTodo->id}", [
            'title' => 'Forbidden combined edit',
            'status' => 'in_progress',
        ])->assertForbidden();
        $this->assertSame('Author todo', $authorTodo->fresh()->title);
        $this->assertSame('todo', $authorTodo->fresh()->status);

        $this->actingAs($this->author)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", [
            'assigned_to' => $this->author->id,
            'status' => 'todo',
        ])->assertForbidden();
        $this->assertSame($this->coAuthor->id, $coAuthorTodo->fresh()->assigned_to);
        $this->assertSame('done', $coAuthorTodo->fresh()->status);

        $this->actingAs($this->secondCoAuthor)->putJson("/blog/{$this->post->slug}/todos/{$unassignedTodo->id}", ['status' => 'in_progress'])
            ->assertForbidden();
        $this->assertSame('todo', $unassignedTodo->fresh()->status);

        $this->actingAs($this->author)->putJson("/blog/{$this->post->slug}/todos/{$unassignedTodo->id}", ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('todo.status', 'in_progress');
        $this->assertSame('in_progress', $unassignedTodo->fresh()->status);
    }

    public function test_status_capabilities_match_backend_status_ownership_rule(): void
    {
        $authorTodo = $this->todo('Author todo', $this->author->id);
        $coAuthorTodo = $this->todo('Coauthor todo', $this->coAuthor->id);
        $unassignedTodo = $this->todo('Unassigned todo', null);

        $this->actingAs($this->author)->getJson("/blog/{$this->post->slug}/todos")
            ->assertOk()
            ->assertJsonPath('todos.0.id', $authorTodo->id)
            ->assertJsonPath('todos.0.can_change_status', true)
            ->assertJsonPath('todos.1.id', $coAuthorTodo->id)
            ->assertJsonPath('todos.1.can_change_status', false)
            ->assertJsonPath('todos.2.id', $unassignedTodo->id)
            ->assertJsonPath('todos.2.can_change_status', true);

        $this->actingAs($this->coAuthor)->getJson("/blog/{$this->post->slug}/todos")
            ->assertOk()
            ->assertJsonPath('todos.0.can_change_status', false)
            ->assertJsonPath('todos.1.can_change_status', true)
            ->assertJsonPath('todos.2.can_change_status', false);
    }

    public function test_edit_assignment_and_delete_permissions_are_strict(): void
    {
        $authorTodo = $this->todo('Author todo', $this->author->id);
        $coAuthorTodo = $this->todo('Coauthor todo', $this->coAuthor->id);
        $secondCoAuthorTodo = $this->todo('Second coauthor todo', $this->secondCoAuthor->id);
        $unassignedTodo = $this->todo('Unassigned todo', null);

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['title' => 'Edited by assignee'])
            ->assertOk()->assertJsonPath('todo.title', 'Edited by assignee');

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$authorTodo->id}", ['title' => 'Forbidden edit'])
            ->assertForbidden();

        $this->actingAs($this->coAuthor)->putJson("/blog/{$this->post->slug}/todos/{$coAuthorTodo->id}", ['assigned_to' => $this->secondCoAuthor->id])
            ->assertForbidden();

        $this->actingAs($this->author)->putJson("/blog/{$this->post->slug}/todos/{$authorTodo->id}", ['assigned_to' => $this->coAuthor->id])
            ->assertOk()->assertJsonPath('todo.assigned_to', $this->coAuthor->id);

        $this->actingAs($this->coAuthor)->deleteJson("/blog/{$this->post->slug}/todos/{$authorTodo->id}")
            ->assertOk();

        $this->actingAs($this->coAuthor)->deleteJson("/blog/{$this->post->slug}/todos/{$secondCoAuthorTodo->id}")
            ->assertForbidden();

        $this->actingAs($this->coAuthor)->deleteJson("/blog/{$this->post->slug}/todos/{$unassignedTodo->id}")
            ->assertForbidden();

        $this->actingAs($this->author)->deleteJson("/blog/{$this->post->slug}/todos/{$secondCoAuthorTodo->id}")
            ->assertOk();

        $this->actingAs($this->author)->deleteJson("/blog/{$this->post->slug}/todos/{$unassignedTodo->id}")
            ->assertOk();
    }

    public function test_parent_article_and_tenant_boundaries_are_enforced(): void
    {
        $sameOrgOtherPost = BlogPost::create([
            'user_id' => $this->author->id,
            'organization_id' => $this->org->id,
            'title' => 'Other post',
            'summary' => 'Summary',
            'content' => 'Content',
            'status' => 'draft',
        ]);
        $todo = $this->todo('Parent protected', $this->author->id);

        $this->actingAs($this->author)->putJson("/blog/{$sameOrgOtherPost->slug}/todos/{$todo->id}", ['title' => 'Wrong parent'])
            ->assertNotFound();
        $this->actingAs($this->author)->deleteJson("/blog/{$sameOrgOtherPost->slug}/todos/{$todo->id}")
            ->assertNotFound();
        $this->actingAs($this->author)->postJson("/blog/{$sameOrgOtherPost->slug}/todos/{$todo->id}/threads", ['body' => 'Wrong parent'])
            ->assertNotFound();

        $otherOrgPost = BlogPost::create([
            'user_id' => $this->otherOrgUser->id,
            'organization_id' => $this->otherOrg->id,
            'title' => 'Other org post',
            'summary' => 'Summary',
            'content' => 'Content',
            'status' => 'draft',
        ]);

        $this->actingAs($this->author)->getJson("/blog/{$otherOrgPost->slug}/todos")
            ->assertNotFound();
        $this->actingAs($this->outsider)->getJson("/blog/{$this->post->slug}/todos")
            ->assertForbidden();
    }

    public function test_existing_create_draft_endpoint_provides_persistent_draft_for_future_todos(): void
    {
        $category = Category::create([
            'organization_id' => $this->org->id,
            'name_b2c' => 'Blog',
            'name_b2b' => 'Blog',
            'slug' => 'blog-category',
            'color' => '#6366f1',
        ]);

        $response = $this->actingAs($this->author)->postJson('/blog/creer-brouillon', [
            'title' => 'Draft for todos',
            'summary' => 'Draft summary',
            'category_id' => $category->id,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['post_id', 'edit_url']);

        $post = BlogPost::findOrFail($response->json('post_id'));
        $this->assertSame('draft', $post->status);
        $this->assertSame('<p></p>', $post->content);
        $this->assertSame($this->author->id, $post->user_id);
        $this->assertSame($this->org->id, $post->organization_id);
    }

    private function todo(string $title, ?string $assignedTo)
    {
        return $this->post->todos()->create([
            'organization_id' => $this->post->organization_id,
            'user_id' => $this->author->id,
            'assigned_to' => $assignedTo,
            'title' => $title,
            'status' => 'todo',
            'position' => 1,
        ]);
    }
}
