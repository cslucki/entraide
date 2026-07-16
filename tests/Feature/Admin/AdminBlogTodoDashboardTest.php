<?php

namespace Tests\Feature\Admin;

use App\Models\BlogPost;
use App\Models\BlogTodo;
use App\Models\BlogTodoThread;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBlogTodoDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $admin;

    private User $orgAdmin;

    private User $member;

    private User $authorA;

    private User $coAuthorA;

    private User $otherMemberA;

    private User $authorB;

    private BlogPost $postA;

    private BlogPost $postB;

    private BlogTodo $todoA;

    private BlogTodo $todoB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org Alpha', 'is_default' => true]);
        $this->orgB = Organization::factory()->create(['name' => 'Org Beta']);

        $this->admin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
        $this->orgAdmin = User::factory()->create(['is_admin' => false, 'organization_id' => $this->orgA->id]);
        $this->member = User::factory()->create(['is_admin' => false, 'organization_id' => $this->orgA->id]);
        $this->authorA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Auteur Alpha']);
        $this->coAuthorA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Coauthor Alpha']);
        $this->otherMemberA = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Member Alpha']);
        $this->authorB = User::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Auteur Beta']);

        $this->orgA->update(['admin_id' => $this->orgAdmin->id]);

        $this->postA = $this->blogPost($this->orgA, $this->authorA, 'Article Alpha');
        $this->postB = $this->blogPost($this->orgB, $this->authorB, 'Article Beta');
        $this->postA->coAuthors()->attach($this->coAuthorA->id, ['role' => 'coauthor', 'added_by' => $this->authorA->id]);

        $this->todoA = $this->todo($this->postA, $this->authorA, 'Alpha admin todo', 'todo', $this->authorA->id);
        $this->todoB = $this->todo($this->postB, $this->authorB, 'Beta admin todo', 'done', $this->authorB->id);
    }

    public function test_guest_is_redirected(): void
    {
        $this->get(route('admin.todo'))->assertRedirect(route('login'));
    }

    public function test_member_is_forbidden(): void
    {
        $this->actingAs($this->member)->get(route('admin.todo'))->assertStatus(403);
    }

    public function test_organization_admin_without_super_admin_flag_is_forbidden(): void
    {
        $this->actingAs($this->orgAdmin)->get(route('admin.todo'))->assertStatus(403);
    }

    public function test_super_admin_sees_multiple_organizations_by_default(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.todo'));

        $response->assertOk();
        $response->assertSee('Alpha admin todo');
        $response->assertSee('Beta admin todo');
        $response->assertSee('Org Alpha');
        $response->assertSee('Org Beta');
    }

    public function test_organization_filter_limits_results(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.todo', ['organization_id' => $this->orgA->id]));

        $response->assertOk();
        $response->assertSee('Alpha admin todo');
        $response->assertDontSee('Beta admin todo');
    }

    public function test_search_matches_todo_and_article_titles(): void
    {
        $this->actingAs($this->admin)->get(route('admin.todo', ['search' => 'Alpha admin']))
            ->assertOk()
            ->assertSee('Alpha admin todo')
            ->assertDontSee('Beta admin todo');

        $this->actingAs($this->admin)->get(route('admin.todo', ['search' => 'Article Beta']))
            ->assertOk()
            ->assertSee('Beta admin todo')
            ->assertDontSee('Alpha admin todo');
    }

    public function test_status_author_and_assignee_filters_work(): void
    {
        $this->actingAs($this->admin)->get(route('admin.todo', ['status' => 'done']))
            ->assertOk()
            ->assertSee('Beta admin todo')
            ->assertDontSee('Alpha admin todo');

        $this->actingAs($this->admin)->get(route('admin.todo', ['author_id' => $this->authorA->id]))
            ->assertOk()
            ->assertSee('Alpha admin todo')
            ->assertDontSee('Beta admin todo');

        $this->actingAs($this->admin)->get(route('admin.todo', ['assignee_id' => $this->authorB->id]))
            ->assertOk()
            ->assertSee('Beta admin todo')
            ->assertDontSee('Alpha admin todo');
    }

    public function test_allowed_sorts_are_accepted(): void
    {
        foreach (['created_at', 'updated_at', 'author', 'assignee'] as $sort) {
            $this->actingAs($this->admin)->get(route('admin.todo', ['sort' => $sort, 'direction' => 'asc']))
                ->assertOk()
                ->assertSee('Alpha admin todo');
        }
    }

    public function test_pagination_keeps_filters(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->todo($this->postA, $this->authorA, 'Paginated todo '.$i, 'todo', null);
        }

        $response = $this->actingAs($this->admin)->get(route('admin.todo', [
            'organization_id' => $this->orgA->id,
            'page' => 2,
        ]));

        $response->assertOk();
        $response->assertSee('organization_id='.$this->orgA->id, false);
    }

    public function test_super_admin_can_update_title(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.todo.update', $this->todoA), [
            'title' => 'Updated admin title',
            'status' => 'todo',
            'assigned_to' => $this->authorA->id,
        ])->assertRedirect();

        $this->assertSame('Updated admin title', $this->todoA->fresh()->title);
    }

    public function test_super_admin_can_change_status_independently_from_assignee(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.todo.update', $this->todoA), [
            'title' => $this->todoA->title,
            'status' => 'done',
            'assigned_to' => $this->authorA->id,
        ])->assertRedirect();

        $this->assertSame('done', $this->todoA->fresh()->status);
    }

    public function test_super_admin_can_reassign_to_article_author(): void
    {
        $this->todoA->update(['assigned_to' => $this->coAuthorA->id]);

        $this->actingAs($this->admin)->patch(route('admin.todo.update', $this->todoA), [
            'title' => $this->todoA->title,
            'status' => $this->todoA->status,
            'assigned_to' => $this->authorA->id,
        ])->assertRedirect();

        $this->assertSame($this->authorA->id, $this->todoA->fresh()->assigned_to);
    }

    public function test_super_admin_can_reassign_to_active_coauthor(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.todo.update', $this->todoA), [
            'title' => $this->todoA->title,
            'status' => $this->todoA->status,
            'assigned_to' => $this->coAuthorA->id,
        ])->assertRedirect();

        $this->assertSame($this->coAuthorA->id, $this->todoA->fresh()->assigned_to);
    }

    public function test_outside_organization_assignee_is_rejected(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.todo.update', $this->todoA), [
            'title' => 'Forbidden outside org',
            'status' => 'done',
            'assigned_to' => $this->authorB->id,
        ])->assertStatus(422);

        $this->assertSame('Alpha admin todo', $this->todoA->fresh()->title);
        $this->assertSame('todo', $this->todoA->fresh()->status);
        $this->assertSame($this->authorA->id, $this->todoA->fresh()->assigned_to);
    }

    public function test_same_organization_non_coauthor_assignee_is_rejected(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.todo.update', $this->todoA), [
            'title' => 'Forbidden same org member',
            'status' => 'done',
            'assigned_to' => $this->otherMemberA->id,
        ])->assertStatus(422);

        $this->assertSame('Alpha admin todo', $this->todoA->fresh()->title);
        $this->assertSame('todo', $this->todoA->fresh()->status);
        $this->assertSame($this->authorA->id, $this->todoA->fresh()->assigned_to);
    }

    public function test_super_admin_can_unassign_todo(): void
    {
        $this->actingAs($this->admin)->patch(route('admin.todo.update', $this->todoA), [
            'title' => $this->todoA->title,
            'status' => $this->todoA->status,
            'assigned_to' => null,
        ])->assertRedirect();

        $this->assertNull($this->todoA->fresh()->assigned_to);
    }

    public function test_super_admin_can_delete_todo_and_threads_cascade(): void
    {
        $thread = BlogTodoThread::create([
            'todo_id' => $this->todoA->id,
            'user_id' => $this->authorA->id,
            'body' => 'Thread attached to todo',
        ]);

        $this->actingAs($this->admin)->delete(route('admin.todo.destroy', $this->todoA))->assertRedirect();

        $this->assertDatabaseMissing('blog_todos', ['id' => $this->todoA->id]);
        $this->assertDatabaseMissing('blog_todo_threads', ['id' => $thread->id]);
    }

    public function test_incoherent_todo_identifier_does_not_modify_other_todo(): void
    {
        $incoherent = $this->postA->todos()->create([
            'organization_id' => $this->orgB->id,
            'user_id' => $this->authorA->id,
            'assigned_to' => $this->authorA->id,
            'title' => 'Incoherent todo',
            'status' => 'todo',
            'position' => 10,
        ]);

        $this->actingAs($this->admin)->patch(route('admin.todo.update', $incoherent), [
            'title' => 'Should not update',
            'status' => 'done',
            'assigned_to' => null,
        ])->assertStatus(422);

        $this->assertSame('Incoherent todo', $incoherent->fresh()->title);
        $this->assertSame('Alpha admin todo', $this->todoA->fresh()->title);
    }

    private function blogPost(Organization $organization, User $author, string $title): BlogPost
    {
        return BlogPost::create([
            'user_id' => $author->id,
            'organization_id' => $organization->id,
            'title' => $title,
            'summary' => $title.' summary',
            'content' => $title.' content',
            'status' => 'draft',
        ]);
    }

    private function todo(BlogPost $post, User $creator, string $title, string $status, ?string $assignedTo): BlogTodo
    {
        return $post->todos()->create([
            'organization_id' => $post->organization_id,
            'user_id' => $creator->id,
            'assigned_to' => $assignedTo,
            'title' => $title,
            'status' => $status,
            'position' => 1,
        ]);
    }
}
