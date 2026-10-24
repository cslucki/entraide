<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug AB — sortir de l'impasse : « Adapter » un gabarit Plateforme.
 *
 * ## L'impasse, décrite par Cyril
 *
 * « L'utilisateur n'a aucun bouton Créer et une liste vide — c'est donc une
 * impasse pour lui. »
 *
 * C'était exact, et c'était une conséquence de mon bug N : l'écran ne listait
 * que les lignes **rattachées** à l'Organization, et le seeder n'en crée plus
 * aucune. OFSH voyait un tableau vide, sans aucun geste possible.
 *
 * ## Une seule ligne par (slug, langue), et la source est dite
 *
 * La question d'un administrateur est « quel email part de chez moi, et d'où
 * vient-il ? » — pas « quelles lignes existent en base ». L'écran montre donc
 * la version qui part **réellement**, celle que le résolveur du bug M
 * choisirait.
 *
 * ## On ne modifie pas un Global, on le clone
 *
 * Décision de Cyril : « on ne peut pas modifier les emails global, mais on peut
 * les cloner ». Une Organization qui éditerait la ligne globale changerait
 * l'email de **toutes** les autres.
 */
class TASK1694OrgAdaptSystemEmailTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694ab',
            'locale' => 'fr',
            'is_active' => true,
            'system_emails_editable' => true,
        ]);

        $this->orgAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);

        $this->organization->update(['admin_id' => $this->orgAdmin->id]);
    }

    /**
     * La ligne Plateforme, dans l'etat que CE test decrit.
     *
     * `updateOrCreate` et non `firstOrCreate` : la migration de normalisation
     * (bug Q) pose deja 18 lignes globales, et `RefreshDatabase` joue les
     * migrations. Un `firstOrCreate` rendait donc la ligne de la MIGRATION, dont
     * le sujet n'est pas celui que le test annonce — il mesurait un texte qu'il
     * n'avait pas ecrit. Meme famille que la lecon du bug T : un test doit creer
     * l'etat qu'il mesure, pas en heriter.
     */
    private function global(string $slug = 'new_message', string $locale = 'fr'): SystemEmailTemplate
    {
        return SystemEmailTemplate::query()->updateOrCreate(
            ['organization_id' => null, 'locale' => $locale, 'slug' => $slug],
            [
                'name' => 'Gabarit plateforme '.$slug,
                'subject' => 'SUJET_PLATEFORME',
                'content_html' => '<p>texte plateforme</p>',
                'variables' => ['name'],
                'enabled' => true,
            ],
        );
    }

    private function liste(): string
    {
        return $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.system-email-templates', $this->organization))
            ->assertOk()
            ->getContent();
    }

    /** LE défaut : l'écran n'est plus vide, il montre les gabarits Plateforme. */
    public function test_the_platform_templates_are_listed(): void
    {
        $global = $this->global();

        $html = $this->liste();

        $this->assertStringContainsString('data-system-email-row="'.$global->slug.'"', $html);
        $this->assertStringContainsString('data-system-email-source="platform"', $html);
        $this->assertStringContainsString('data-system-email-adapt="'.$global->id.'"', $html);
    }

    /** Et jamais le bouton « Modifier » sur un gabarit Plateforme. */
    public function test_a_platform_template_offers_no_edit_link(): void
    {
        $global = $this->global();

        $this->assertStringNotContainsString(
            'data-system-email-edit="'.$global->id.'"',
            $this->liste(),
            'editer la ligne globale changerait l\'email de TOUTES les Organizations',
        );
    }

    /** LE geste : adapter crée une copie rattachée à l'Organization. */
    public function test_adapting_creates_a_copy_for_this_organization(): void
    {
        $global = $this->global();

        $this->actingAs($this->orgAdmin)
            ->post(route('organization.admin.system-email-templates.adapt', [$this->organization, $global]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('system_email_templates', [
            'organization_id' => $this->organization->id,
            'slug' => $global->slug,
            'locale' => $global->locale,
            'subject' => 'SUJET_PLATEFORME',
        ]);
    }

    /**
     * La copie naît DÉSACTIVÉE.
     *
     * Invariant TASK-1681, et il compte ici : tant qu'elle est décochée, le
     * résolveur continue de servir la version Plateforme. Une copie active
     * d'emblée remplacerait l'email sans que personne ne l'ait relu.
     */
    public function test_the_copy_is_born_disabled(): void
    {
        $global = $this->global();

        $this->actingAs($this->orgAdmin)
            ->post(route('organization.admin.system-email-templates.adapt', [$this->organization, $global]));

        $this->assertFalse(
            (bool) SystemEmailTemplate::query()
                ->where('organization_id', $this->organization->id)
                ->where('slug', $global->slug)
                ->sole()
                ->enabled,
        );
    }

    /** La ligne GLOBALE n'est pas touchée. */
    public function test_the_global_row_is_left_untouched(): void
    {
        $global = $this->global();

        $this->actingAs($this->orgAdmin)
            ->post(route('organization.admin.system-email-templates.adapt', [$this->organization, $global]));

        $this->assertSame('SUJET_PLATEFORME', $global->fresh()->subject);
        $this->assertTrue((bool) $global->fresh()->enabled);
        $this->assertNull($global->fresh()->organization_id);
    }

    /** Une fois adapté, le gabarit n'apparaît plus qu'UNE fois, côté Organization. */
    public function test_once_adapted_the_row_is_shown_once_as_the_organizations(): void
    {
        $global = $this->global();

        $this->actingAs($this->orgAdmin)
            ->post(route('organization.admin.system-email-templates.adapt', [$this->organization, $global]));

        $html = $this->liste();

        $this->assertSame(
            1,
            substr_count($html, 'data-system-email-row="'.$global->slug.'"'),
            'un gabarit adapte ne doit pas apparaitre deux fois',
        );
        $this->assertStringContainsString('data-system-email-source="organization"', $html);
        $this->assertStringNotContainsString('data-system-email-adapt="'.$global->id.'"', $html);
    }

    /** Rejouer le geste ne crée pas de doublon. */
    public function test_adapting_twice_creates_no_duplicate(): void
    {
        $global = $this->global();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->orgAdmin)
                ->post(route('organization.admin.system-email-templates.adapt', [$this->organization, $global]));
        }

        $this->assertSame(
            1,
            SystemEmailTemplate::query()
                ->where('organization_id', $this->organization->id)
                ->where('slug', $global->slug)
                ->count(),
        );
    }

    /**
     * On ne clone pas le gabarit d'une AUTRE Organization.
     *
     * Ce serait faire franchir une frontière de tenant à un texte. 404, comme
     * la garde voisine de ce contrôleur.
     */
    public function test_another_organizations_template_cannot_be_cloned(): void
    {
        $autre = Organization::factory()->create(['slug' => 'org-1694ab-autre', 'is_active' => true]);

        $sien = SystemEmailTemplate::query()->create([
            'organization_id' => $autre->id,
            'locale' => 'fr',
            'slug' => 'new_message',
            'name' => 'Le sien',
            'subject' => 'SUJET_AUTRE_ORG',
            'content_html' => '<p>x</p>',
            'variables' => ['name'],
            'enabled' => true,
        ]);

        $this->actingAs($this->orgAdmin)
            ->post(route('organization.admin.system-email-templates.adapt', [$this->organization, $sien]))
            ->assertNotFound();

        $this->assertDatabaseMissing('system_email_templates', [
            'organization_id' => $this->organization->id,
            'subject' => 'SUJET_AUTRE_ORG',
        ]);
    }

    /** Et la capacité du bug R garde ce geste, au serveur. */
    public function test_without_the_capability_adapting_is_refused(): void
    {
        $global = $this->global();
        $this->organization->update(['system_emails_editable' => false]);

        $this->actingAs($this->orgAdmin)
            ->post(route('organization.admin.system-email-templates.adapt', [$this->organization, $global]))
            ->assertForbidden();

        $this->assertDatabaseMissing('system_email_templates', [
            'organization_id' => $this->organization->id,
            'slug' => $global->slug,
        ]);
    }
}
