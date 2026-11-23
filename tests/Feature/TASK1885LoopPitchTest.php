<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1885 C7 (demande Cyril, 22/11) — le « Pitch » d'une Boucle : 12
 * caracteres maximum, modifiable par le proprietaire, l'admin d'Organization et
 * le SuperAdmin, affiche en surimpression de la couverture.
 */
class TASK1885LoopPitchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $proprietaire;

    private User $orgAdmin;

    private User $superAdmin;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['slug' => 'org-1885-c7', 'is_active' => true, 'is_public' => true, 'loops_enabled' => true]);
        $this->orgAdmin = User::factory()->complete()->create(['organization_id' => $this->org->id, 'email_verified_at' => now()]);
        $this->org->update(['admin_id' => $this->orgAdmin->id]);
        $this->superAdmin = User::factory()->complete()->create(['organization_id' => $this->org->id, 'is_admin' => true, 'email_verified_at' => now()]);
        $this->proprietaire = User::factory()->complete()->create(['organization_id' => $this->org->id, 'email_verified_at' => now()]);
        app()->instance('current_organization', $this->org);

        $this->loop = (new LoopService)->createLoop($this->proprietaire, 'Boucle pitch')->fresh();
    }

    private function urlProprietaire(): string
    {
        return route('organization.loops.update', ['organization' => $this->org->slug, 'loop' => $this->loop->id]);
    }

    public function test_owner_sets_trims_and_clears_the_pitch(): void
    {
        $this->actingAs($this->proprietaire)
            ->put($this->urlProprietaire(), ['name' => 'Boucle pitch', 'pitch' => '  Entraide  '])
            ->assertSessionHasNoErrors();
        $this->assertSame('Entraide', $this->loop->fresh()->pitch);

        // Champ absent = inchange.
        $this->actingAs($this->proprietaire)
            ->put($this->urlProprietaire(), ['name' => 'Renommee'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Entraide', $this->loop->fresh()->pitch);

        // Champ vide = efface.
        $this->actingAs($this->proprietaire)
            ->put($this->urlProprietaire(), ['name' => 'Renommee', 'pitch' => ''])
            ->assertSessionHasNoErrors();
        $this->assertNull($this->loop->fresh()->pitch);
    }

    public function test_pitch_longer_than_twelve_characters_is_refused(): void
    {
        $this->actingAs($this->proprietaire)
            ->put($this->urlProprietaire(), ['name' => 'Boucle pitch', 'pitch' => 'Treize carac'.'x'])
            ->assertSessionHasErrors('pitch');
        $this->assertNull($this->loop->fresh()->pitch);

        // 12 caracteres multi-octets passent : la limite compte des caracteres.
        $this->actingAs($this->proprietaire)
            ->put($this->urlProprietaire(), ['name' => 'Boucle pitch', 'pitch' => 'ÉÉÉÉÉÉÉÉÉÉÉÉ'])
            ->assertSessionHasNoErrors();
        $this->assertSame('ÉÉÉÉÉÉÉÉÉÉÉÉ', $this->loop->fresh()->pitch);
    }

    public function test_org_admin_and_super_admin_can_set_the_pitch(): void
    {
        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.loops.update', ['organization' => $this->org->slug, 'loop' => $this->loop->id]),
                ['name' => 'Boucle pitch', 'type' => $this->loop->type, 'pitch' => 'Admin'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Admin', $this->loop->fresh()->pitch);

        $this->actingAs($this->superAdmin)
            ->put(route('admin.loops.update', $this->loop), ['name' => 'Boucle pitch', 'visibility' => 'private', 'pitch' => 'Super'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Super', $this->loop->fresh()->pitch);
    }

    public function test_edit_screen_shows_the_field_and_the_cover_renders_the_pitch(): void
    {
        $this->actingAs($this->proprietaire)
            ->get(route('organization.loops.edit', ['organization' => $this->org->slug, 'loop' => $this->loop->id]))
            ->assertOk()
            ->assertSee('data-loop-pitch-field', false)
            ->assertSee('maxlength="12"', false);

        $this->loop->update(['pitch' => 'Ensemble']);

        $html = \Illuminate\Support\Facades\Blade::render('<x-loops.cover :loop="$l" />', ['l' => $this->loop->fresh()]);
        $this->assertStringContainsString('data-loop-pitch', $html);
        $this->assertStringContainsString('ENSEMBLE', $html);
        $this->assertStringContainsString('var(--bp-page', $html);

        $this->loop->update(['pitch' => null]);
        $sans = \Illuminate\Support\Facades\Blade::render('<x-loops.cover :loop="$l" />', ['l' => $this->loop->fresh()]);
        $this->assertStringNotContainsString('data-loop-pitch', $sans);
    }
}
