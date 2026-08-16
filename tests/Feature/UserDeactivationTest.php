<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

class UserDeactivationTest extends TestCase
{
    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        Organization::where('is_default', true)->update(['is_default' => false]);
        $this->organization = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function createUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'organization_id' => $this->organization->id,
            'password' => bcrypt('password'),
        ], $overrides));
    }

    /**
     * TASK-1218 : `UserFactory` tire le nom via `fake()->lastName()`, qui
     * produit des noms courts et non uniques (« Le », « Roy »…). Cherches
     * dans TOUT le HTML, ils entraient en collision avec le markup, un
     * script inline ou l'autre utilisateur — d'ou un echec aleatoire en CI.
     * Des sentinelles uniques rendent l'assertion sans ambiguite, sans
     * toucher a la factory ni assouplir le test metier.
     */
    public function test_deactivated_user_absent_from_members_page(): void
    {
        $activeUser = $this->createUser(['banned_at' => null, 'name' => 'MemberActiveSentinelXYZ']);
        $deactivatedUser = $this->createUser(['banned_at' => now(), 'name' => 'MemberDeactivatedSentinelXYZ']);

        $response = $this->get('/membres');

        $response->assertOk();
        $response->assertSee($activeUser->name);
        $response->assertDontSee($deactivatedUser->name);
    }

    public function test_active_user_present_on_members_page(): void
    {
        $activeUser = $this->createUser(['banned_at' => null, 'name' => 'MemberPresentSentinelXYZ']);

        $response = $this->get('/membres');

        $response->assertOk();
        $response->assertSee($activeUser->name);
    }

    public function test_web_login_refused_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now(), 'password' => bcrypt('password')]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_web_session_invalidated_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_api_login_refused_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertForbidden();
    }

    public function test_old_sanctum_token_rejected_after_deactivation(): void
    {
        $user = $this->createUser(['banned_at' => null]);
        $token = $user->createToken('test')->plainTextToken;

        $user->update(['banned_at' => now()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/profile');

        $response->assertUnauthorized();
    }

    public function test_sanctum_tokens_revoked_on_deactivation(): void
    {
        $user = $this->createUser(['banned_at' => null]);
        $user->createToken('token-a')->plainTextToken;
        $user->createToken('token-b')->plainTextToken;

        $this->assertSame(2, $user->tokens()->count());

        $user->update(['banned_at' => now()]);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_no_repeated_token_revocation_on_subsequent_save(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $user->createToken('token-after-ban')->plainTextToken;
        $this->assertSame(1, $user->tokens()->count());

        $tokenCount = $user->tokens()->count();

        $user->update(['bio' => 'Some bio update while still banned']);

        $this->assertSame($tokenCount, $user->tokens()->count());
    }

    public function test_public_web_profile_returns_404_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->get(route('profile.show', $user));

        $response->assertNotFound();
    }

    public function test_public_api_profile_returns_404_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->getJson('/api/users/'.$user->id);

        $response->assertNotFound();
    }

    public function test_public_service_detail_returns_404_when_owner_is_deactivated(): void
    {
        $owner = $this->createUser(['banned_at' => now()]);
        $category = Category::factory()->create(['organization_id' => $this->organization->id]);
        $service = Service::factory()
            ->forUser($owner)
            ->forCategory($category)
            ->create([
                'organization_id' => $this->organization->id,
                'status' => 'active',
            ]);

        $this->get(route('organization.services.show', [$this->organization, $service]))
            ->assertNotFound();
    }

    public function test_public_request_detail_returns_404_when_owner_is_deactivated(): void
    {
        $owner = $this->createUser(['banned_at' => now()]);
        $category = Category::factory()->create(['organization_id' => $this->organization->id]);
        $serviceRequest = ServiceRequest::factory()
            ->forUser($owner)
            ->create([
                'organization_id' => $this->organization->id,
                'category_id' => $category->id,
                'status' => 'open',
            ]);

        $this->get(route('organization.requests.show', [$this->organization, $serviceRequest]))
            ->assertNotFound();
    }

    public function test_search_excludes_deactivated_users(): void
    {
        $activeUser = $this->createUser(['banned_at' => null, 'name' => 'UniqueActiveName']);
        $deactivatedUser = $this->createUser(['banned_at' => now(), 'name' => 'UniqueBannedName']);

        $response = $this->get('/search?q=Unique');

        $response->assertOk();
        $response->assertDontSee('UniqueBannedName');
    }

    public function test_blog_keeps_published_article_but_neutralizes_deactivated_author_and_commenter(): void
    {
        $viewer = $this->createUser(['name' => 'Active Reader']);
        $deactivatedAuthor = $this->createUser([
            'first_name' => 'Hidden',
            'name' => 'AuthorName',
            'banned_at' => now(),
        ]);
        $deactivatedCommenter = $this->createUser([
            'first_name' => 'Hidden',
            'name' => 'CommenterName',
            'banned_at' => now(),
        ]);
        $post = BlogPost::create([
            'user_id' => $deactivatedAuthor->id,
            'organization_id' => $this->organization->id,
            'title' => 'Historical public article',
            'slug' => 'historical-public-article',
            'content' => 'Article content remains visible for collective history.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        BlogComment::create([
            'blog_post_id' => $post->id,
            'user_id' => $deactivatedCommenter->id,
            'organization_id' => $this->organization->id,
            'content' => 'Historical comment remains visible.',
            'is_approved' => true,
        ]);

        $response = $this->actingAs($viewer)->get(route('organization.blog.show', [$this->organization, $post]));

        $response->assertOk();
        $response->assertSee('Historical public article');
        $response->assertSee(__('profile.deactivated_user'));
        $response->assertDontSee('Hidden AuthorName');
        $response->assertDontSee('Hidden CommenterName');
        $response->assertDontSee(route('organization.profile.show', [$this->organization, $deactivatedAuthor]));
    }

    public function test_loop_keeps_old_message_but_neutralizes_deactivated_sender(): void
    {
        $viewer = $this->createUser(['name' => 'Active Loop Reader']);
        $deactivatedSender = $this->createUser([
            'first_name' => 'Hidden',
            'name' => 'LoopSender',
            'banned_at' => now(),
        ]);
        $loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $viewer->id,
            'visibility' => 'private',
        ]);
        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $viewer->id,
            'organization_id' => $this->organization->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        LoopMessage::create([
            'loop_id' => $loop->id,
            'sender_id' => $deactivatedSender->id,
            'organization_id' => $this->organization->id,
            'body' => 'Historical loop message remains visible.',
            'type' => 'user',
        ]);

        Livewire::actingAs($viewer)
            ->test(LoopChat::class, ['loop' => $loop])
            ->assertSee('Historical loop message remains visible.')
            ->assertSee(__('profile.deactivated_user'))
            ->assertDontSee('Hidden LoopSender');
    }

    public function test_public_ai_agent_entry_returns_404_when_owner_is_deactivated(): void
    {
        $this->organization->update(['ai_profiles_enabled' => true]);
        $owner = $this->createUser(['banned_at' => now()]);
        MemberAiProfile::factory()->published()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $owner->id,
        ]);

        $this->get(route('organization.agent-ia.profile.chat', [$this->organization, $owner]))
            ->assertNotFound();
    }

    public function test_dossier_members_api_neutralizes_deactivated_existing_member(): void
    {
        $owner = $this->createUser();
        $deactivatedMember = $this->createUser([
            'first_name' => 'Hidden',
            'name' => 'DossierMember',
            'email' => 'hidden-dossier-member@example.test',
            'banned_at' => now(),
        ]);
        $dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $owner->id,
            'name' => 'Shared diagnostic dossier',
            'visibility' => Dossier::VISIBILITY_SHARED,
        ]);
        DossierMember::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'user_id' => $deactivatedMember->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->getJson(route('organization.dossiers.members.index', [$this->organization, $dossier]));

        $response->assertOk();
        $response->assertJsonPath('members.0.name', __('profile.deactivated_user'));
        $response->assertJsonPath('members.0.first_name', null);
        $response->assertJsonPath('members.0.email', null);
        $response->assertJsonMissing(['name' => 'DossierMember']);
        $response->assertJsonMissing(['email' => 'hidden-dossier-member@example.test']);
    }

    public function test_dossier_files_api_neutralizes_deactivated_uploader(): void
    {
        $owner = $this->createUser();
        $deactivatedUploader = $this->createUser([
            'first_name' => 'Hidden',
            'name' => 'FileUploader',
            'email' => 'hidden-file-uploader@example.test',
            'banned_at' => now(),
        ]);
        $dossier = Dossier::create([
            'organization_id' => $this->organization->id,
            'owner_id' => $owner->id,
            'name' => 'Files diagnostic dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        DossierFile::create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $deactivatedUploader->id,
            'disk' => 'local',
            'path' => 'diagnostic.txt',
            'original_name' => 'diagnostic.txt',
            'display_name' => 'diagnostic.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 42,
            'checksum_sha256' => hash('sha256', 'diagnostic'),
            'source' => 'upload',
        ]);

        $response = $this->actingAs($owner)->getJson(route('organization.dossiers.files.index', [$this->organization, $dossier]));

        $response->assertOk();
        $response->assertJsonPath('files.data.0.uploader.name', __('profile.deactivated_user'));
        $response->assertJsonPath('files.data.0.uploader.email', null);
        $response->assertJsonMissing(['name' => 'FileUploader']);
        $response->assertJsonMissing(['email' => 'hidden-file-uploader@example.test']);
    }

    public function test_password_reset_refused_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->post('/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertSessionHas('status');
    }

    public function test_password_change_by_token_refused_for_deactivated_user(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $response = $this->post('/reset-password', [
            'token' => 'fake-token',
            'email' => $user->email,
            'password' => 'NewPass123!!',
            'password_confirmation' => 'NewPass123!!',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_admin_login_as_refused_for_deactivated_user(): void
    {
        $admin = $this->createUser(['is_admin' => true]);
        $deactivatedUser = $this->createUser(['banned_at' => now()]);

        $response = $this->actingAs($admin)
            ->post(route('admin.users.login-as', $deactivatedUser));

        $response->assertSessionHas('error');
    }

    public function test_reactivation_restores_login_and_discoverability(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $user->update(['banned_at' => null]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);

        auth()->logout();

        $membersResponse = $this->get('/membres');
        $membersResponse->assertOk();
        $membersResponse->assertSee($user->name);
    }

    public function test_api_middleware_blocks_deactivated_user_with_existing_token(): void
    {
        $user = $this->createUser(['banned_at' => now()]);

        $user->createToken('post-ban-token');
        $this->assertSame(1, $user->tokens()->count());

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/profile');

        $response->assertForbidden();
        $response->assertJson(['message' => trans('auth.deactivated')]);
    }

    public function test_organization_isolation_preserved_for_members_page(): void
    {
        $otherOrg = Organization::factory()->create(['slug' => 'other', 'is_active' => true]);
        $userInOtherOrg = User::factory()->create([
            'organization_id' => $otherOrg->id,
            'banned_at' => null,
            'name' => 'OtherOrgUser',
        ]);

        $this->createUser(['banned_at' => null, 'name' => 'MainOrgUser']);

        $response = $this->get('/membres');

        $response->assertOk();
        $response->assertSee('MainOrgUser');
        $response->assertDontSee('OtherOrgUser');
    }
}
