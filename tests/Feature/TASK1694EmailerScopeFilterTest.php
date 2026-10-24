<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminEmailTemplatesController as Ecran;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug AC — toutes les Organizations, et de quoi en isoler une.
 *
 * ## Ce que l'écran taisait
 *
 * Mesure du 24/10 : cette liste n'a **jamais** filtré par tenant. Elle
 * affichait donc des gabarits de plusieurs Organizations **sans jamais dire à
 * laquelle chacun appartient**.
 *
 * Le défaut n'était pas ce qui manquait à la liste, c'était **ce qu'elle
 * taisait** : deux lignes au même nom pouvaient venir de deux Organizations, et
 * rien ne les distinguait. La colonne vient donc avant le filtre dans l'ordre
 * des raisons — un filtre sur une donnée invisible ne se vérifie pas.
 */
class TASK1694EmailerScopeFilterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-ac-1', 'name' => 'Org AC 1', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'org-ac-2', 'name' => 'Org AC 2', 'is_active' => true]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        EmailTemplate::query()->delete();

        EmailTemplate::factory()->create(['organization_id' => $this->orgA->id, 'slug' => 'chez-a', 'name' => 'GABARIT_A']);
        EmailTemplate::factory()->create(['organization_id' => $this->orgB->id, 'slug' => 'chez-b', 'name' => 'GABARIT_B']);
        EmailTemplate::factory()->create(['organization_id' => null, 'slug' => 'partout', 'name' => 'GABARIT_GLOBAL']);
    }

    private function html(array $query = []): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.email-templates', $query))
            ->assertOk()
            ->getContent();
    }

    /** Par défaut : TOUTES les organisations, globaux compris. */
    public function test_by_default_every_organization_is_shown(): void
    {
        $html = $this->html();

        foreach (['GABARIT_A', 'GABARIT_B', 'GABARIT_GLOBAL'] as $nom) {
            $this->assertStringContainsString($nom, $html);
        }
    }

    /** Chaque ligne DIT à quelle Organization elle appartient. */
    public function test_each_row_names_its_organization(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-emailer-row-scope="org-ac-1"', $html);
        $this->assertStringContainsString('data-emailer-row-scope="org-ac-2"', $html);
        $this->assertStringContainsString('data-emailer-row-scope="global"', $html);
    }

    /** Le filtre isole UNE organisation. */
    public function test_the_filter_isolates_one_organization(): void
    {
        $html = $this->html(['organization_id' => $this->orgA->id]);

        $this->assertStringContainsString('GABARIT_A', $html);
        $this->assertStringNotContainsString('GABARIT_B', $html);
        $this->assertStringNotContainsString('GABARIT_GLOBAL', $html);
    }

    /** Et « Global » est une valeur du filtre, pas son absence. */
    public function test_global_is_a_filter_value(): void
    {
        $html = $this->html(['organization_id' => Ecran::CIBLE_GLOBALE]);

        $this->assertStringContainsString('GABARIT_GLOBAL', $html);
        $this->assertStringNotContainsString('GABARIT_A', $html);
        $this->assertStringNotContainsString('GABARIT_B', $html);
    }

    /**
     * Une cible forgée ne filtre RIEN — et ne lève rien non plus.
     *
     * Sur PostgreSQL, comparer une chaîne quelconque à une colonne `uuid` lève
     * `22P02` : un 500 là où il faut une liste. Invisible sous SQLite, d'où la
     * vérification sur les deux moteurs. Leçon TASK-1683.
     */
    public function test_a_forged_scope_does_not_break_the_screen(): void
    {
        $html = $this->html(['organization_id' => 'pas-un-uuid']);

        $this->assertStringContainsString('GABARIT_A', $html);
        $this->assertStringContainsString('GABARIT_GLOBAL', $html);
    }

    /** Le menu existe, et propose chaque Organization. */
    public function test_the_screen_offers_the_scope_menu(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('data-emailer-scope-filter', $html);
        $this->assertStringContainsString('value="'.$this->orgA->id.'"', $html);
        $this->assertStringContainsString('value="'.$this->orgB->id.'"', $html);
    }

    /** Le filtre survit à la pagination et à la recherche. */
    public function test_the_filter_survives_search(): void
    {
        $html = $this->html(['organization_id' => $this->orgB->id, 'search' => 'GABARIT']);

        $this->assertStringContainsString('GABARIT_B', $html);
        $this->assertStringNotContainsString('GABARIT_A', $html);
    }
}
