<?php

namespace Tests\Feature;

use App\Livewire\LoopManifestoCard;
use App\Models\BlogPost;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopManifestoSource;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopCardCompositionService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rendering and permissions of the Manifesto card.
 *
 * Three rules after Cyril's review: the card shows the Manifesto itself rather
 * than a stub excerpt, it carries no decorative header, and editing is reserved
 * to the Loop owner, the scoped Organization admin and the super-admin.
 */
class TASK1079ManifestoCardTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = 'Notre raison d etre commune, en toutes lettres.';

    private Organization $org;

    private User $orgAdmin;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgAdmin = User::factory()->create();
        $this->org = Organization::factory()->create([
            'loops_enabled' => true, 'is_active' => true, 'admin_id' => $this->orgAdmin->id,
        ]);
        $this->orgAdmin->update(['organization_id' => $this->org->id]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        // A Projets Loop. Since TASK-1332 the Manifesto is no longer imposed
        // by any type's default preset — it is enabled explicitly here so the
        // card is genuinely part of this Loop's active composition, exactly
        // as any administrator would do from Outils, rather than reached
        // through the no-rows fallback.
        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'project',
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->loop);
        app(LoopCardCompositionService::class)->enable($this->loop, 'core.manifesto');
        LoopMember::factory()->owner()->create(['loop_id' => $this->loop->id, 'user_id' => $this->owner->id]);

        app()->instance('current_organization', $this->org);
    }

    private function withManifesto(string $content = self::BODY): BlogPost
    {
        $post = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Manifeste',
            'slug' => 'manifeste-'.uniqid(),
            'content' => $content,
            'status' => 'draft',
        ]);
        $this->loop->forceFill(['manifesto_blog_post_id' => $post->id])->save();
        $this->loop->refresh();

        return $post;
    }

    private function member(string $role = 'member'): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active',
        ]);

        return $user;
    }

    // ── Rendu ───────────────────────────────────────────────────────────────

    public function test_the_card_renders_the_manifesto_itself(): void
    {
        $this->withManifesto();

        Livewire::actingAs($this->owner)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertSee(self::BODY);
    }

    public function test_a_long_manifesto_is_not_truncated(): void
    {
        // The old card cut at 240 characters, which made it useless.
        $long = str_repeat('Un principe fondateur enonce clairement. ', 30);
        $this->withManifesto($long);

        Livewire::actingAs($this->owner)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertSee(trim(mb_substr($long, -60)));
    }

    public function test_formatting_survives_but_scripts_do_not(): void
    {
        $this->withManifesto('<h2>Mission</h2><p>Avancer <strong>ensemble</strong>.</p><script>alert(1)</script>');

        $html = Livewire::actingAs($this->owner)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->html();

        $this->assertStringContainsString('<strong>ensemble</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
    }

    public function test_an_event_handler_on_an_allowed_tag_is_stripped(): void
    {
        $this->withManifesto('<p onclick="steal()">Texte</p>');

        $html = Livewire::actingAs($this->owner)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->html();

        $this->assertStringContainsString('Texte', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function test_the_decorative_header_is_gone(): void
    {
        $this->withManifesto();

        // It restated the card title and a generic sentence, costing vertical
        // space in a side panel without carrying any information.
        Livewire::actingAs($this->owner)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertDontSee(__('loops.cards.manifesto.description'));
    }

    // ── Permissions d'édition ───────────────────────────────────────────────

    public function test_the_loop_owner_can_edit(): void
    {
        $this->withManifesto();

        Livewire::actingAs($this->owner)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.manifesto_publish'))
            ->assertSee(__('loops.manifesto_sources_add'));
    }

    public function test_the_organization_admin_can_edit(): void
    {
        $this->withManifesto();

        Livewire::actingAs($this->orgAdmin->fresh())
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.manifesto_publish'));
    }

    public function test_the_super_admin_can_edit(): void
    {
        $this->withManifesto();
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        Livewire::actingAs($superAdmin)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.manifesto_publish'));
    }

    public function test_a_standard_member_reads_but_cannot_edit(): void
    {
        $this->withManifesto();
        $member = $this->member();

        Livewire::actingAs($member)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertSee(self::BODY)
            ->assertDontSee(__('loops.manifesto_publish'))
            ->assertDontSee(__('loops.manifesto_sources_add'))
            ->assertDontSee(__('loops.manifesto_replace'));
    }

    public function test_a_moderator_no_longer_qualifies(): void
    {
        $this->withManifesto();
        $moderator = $this->member('moderator');

        // The Manifesto is the Loop's founding text, not day-to-day moderation:
        // a moderator used to be able to edit it, which was too wide.
        Livewire::actingAs($moderator)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertSee(self::BODY)
            ->assertDontSee(__('loops.manifesto_publish'));
    }

    public function test_the_admin_of_another_organization_cannot_edit(): void
    {
        $this->withManifesto();
        $otherAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        Organization::factory()->create(['admin_id' => $otherAdmin->id]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id, 'user_id' => $otherAdmin->id, 'status' => 'active',
        ]);

        // Admin of *an* Organization, but not of this Loop's one.
        Livewire::actingAs($otherAdmin->fresh())
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertDontSee(__('loops.manifesto_publish'));
    }

    public function test_a_non_member_sees_nothing_of_the_content(): void
    {
        $this->withManifesto();
        $outsider = User::factory()->create(['organization_id' => $this->org->id]);

        Livewire::actingAs($outsider)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->assertDontSee(self::BODY)
            ->assertDontSee(__('loops.manifesto_publish'));
    }

    public function test_a_standard_member_cannot_publish_by_calling_the_action(): void
    {
        $manifesto = $this->withManifesto();
        $member = $this->member();

        // Hiding the button is not the guard: the action itself refuses.
        Livewire::actingAs($member)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('publish');

        $this->assertSame('draft', $manifesto->fresh()->status);
    }

    public function test_a_standard_member_cannot_attach_a_source_by_calling_the_action(): void
    {
        $this->withManifesto();
        $member = $this->member();
        $file = DossierFile::factory()->create(['organization_id' => $this->org->id]);

        Livewire::actingAs($member)
            ->test(LoopManifestoCard::class, ['loop' => $this->loop])
            ->call('attachSource', $file->id);

        $this->assertSame(0, LoopManifestoSource::where('loop_id', $this->loop->id)->count());
    }
}
