<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug R — « Possibilité de modifier les emails types », par Organization.
 *
 * ## La demande
 *
 * Cyril : « Je pense qu'on va aussi désactiver le fait qu'une organisation
 * puisse modifier les emails types [...] et ceci dans la page de
 * configuration, avec une case à cocher ».
 *
 * ## Décochée par défaut
 *
 * C'est la demande lue au pied de la lettre : la capacité est **retirée**, et
 * rendue au cas par cas. Un défaut à `true` aurait laissé la situation
 * inchangée et fait de la case une décoration.
 *
 * C'est un retrait de **capacité**, pas une perte de **données** : les lignes
 * déjà écrites restent, et le résolveur (bug M) continue de les servir.
 *
 * ## 403 et non 404
 *
 * L'écran **existe** ; c'est la permission qui manque. La garde de tenant du
 * même contrôleur rend 404 parce qu'elle répond à une autre question — « cette
 * ligne appartient-elle à cette Organization ? ». Confondre les deux rendrait
 * illisible ce qui est refusé et pourquoi.
 */
class TASK1694OrgSystemEmailEditabilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $orgAdmin;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694r',
            'is_active' => true,
        ]);

        $this->orgAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->organization->update(['admin_id' => $this->orgAdmin->id]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function gabarit(): SystemEmailTemplate
    {
        return SystemEmailTemplate::query()->create([
            'slug' => 'new_message',
            'locale' => 'fr',
            'organization_id' => $this->organization->id,
            'name' => 'Tenant 1694R',
            'subject' => 'Sujet 1694R',
            'content_html' => '<p>corps</p>',
            'variables' => ['name'],
            'enabled' => true,
        ]);
    }

    /** Par défaut, la capacité n'est pas là. */
    public function test_the_capability_is_off_by_default(): void
    {
        $this->assertFalse($this->organization->fresh()->canEditSystemEmails());
    }

    /** Et la liste est refusée — au SERVEUR, pas seulement masquée. */
    public function test_without_the_capability_the_list_is_refused(): void
    {
        $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.system-email-templates', $this->organization))
            ->assertForbidden();
    }

    /** L'édition aussi. */
    public function test_without_the_capability_editing_is_refused(): void
    {
        $gabarit = $this->gabarit();

        $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.system-email-templates.edit', [$this->organization, $gabarit]))
            ->assertForbidden();
    }

    /**
     * Et l'ÉCRITURE aussi — c'est la moitié qui compte.
     *
     * Une garde posée sur les deux lectures et oubliée sur l'écriture laisserait
     * la porte ouverte. Même famille que la leçon TASK-1689 : une garde
     * conditionnée aux lectures ne protège pas les écritures.
     */
    public function test_without_the_capability_saving_is_refused(): void
    {
        $gabarit = $this->gabarit();

        $this->actingAs($this->orgAdmin)
            ->put(route('organization.admin.system-email-templates.update', [$this->organization, $gabarit]), [
                'subject' => 'SUJET_FORCE_1694R',
                'content_html' => '<p>force</p>',
            ])
            ->assertForbidden();

        $this->assertSame('Sujet 1694R', $gabarit->fresh()->subject);
    }

    /** Cochée, tout redevient accessible. */
    public function test_with_the_capability_the_screen_opens_again(): void
    {
        $this->organization->update(['system_emails_editable' => true]);

        $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.system-email-templates', $this->organization))
            ->assertOk();
    }

    /** L'entrée de menu suit la capacité. */
    public function test_the_menu_entry_follows_the_capability(): void
    {
        $this->organization->update(['system_emails_editable' => true]);

        $avec = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.dashboard', $this->organization))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('organization.admin.system-email-templates', $this->organization),
            $avec,
        );

        $this->organization->update(['system_emails_editable' => false]);

        $sans = $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.dashboard', $this->organization))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('organization.admin.system-email-templates', $this->organization),
            $sans,
        );
    }

    /** Le SuperAdmin pose la case depuis la configuration de l'Organization. */
    public function test_the_superadmin_can_grant_the_capability(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.organizations.edit', $this->organization))
            ->assertOk()
            ->assertSee('data-admin-org-system-emails-editable', false);
    }

    /**
     * Retirer la capacité ne détruit AUCUNE ligne.
     *
     * Un retrait de capacité n'est pas une suppression de données : les lignes
     * déjà écrites restent, et le résolveur continue de les servir.
     */
    public function test_removing_the_capability_destroys_nothing(): void
    {
        $gabarit = $this->gabarit();

        $this->organization->update(['system_emails_editable' => true]);
        $this->organization->update(['system_emails_editable' => false]);

        $this->assertDatabaseHas('system_email_templates', ['id' => $gabarit->id]);
    }
}
