<?php

namespace Tests\Feature;

use App\Http\Controllers\BlogInvitationController;
use App\Http\Controllers\LoopInvitationController;
use App\Models\BlogPostInvitation;
use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\BlogInvitationService;
use App\Services\LoopInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1281 — campagne Cards endgame : liens et redirections vers la surface
 * courte depuis des surfaces Organization-scoped.
 *
 * La surface courte (`/loops`, `/blog/...`) resout l'Organization **par
 * defaut** (`ResolveUrlOrganization::$defaultOrganizationRoutes`), meme pour
 * un utilisateur connecte d'une autre Organization. Tout lien ou redirect
 * court produit depuis `/org/{slug}/...` envoie donc l'utilisateur hors de
 * son tenant : Boucle en 404 (T1277), « Mes articles » vides, retour vers les
 * Boucles d'une autre Organization.
 *
 * Corrections couvertes ici :
 *  - LoopInvitationController::redirectForOutcome  (succes -> loops.show
 *    org-scoped ; echec sans token -> loops.index org-scoped) ;
 *  - BlogInvitationController::redirectForOutcome  (succes -> blog.edit
 *    org-scoped) ;
 *  - navigation + mobile-topbar : « Mes articles » et le bouton retour des
 *    pages Boucles restent dans l'Organization courante ;
 *  - loops/presentation : « lire la suite » du Manifeste public pointe vers
 *    le blog de l'Organization de la Boucle.
 */
class TASK1281ShortSurfaceRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Loop $loop;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['loops_enabled' => true]);
        $this->loop = Loop::factory()->create(['organization_id' => $this->org->id, 'status' => 'active']);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $this->loop->id, 'user_id' => $this->owner->id]);

        app()->instance('current_organization', $this->org);
    }

    // ── LoopInvitationController::redirectForOutcome ──────────────────────

    public function test_loop_invitation_accept_redirects_to_organization_scoped_loop(): void
    {
        $invitee = User::factory()->create(['organization_id' => $this->org->id, 'email' => 'invitee-1281@example.com']);
        $invitation = app(LoopInvitationService::class)->invite($this->loop, $this->owner, 'invitee-1281@example.com');

        $response = $this->actingAs($invitee)
            ->post(route('loop-invitations.accept', $invitation->token))
            ->assertRedirect(route('organization.loops.show', [
                'organization' => $this->org->slug,
                'loop' => $this->loop->id,
            ]));

        $this->assertStringStartsWith(
            '/org/'.$this->org->slug.'/loops/',
            parse_url($response->headers->get('Location'), PHP_URL_PATH),
        );
    }

    public function test_loop_invitation_failure_without_token_lands_on_organization_scoped_index(): void
    {
        $invitation = new LoopInvitation;
        $invitation->setRelation('organization', $this->org);
        $invitation->setRelation('loop', null);

        $response = app(LoopInvitationController::class)->redirectForOutcome([
            'result' => LoopInvitationService::RESULT_REVOKED,
            'invitation' => $invitation,
        ]);

        $this->assertSame(
            route('organization.loops.index', ['organization' => $this->org->slug]),
            $response->getTargetUrl(),
        );
    }

    public function test_loop_invitation_failure_without_any_organization_falls_back_to_short_index(): void
    {
        $response = app(LoopInvitationController::class)->redirectForOutcome([
            'result' => LoopInvitationService::RESULT_NOT_FOUND,
            'invitation' => null,
        ]);

        $this->assertSame(route('loops.index'), $response->getTargetUrl());
    }

    // ── BlogInvitationController::redirectForOutcome ──────────────────────

    public function test_blog_invitation_accept_redirects_to_organization_scoped_editor(): void
    {
        $post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Article T1281',
            'slug' => 'article-t1281',
            'content' => 'Contenu.',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $invitation = new BlogPostInvitation;
        $invitation->setRelation('blogPost', $post);

        $response = app(BlogInvitationController::class)->redirectForOutcome([
            'result' => BlogInvitationService::RESULT_ACCEPTED,
            'invitation' => $invitation,
        ]);

        $this->assertSame(
            route('organization.blog.edit', ['organization' => $this->org->slug, 'post' => $post->slug]),
            $response->getTargetUrl(),
        );
    }

    // ── Navigation : « Mes articles » et bouton retour restent org-scoped ─

    public function test_org_scoped_loop_page_renders_no_short_loops_or_my_posts_link(): void
    {
        $response = $this->actingAs($this->owner)
            ->get(route('organization.loops.show', [
                'organization' => $this->org->slug,
                'loop' => $this->loop->id,
            ]))
            ->assertOk();

        // Les deux liens que la campagne T1281 a releves sur TOUTES les pages
        // de Boucle org-scoped : le retour du topbar mobile et « Mes articles ».
        $response->assertDontSee('href="'.route('loops.index').'"', false);
        $response->assertDontSee('href="'.route('blog.my-posts').'"', false);
        $response->assertSee(route('organization.blog.my-posts', ['organization' => $this->org->slug]), false);
    }

    // ── Presentation : « lire la suite » du Manifeste public ──────────────

    public function test_presentation_manifesto_read_more_links_to_organization_blog(): void
    {
        $post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Manifeste T1281',
            'slug' => 'manifeste-t1281',
            'status' => 'published',
            'published_at' => now(),
            'content' => str_repeat('Le manifeste public de la Boucle, en toutes lettres. ', 12),
        ]);
        $this->loop->update([
            'visibility' => 'public',
            'access_mode' => 'request',
            'manifesto_blog_post_id' => $post->id,
        ]);

        $outsider = User::factory()->create(['organization_id' => $this->org->id]);

        $response = $this->actingAs($outsider)
            ->get(route('organization.loops.show', [
                'organization' => $this->org->slug,
                'loop' => $this->loop->id,
            ]))
            ->assertOk();

        $response->assertSee(
            route('organization.blog.show', ['organization' => $this->org->slug, 'post' => $post->slug]),
            false,
        );
        $response->assertDontSee('href="'.route('blog.show', ['post' => $post->slug]).'"', false);
    }
}
