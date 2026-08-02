<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TASK1076LoopDomainsTest extends TestCase
{
    use RefreshDatabase;

    private function org(array $overrides = []): Organization
    {
        return Organization::factory()->create(array_merge([
            'loops_enabled' => true,
            'members_can_create_loops' => true,
            'loop_mode' => 'multi',
        ], $overrides));
    }

    private function domain(Organization $organization, string $b2c, string $b2b = 'B2B name'): Category
    {
        return Category::create([
            'organization_id' => $organization->id,
            'name_b2c' => $b2c,
            'name_b2b' => $b2b,
            'slug' => Str::slug($b2c).'-'.Str::random(4),
            'color' => '#6366f1',
        ]);
    }

    private function withCurrentOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }

    private function owner(Organization $organization, Loop $loop): User
    {
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);

        return $owner;
    }

    // ── Création ────────────────────────────────────────────────────────────

    public function test_loop_can_be_created_without_any_domain(): void
    {
        $org = $this->org();
        $this->domain($org, 'Informatique');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), ['name' => 'Sans domaine'])->assertRedirect();

        $loop = Loop::where('name', 'Sans domaine')->firstOrFail();
        $this->assertCount(0, $loop->categories);
    }

    public function test_loop_can_be_created_with_one_domain(): void
    {
        $org = $this->org();
        $d = $this->domain($org, 'Informatique');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Un domaine',
            'category_ids' => [$d->id],
        ])->assertRedirect();

        $loop = Loop::where('name', 'Un domaine')->firstOrFail();
        $this->assertEqualsCanonicalizing([$d->id], $loop->categories->pluck('id')->all());
    }

    public function test_loop_can_be_created_with_three_domains(): void
    {
        $org = $this->org();
        $ids = collect(['A', 'B', 'C'])->map(fn ($n) => $this->domain($org, $n)->id)->all();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Trois domaines',
            'category_ids' => $ids,
        ])->assertRedirect();

        $loop = Loop::where('name', 'Trois domaines')->firstOrFail();
        $this->assertEqualsCanonicalizing($ids, $loop->categories->pluck('id')->all());
    }

    public function test_four_domains_are_rejected_by_validation(): void
    {
        $org = $this->org();
        $ids = collect(['A', 'B', 'C', 'D'])->map(fn ($n) => $this->domain($org, $n)->id)->all();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Quatre domaines',
            'category_ids' => $ids,
        ])->assertSessionHasErrors('category_ids');

        $this->assertDatabaseMissing('loops', ['name' => 'Quatre domaines']);
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_domain_from_another_organization_is_silently_dropped(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $mine = $this->domain($org, 'Le mien');
        $foreign = $this->domain($otherOrg, 'Etranger');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->post(route('loops.store'), [
            'name' => 'Tenant test',
            'category_ids' => [$mine->id, $foreign->id],
        ])->assertRedirect();

        $loop = Loop::where('name', 'Tenant test')->firstOrFail();
        $this->assertEqualsCanonicalizing([$mine->id], $loop->categories->pluck('id')->all());
    }

    public function test_edit_form_only_offers_domains_of_the_current_organization(): void
    {
        $org = $this->org();
        $otherOrg = $this->org();
        $this->domain($org, 'Domaine visible');
        $this->domain($otherOrg, 'Domaine etranger');
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $owner = $this->owner($org, $loop);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->get(route('loops.edit', $loop))
            ->assertOk()
            ->assertSee('Domaine visible')
            ->assertDontSee('Domaine etranger');
    }

    // ── Modification ────────────────────────────────────────────────────────

    public function test_owner_can_change_the_domains(): void
    {
        $org = $this->org();
        $a = $this->domain($org, 'Alpha');
        $b = $this->domain($org, 'Beta');
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $loop->categories()->sync([$a->id]);
        $owner = $this->owner($org, $loop);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->put(route('loops.update', $loop), [
            'name' => $loop->name,
            'category_ids' => [$b->id],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing([$b->id], $loop->fresh()->categories->pluck('id')->all());
    }

    public function test_owner_can_remove_every_domain(): void
    {
        $org = $this->org();
        $a = $this->domain($org, 'Alpha');
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $loop->categories()->sync([$a->id]);
        $owner = $this->owner($org, $loop);
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->put(route('loops.update', $loop), ['name' => $loop->name])->assertRedirect();

        $this->assertCount(0, $loop->fresh()->categories);
    }

    // ── Affichage ───────────────────────────────────────────────────────────

    public function test_catalog_shows_domain_badges(): void
    {
        $org = $this->org();
        $d = $this->domain($org, 'Marketing digital');
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $loop->categories()->sync([$d->id]);
        Loop::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->get(route('loops.index'))->assertOk()->assertSee('Marketing digital');
    }

    public function test_presentation_shows_domain_badges(): void
    {
        $org = $this->org();
        $d = $this->domain($org, 'Pair-aidance');
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $loop->categories()->sync([$d->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)->get(route('loops.show', $loop))->assertOk()->assertSee('Pair-aidance');
    }

    public function test_catalog_renders_cleanly_when_no_loop_has_a_domain(): void
    {
        $org = $this->org();
        Loop::factory()->count(2)->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertDontSee(__('loops.filter_all_domains'));
    }

    public function test_domain_filter_appears_when_domains_are_used(): void
    {
        $org = $this->org();
        $d = $this->domain($org, 'Informatique');
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $loop->categories()->sync([$d->id]);
        Loop::factory()->create(['organization_id' => $org->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('loops.index'))
            ->assertOk()
            ->assertSee(__('loops.filter_all_domains'));
    }

    // ── Nommage b2b/b2c (mécanisme Annuaire réutilisé) ──────────────────────

    public function test_domain_name_follows_the_organization_loops_naming_setting(): void
    {
        $org = $this->org(['loops_naming' => 'b2b']);
        $d = $this->domain($org, 'Nom grand public', 'Nom entreprise');
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $loop->categories()->sync([$d->id]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        $this->actingAs($user)
            ->get(route('loops.show', $loop))
            ->assertOk()
            ->assertSee('Nom entreprise')
            ->assertDontSee('Nom grand public');
    }

    public function test_loops_naming_defaults_to_b2c_and_is_independent_from_blog_naming(): void
    {
        $org = $this->org(['blog_naming' => 'b2b']);
        $d = $this->domain($org, 'Nom grand public', 'Nom entreprise');

        $this->withCurrentOrganization($org);

        $this->assertSame('b2c', $org->fresh()->loops_naming);
        $this->assertSame('Nom grand public', $d->displayName('loops'));
        $this->assertSame('Nom entreprise', $d->displayName('blog'));
    }

    public function test_admin_can_set_loops_naming_from_the_organization_screen(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $org = $this->org(['platform_name' => 'Test Platform', 'global_color_mode' => 'light']);

        $this->actingAs($admin)
            ->put(route('admin.organizations.update', $org), [
                'name' => $org->name,
                'platform_name' => 'Test Platform',
                'global_color_mode' => 'light',
                'welcome_points' => $org->welcome_points,
                'loops_naming' => 'b2b',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('b2b', $org->fresh()->loops_naming);
    }

    // ── Non-régression Annuaire : aucun lien vers les compétences ───────────

    public function test_no_association_is_created_between_a_loop_and_skills(): void
    {
        $org = $this->org();
        $d = $this->domain($org, 'Informatique');
        $skill = Skill::create([
            'organization_id' => $org->id,
            'category_id' => $d->id,
            'name' => 'Reparation PC',
            'slug' => 'reparation-pc',
        ]);
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $loop->categories()->sync([$d->id]);

        // The domain keeps its skills (Annuaire untouched) but the Loop is only
        // ever linked to the domain — no Loop<->Skill pivot exists.
        $this->assertCount(1, $d->fresh()->skills);
        $this->assertFalse(Schema::hasTable('loop_skill'));
        $this->assertFalse(Schema::hasColumn('category_loop', 'skill_id'));
    }

    public function test_deleting_a_loop_removes_only_the_pivot_rows(): void
    {
        $org = $this->org();
        $d = $this->domain($org, 'Informatique');
        $loop = Loop::factory()->create(['organization_id' => $org->id]);
        $loop->categories()->sync([$d->id]);

        $loop->delete();

        $this->assertDatabaseMissing('category_loop', ['loop_id' => $loop->id]);
        $this->assertDatabaseHas('categories', ['id' => $d->id]);
    }
}
