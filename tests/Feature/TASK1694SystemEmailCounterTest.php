<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminSystemEmailTemplatesController as Ecran;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1694, bug AD — combien de gabarits système, et combien le filtre en cache.
 *
 * **Demande de Cyril** : « ajouter un compteur pour indiquer le nombre de
 * messages types ».
 *
 * ## Deux nombres, pas un
 *
 * Le compte **affiché** seul laisserait croire que la base n'en contient pas
 * davantage dès qu'un filtre est posé — exactement le genre de chiffre qu'on
 * lit de travers, et qui fait conclure « il en manque » devant une liste
 * simplement filtrée.
 *
 * Le total porte donc sur la **table entière**, jamais sur le filtre courant.
 * Et il n'est montré **que** lorsqu'il diffère : un « 18 sur 18 » est du bruit.
 *
 * ## Et la répartition
 *
 * Global / rattachés : c'est la mesure qui dit si la cible du point 4 de Cyril
 * (« uniquement des Global ») est tenue, d'un coup d'œil.
 */
class TASK1694SystemEmailCounterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694ad',
            'is_active' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        SystemEmailTemplate::query()->delete();
    }

    private function poser(?string $organizationId, string $locale, string $slug): void
    {
        SystemEmailTemplate::query()->create([
            'organization_id' => $organizationId,
            'locale' => $locale,
            'slug' => $slug,
            'name' => 'Gabarit '.$slug.' '.$locale,
            'subject' => 'Sujet',
            'content_html' => '<p>corps</p>',
            'variables' => ['name'],
            'enabled' => true,
        ]);
    }

    private function html(array $query = []): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.system-email-templates', $query))
            ->assertOk()
            ->getContent();
    }

    /** LE compteur demandé. */
    public function test_the_screen_counts_the_templates(): void
    {
        $this->poser(null, 'fr', 'a');
        $this->poser(null, 'en', 'a');

        $html = $this->html();

        $this->assertStringContainsString('data-system-email-counters', $html);
        $this->assertStringContainsString('data-system-email-count-shown="2"', $html);
    }

    /** Il bouge avec la base. */
    public function test_the_counter_follows_the_table(): void
    {
        $this->poser(null, 'fr', 'a');
        $this->assertStringContainsString('data-system-email-count-shown="1"', $this->html());

        $this->poser(null, 'en', 'a');
        $this->assertStringContainsString('data-system-email-count-shown="2"', $this->html());
    }

    /**
     * Filtré, l'écran dit AUSSI le total.
     *
     * C'est la moitié qui empêche de lire le chiffre de travers : sans elle, un
     * filtre donnerait « 1 gabarit » et laisserait croire qu'il n'y en a qu'un.
     */
    public function test_under_a_filter_the_total_is_also_shown(): void
    {
        $this->poser(null, 'fr', 'a');
        $this->poser(null, 'en', 'a');
        $this->poser($this->organization->id, 'fr', 'b');

        $html = $this->html(['locale' => 'fr']);

        $this->assertStringContainsString('data-system-email-count-shown="2"', $html);
        $this->assertStringContainsString('data-system-email-count-total="3"', $html);
    }

    /**
     * Et sans filtre, le total n'est PAS répété.
     *
     * « 3 sur 3 » est du bruit. Sans cette moitié, afficher les deux nombres
     * partout passerait le test précédent.
     */
    public function test_without_a_filter_the_total_is_not_repeated(): void
    {
        $this->poser(null, 'fr', 'a');
        $this->poser(null, 'en', 'a');

        $this->assertStringNotContainsString('data-system-email-count-total=', $this->html());
    }

    /**
     * La répartition Global / rattachés.
     *
     * C'est la mesure qui dit si la cible du point 4 (« uniquement des
     * Global ») est tenue, d'un coup d'œil.
     */
    public function test_the_breakdown_separates_global_from_tenant_rows(): void
    {
        $this->poser(null, 'fr', 'a');
        $this->poser(null, 'en', 'a');
        $this->poser($this->organization->id, 'fr', 'b');

        $this->assertStringContainsString('data-system-email-count-breakdown="2/1"', $this->html());
    }

    /** Le total ignore le filtre par Organization, lui aussi. */
    public function test_the_total_ignores_the_organization_filter(): void
    {
        $this->poser(null, 'fr', 'a');
        $this->poser($this->organization->id, 'fr', 'b');

        $html = $this->html(['organization_id' => Ecran::FILTRE_GLOBAL]);

        $this->assertStringContainsString('data-system-email-count-shown="1"', $html);
        $this->assertStringContainsString('data-system-email-count-total="2"', $html);
    }

    /** Zéro gabarit se dit, au lieu d'un tableau muet. */
    public function test_an_empty_table_says_zero(): void
    {
        $this->assertStringContainsString('data-system-email-count-shown="0"', $this->html());
    }
}
