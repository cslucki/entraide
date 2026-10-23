<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AS — « Agents profil IA » rapatrié sur l'écran de
 * l'Organization.
 *
 * ## Pourquoi déplacer
 *
 * Le réglage se posait depuis `/admin/ai-config`, dans une liste de TOUTES les
 * Organizations — loin de l'interrupteur IA, du credential et du budget, qui
 * vivent sur l'écran de l'Organization. Tout ce qui concerne l'IA d'une
 * Organization se règle désormais au même endroit.
 *
 * ## Ce qui ne change pas : l'autorité
 *
 * Le réglage était DÉJÀ réservé au SuperAdmin, puisqu'il vivait sur
 * l'administration plateforme. La garde est donc explicite côté serveur — même
 * idiome que `updateAiCredentialMode()` (TASK-1306). Un admin d'Organization
 * ne voit pas la section, et surtout ne peut pas écrire.
 */
class TASK1693AiProfilesOnOrganizationScreenTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $adminOrg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693as',
            'is_active' => true,
            'ai_profiles_enabled' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);

        $this->adminOrg = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);

        $this->organization->update(['admin_id' => $this->adminOrg->id]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function urlEcran(): string
    {
        return route('organization.admin.ai', ['organization' => $this->organization->slug]);
    }

    private function urlEcriture(): string
    {
        return route('organization.admin.ai.profiles.update', ['organization' => $this->organization->slug]);
    }

    /** La section est là, sur l'écran IA de l'Organization. */
    public function test_the_section_lives_on_the_organization_ai_screen(): void
    {
        $html = $this->actingAs($this->superAdmin)->get($this->urlEcran())->assertOk()->getContent();

        $this->assertStringContainsString('data-ai-profiles-section', $html);
        $this->assertStringContainsString('data-ai-profiles-enabled="1"', $html);
        $this->assertStringContainsString(e(__('admin.ai_profile_toggle_label')), $html);
    }

    /** Et elle écrit vraiment. */
    public function test_the_superadmin_can_turn_it_off_and_on(): void
    {
        $this->actingAs($this->superAdmin)
            ->put($this->urlEcriture(), ['ai_profiles_enabled' => '0'])
            ->assertRedirect($this->urlEcran())
            ->assertSessionHas('success');

        $this->assertFalse((bool) $this->organization->fresh()->ai_profiles_enabled);

        $this->actingAs($this->superAdmin)->put($this->urlEcriture(), ['ai_profiles_enabled' => '1']);

        $this->assertTrue((bool) $this->organization->fresh()->ai_profiles_enabled);
    }

    /**
     * L'AUTORITÉ : un admin d'Organization ne peut pas écrire.
     *
     * La garde est au SERVEUR. Masquer la section dans la vue serait un
     * confort, jamais une protection : l'adresse resterait atteignable.
     */
    public function test_an_organization_admin_cannot_write(): void
    {
        $this->actingAs($this->adminOrg)
            ->put($this->urlEcriture(), ['ai_profiles_enabled' => '0'])
            ->assertForbidden();

        $this->assertTrue((bool) $this->organization->fresh()->ai_profiles_enabled,
            'le reglage ne doit pas avoir bouge');
    }

    /** Et il ne voit pas la section non plus. */
    public function test_an_organization_admin_does_not_see_the_section(): void
    {
        $html = $this->actingAs($this->adminOrg)->get($this->urlEcran())->assertOk()->getContent();

        $this->assertStringNotContainsString('data-ai-profiles-section', $html);
    }

    /**
     * `/admin/ai-config` garde un RENVOI, plus un formulaire.
     *
     * Deux formulaires écrivant la même colonne finissent par diverger, et
     * l'un des deux devient le mauvais.
     */
    public function test_the_platform_page_keeps_a_pointer_not_a_form(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.ai-config'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ai-profiles-pointer="'.$this->organization->slug.'"', $html);
        $this->assertStringContainsString($this->urlEcran(), $html);
    }

    /** L'ancienne adresse d'écriture n'existe plus du tout. */
    public function test_the_old_writer_is_gone(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('admin.ai-config.profile'),
            'deux ecrivains pour une meme colonne finissent par diverger',
        );
    }
}
