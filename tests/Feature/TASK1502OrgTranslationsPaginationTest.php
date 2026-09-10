<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1502 — P0 de l'audit TASK-1501 : /org/{organization}/admin/translations
 * repondait HTTP 500 (« Allowed memory size of 134217728 bytes exhausted, tried
 * to allocate 52717968 »). La page rendait les ~6 100 entrees de traduction en
 * une seule passe Blade, chacune avec sa modale et son formulaire.
 *
 * Ce que ces tests mesurent :
 * - la page rend au plus 100 entrees, et annonce le total reel (statistiques
 *   sur l'ensemble filtre, pas sur la page) ;
 * - le cout memoire d'UNE requete reste borne — mesure en delta de pic, pas en
 *   `memory_limit` (baisser la limite sous l'usage courant du runner ferait
 *   tomber PHPUnit lui-meme, pas la page) ;
 * - les filtres survivent au changement de page ;
 * - une page hors plage ne casse rien.
 *
 * Sabotage verifie : `$perPage = PHP_INT_MAX` -> le delta de pic depasse le
 * seuil et le test « rows » rougit.
 */
class TASK1502OrgTranslationsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_public' => true, 'slug' => 'org-t1502']);
        $this->orgAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $this->org->update(['admin_id' => $this->orgAdmin->id]);
    }

    private function page(array $query = []): string
    {
        return $this->actingAs($this->orgAdmin)
            ->get(route('organization.admin.translations', ['organization' => $this->org->slug] + $query))
            ->assertOk()
            ->getContent();
    }

    private function rowsIn(string $html): int
    {
        return preg_match_all('/<tr x-data="\{ showModal: false \}"/', $html);
    }

    public function test_the_page_renders_at_most_one_hundred_entries_and_announces_the_real_total(): void
    {
        $html = $this->page();

        preg_match('/data-translations-total="(\d+)"/', $html, $total);
        preg_match('/data-translations-per-page="(\d+)"/', $html, $perPage);
        $this->assertNotEmpty($total, 'le marqueur de total manque');
        $this->assertGreaterThan(100, (int) $total[1], 'le depot compte des milliers d\'entrees : le total doit les annoncer');
        $this->assertSame(100, (int) $perPage[1]);

        $rows = $this->rowsIn($html);
        $this->assertLessThanOrEqual(100, $rows, 'plus d\'une page rendue d\'un coup');
        $this->assertGreaterThan(0, $rows);
        $this->assertStringContainsString('data-translations-pagination', $html, 'la pagination n\'est pas rendue');
        $this->assertStringContainsString('page=2', $html);
    }

    public function test_one_request_keeps_its_memory_cost_bounded(): void
    {
        // Premiere requete pour chauffer caches et autoloader : on mesure la seconde.
        $this->page();
        // `memory_get_peak_usage(true)` lit les blocs deja RESERVES par l'allocateur
        // pour les tests precedents : sabote (rendu complet), le delta restait a
        // zero et le test ne pouvait pas rougir. On remet le pic a zero et on lit
        // l'usage REEL (false) : c'est cette mesure-la que le sabotage fait monter.
        memory_reset_peak_usage();
        $before = memory_get_usage(false);
        $this->page();
        $delta = memory_get_peak_usage(false) - $before;

        // Avant TASK-1502, le rendu tentait une allocation unique de 52 Mo au-dela
        // de 128 Mo. Le seuil de 24 Mo laisse de la marge a la page tout en
        // rougissant des qu'on rend a nouveau tout d'un coup.
        $this->assertLessThan(24 * 1024 * 1024, $delta, 'la page a coute '.round($delta / 1048576, 1).' Mo de pic supplementaire');
    }

    public function test_filters_survive_the_page_change(): void
    {
        $html = $this->page(['group' => 'navigation', 'page' => 1]);

        $this->assertMatchesRegularExpression('/href="[^"]*group=navigation[^"]*page=2/', $html, 'le lien vers la page 2 perd le filtre de groupe');
        $this->assertStringContainsString('data-translations-page="1"', $html);
    }

    public function test_statistics_count_the_whole_filtered_set_not_the_page(): void
    {
        $html = $this->page(['group' => 'navigation']);

        preg_match('/data-translations-total="(\d+)"/', $html, $total);
        $this->assertGreaterThan($this->rowsIn($html), (int) $total[1], 'le total annonce doit depasser les lignes de la page');
    }

    public function test_a_page_beyond_the_range_renders_without_error(): void
    {
        $html = $this->page(['page' => 9999]);

        $this->assertSame(0, $this->rowsIn($html));
        $this->assertStringContainsString('data-translations-page="9999"', $html);
    }
}
