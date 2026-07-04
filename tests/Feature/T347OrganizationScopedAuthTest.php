<?php

namespace Tests\Feature;

use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class T347OrganizationScopedAuthTest extends TestCase
{
    use RefreshDatabase;

    private Organization $mainOrg;

    private Organization $cpmeOrg;

    private User $superAdmin;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainOrg = Organization::factory()->create([
            'name' => 'Main',
            'slug' => 'main',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->cpmeOrg = Organization::factory()->create([
            'name' => 'LaunchPals',
            'slug' => 'cpme',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true,
            'organization_id' => $this->mainOrg->id,
        ]);

        $this->orgAdmin = User::factory()->create([
            'organization_id' => $this->cpmeOrg->id,
            'is_admin' => false,
        ]);

        $this->cpmeOrg->update(['admin_id' => $this->orgAdmin->id]);
    }

    // ─── Registration: global ────────────────────────────────

    public function test_global_register_creates_user_with_default_org(): void
    {
        $response = $this->post('/register', [
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'organization_id' => $this->mainOrg->id,
        ]);
    }

    // ─── Registration: org-scoped ─────────────────────────────

    public function test_org_scoped_register_page_returns_200(): void
    {
        $response = $this->get(route('organization.register', $this->cpmeOrg));

        $response->assertStatus(200);
    }

    public function test_org_scoped_register_creates_user_with_correct_org(): void
    {
        $response = $this->post(route('organization.register', $this->cpmeOrg), [
            'name' => 'CPME Member',
            'email' => 'cpme.member@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'cpme.member@example.com',
            'organization_id' => $this->cpmeOrg->id,
        ]);
    }

    public function test_org_scoped_register_binds_correct_org_in_view(): void
    {
        $response = $this->get(route('organization.register', $this->cpmeOrg));

        $response->assertStatus(200);
    }

    // ─── Registration: invalid org slug ───────────────────────

    public function test_invalid_org_slug_register_returns_404(): void
    {
        $response = $this->get('/org/invalid-org/register');

        $response->assertStatus(404);
    }

    public function test_invalid_org_slug_post_register_returns_404(): void
    {
        $response = $this->post('/org/invalid-org/register', [
            'name' => 'Ghost',
            'email' => 'ghost@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('users', ['email' => 'ghost@example.com']);
    }

    // ─── Login: org-scoped ────────────────────────────────────

    public function test_org_scoped_login_page_returns_200(): void
    {
        $response = $this->get(route('organization.login', $this->cpmeOrg));

        $response->assertStatus(200);
    }

    public function test_org_scoped_login_authenticates_correctly(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->cpmeOrg->id,
            'password' => Hash::make('password'),
        ]);

        $response = $this->post(route('organization.login', $this->cpmeOrg), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_org_scoped_login_redirects_to_org_home(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->cpmeOrg->id,
            'password' => Hash::make('password'),
        ]);

        $response = $this->post(route('organization.login', $this->cpmeOrg), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('organization.home', $this->cpmeOrg));
    }

    // ─── Admin global users ─────────────────────────────────

    public function test_super_admin_sees_all_users(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'));

        $response->assertStatus(200);
        $response->assertViewHas('users');
        $users = $response->viewData('users');

        $this->assertGreaterThanOrEqual(2, $users->total());
    }

    public function test_admin_users_can_filter_by_organization(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.users', ['organization_id' => $this->cpmeOrg->id]));

        $response->assertStatus(200);
        $users = $response->viewData('users');

        foreach ($users as $user) {
            $this->assertEquals($this->cpmeOrg->id, $user->organization_id);
        }
    }

    public function test_admin_users_can_sort_by_name(): void
    {
        User::factory()->create(['name' => 'AAA Aaron']);
        User::factory()->create(['name' => 'ZZZ Zach']);

        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.users', ['sort' => 'name', 'direction' => 'asc']));

        $users = $response->viewData('users');
        $names = $users->pluck('name')->toArray();
        $sorted = $names;
        sort($sorted);

        $this->assertEquals($sorted, $names);
    }

    public function test_admin_users_can_sort_by_email(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.users', ['sort' => 'email', 'direction' => 'asc']));

        $response->assertStatus(200);
    }

    public function test_admin_users_can_sort_by_created_at(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.users', ['sort' => 'created_at', 'direction' => 'desc']));

        $response->assertStatus(200);
    }

    // ─── Admin org users ───────────────────────────────────

    public function test_org_admin_sees_only_own_org_users(): void
    {
        // Super admin belongs to mainOrg
        // Org admin belongs to cpmeOrg
        // Org admin should see only cpmeOrg users

        $response = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.users', $this->cpmeOrg));

        $response->assertStatus(200);
        $users = $response->viewData('users');

        foreach ($users as $user) {
            $this->assertEquals($this->cpmeOrg->id, $user->organization_id);
        }
    }

    public function test_org_admin_cannot_see_other_org_users(): void
    {
        $response = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.users', $this->cpmeOrg));

        $response->assertStatus(200);
        $users = $response->viewData('users');

        foreach ($users as $user) {
            $this->assertNotEquals($this->mainOrg->id, $user->organization_id);
        }
    }

    public function test_org_admin_can_sort_users_by_name(): void
    {
        User::factory()->create(['organization_id' => $this->cpmeOrg->id, 'name' => 'AAA Aaron']);
        User::factory()->create(['organization_id' => $this->cpmeOrg->id, 'name' => 'ZZZ Zach']);

        $response = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.users', [$this->cpmeOrg, 'sort' => 'name', 'direction' => 'asc']));

        $users = $response->viewData('users');
        $names = $users->pluck('name')->toArray();
        $sorted = $names;
        sort($sorted);

        $this->assertEquals($sorted, $names);
    }

    public function test_org_admin_can_sort_users_by_created_at(): void
    {
        $response = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.users', [$this->cpmeOrg, 'sort' => 'created_at', 'direction' => 'desc']));

        $response->assertStatus(200);
    }

    public function test_org_admin_can_sort_users_by_email(): void
    {
        $response = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.users', [$this->cpmeOrg, 'sort' => 'email', 'direction' => 'asc']));

        $response->assertStatus(200);
    }

    public function test_non_member_cannot_access_org_admin(): void
    {
        $outsider = User::factory()->create([
            'organization_id' => $this->mainOrg->id,
            'is_admin' => false,
        ]);

        $response = $this->actingAs($outsider)
            ->get(route('organization.admin.users', $this->cpmeOrg));

        $response->assertStatus(403);
    }

    // ─── Email templates ────────────────────────────────────

    public function test_admin_can_view_email_templates_page(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.email-templates'));

        $response->assertStatus(200);
        $response->assertViewIs('admin.email-templates.index');
    }

    public function test_non_admin_cannot_view_email_templates(): void
    {
        $response = $this->actingAs($this->orgAdmin)
            ->get(route('admin.email-templates'));

        $response->assertStatus(403);
    }

    public function test_email_templates_page_shows_content(): void
    {
        $template = EmailTemplate::factory()->create([
            'name' => 'Test Template',
            'subject' => 'Test Subject',
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->get(route('admin.email-templates'));

        $response->assertStatus(200);
        $response->assertSee('Test Template');
        $response->assertSee('Test Subject');
    }
}
