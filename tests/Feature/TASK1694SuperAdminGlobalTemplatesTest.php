<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminSystemEmailTemplatesController as Ecran;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug O — l'écran SuperAdmin sait enfin créer du GLOBAL.
 *
 * ## La cause mécanique du désordre
 *
 * `store()` exigeait un `organization_id`. L'écran ne **savait pas** créer une
 * ligne globale : toute création naissait rattachée à une Organization, même
 * quand le texte n'avait rien de propre à elle. C'est ce qui a produit les 21
 * lignes mesurées le 23/10, toutes identiques au catalogue.
 *
 * ## Et le filtre ne savait pas les montrer
 *
 * Le filtre vide veut dire « toutes les Organizations », ce qui ne permettait
 * pas de demander « seulement les Global » — l'écran ne pouvait donc pas
 * montrer la cible visée par la décision de Cyril.
 */
class TASK1694SuperAdminGlobalTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = WelcomeNotification::SLUG;

    private Organization $organization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694o',
            'name' => 'Organisation 1694O',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        SystemEmailTemplate::query()->delete();
    }

    /** LE geste nouveau : créer une ligne SANS Organization. */
    public function test_the_superadmin_can_create_a_global_row(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.system-email-templates.store'), [
                'slug' => self::SLUG,
                'locale' => 'fr',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('system_email_templates', [
            'slug' => self::SLUG,
            'locale' => 'fr',
            'organization_id' => null,
        ]);
    }

    /**
     * Une ligne vide pour l'Organization reste possible — mais elle n'est plus
     * le seul choix.
     */
    public function test_creating_for_one_organization_still_works(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.system-email-templates.store'), [
                'slug' => self::SLUG,
                'locale' => 'fr',
                'organization_id' => $this->organization->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('system_email_templates', [
            'slug' => self::SLUG,
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * La ligne naît DÉSACTIVÉE, en Global comme ailleurs.
     *
     * Invariant de TASK-1681, et il compte d'autant plus ici : une ligne
     * globale active d'emblée prendrait le pas sur le repli Blade de **toutes**
     * les Organizations à la fois.
     */
    public function test_a_global_row_is_born_disabled(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('admin.system-email-templates.store'), [
                'slug' => self::SLUG,
                'locale' => 'fr',
            ]);

        $this->assertFalse(
            (bool) SystemEmailTemplate::query()->whereNull('organization_id')->sole()->enabled,
        );
    }

    /** Jamais de doublon : rejouer le geste ne crée pas une seconde ligne. */
    public function test_creating_twice_does_not_duplicate(): void
    {
        foreach ([1, 2] as $_) {
            $this->actingAs($this->superAdmin)
                ->post(route('admin.system-email-templates.store'), [
                    'slug' => self::SLUG,
                    'locale' => 'fr',
                ]);
        }

        $this->assertSame(1, SystemEmailTemplate::query()->whereNull('organization_id')->count());
    }

    /** Le filtre sait demander « seulement les Global ». */
    public function test_the_filter_can_ask_for_global_rows_only(): void
    {
        SystemEmailTemplate::query()->create([
            'slug' => self::SLUG, 'locale' => 'fr', 'organization_id' => null,
            'name' => 'LIGNE_GLOBALE_1694O', 'subject' => 'g', 'content_html' => '<p>g</p>',
            'variables' => ['name'], 'enabled' => true,
        ]);
        SystemEmailTemplate::query()->create([
            'slug' => self::SLUG, 'locale' => 'fr', 'organization_id' => $this->organization->id,
            'name' => 'LIGNE_TENANT_1694O', 'subject' => 't', 'content_html' => '<p>t</p>',
            'variables' => ['name'], 'enabled' => true,
        ]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['organization_id' => Ecran::FILTRE_GLOBAL]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('LIGNE_GLOBALE_1694O', $html);
        $this->assertStringNotContainsString('LIGNE_TENANT_1694O', $html);
    }

    /**
     * Et ce mot-clef ne peut pas être confondu avec une Organization.
     *
     * `global` est hors de l'espace des UUID. Mesuré plutôt que supposé : sur
     * PostgreSQL, une chaîne forgée comparée à une colonne `uuid` lève `22P02`
     * — un 500 là où il faut un écran. Leçon TASK-1683.
     */
    public function test_the_global_keyword_is_not_compared_to_a_uuid_column(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['organization_id' => Ecran::FILTRE_GLOBAL]))
            ->assertOk();

        $this->assertSame('global', Ecran::FILTRE_GLOBAL);
    }

    /** L'écran propose le choix, et « Global » y est le défaut. */
    public function test_the_screen_offers_the_global_scope(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', ['slug' => self::SLUG]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-system-email-scope', $html);

        $selecteur = substr($html, strpos($html, 'data-system-email-scope'));
        $selecteur = substr($selecteur, 0, strpos($selecteur, '</select>'));

        $this->assertStringContainsString('<option value="">', $selecteur, 'le choix Global doit exister');
        $this->assertLessThan(
            strpos($selecteur, (string) $this->organization->id),
            strpos($selecteur, '<option value="">'),
            'Global doit etre le PREMIER choix, donc le defaut',
        );
    }
}
