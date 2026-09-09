<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1409 — le compteur de services de l'Annuaire accorde son libelle.
 *
 * Le defaut : la carte de membre rendait toujours `$T['services']`, la forme
 * PLURIELLE de la terminologie, quel que soit le compteur. Roger lisait
 * « 1 micro-services ».
 *
 * Ce n'est ni le defaut de TASK-1404 (`__()` sur cle pluralisee, qui rendait
 * les deux formes collees), ni celui de TASK-1405 (prefixe qui doublait le
 * nombre). C'est un troisieme motif dans la meme famille : le libelle ne
 * s'accorde pas. Les trois cohabitaient sur cette page.
 *
 * `config/terms.php` fournit DEJA les deux formes (`service` / `services`) :
 * il n'y avait aucune terminologie a construire, seulement a choisir. Le
 * correctif ne touche donc ni la config ni le systeme de termes — c'est
 * expressement hors scope.
 *
 * Ces gardes mesurent le TEXTE NORMALISE servi : un `</span>` separe le nombre
 * du libelle, si bien qu'une assertion sur le HTML brut ne verrait jamais la
 * signature « 1 micro-services ».
 */
class TASK1409DirectoryServiceLabelAgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_single_service_gets_the_singular_label(): void
    {
        $texte = $this->renderDirectory(services: 1);

        $this->assertStringContainsString('1 '.config('terms.service'), $texte);
        $this->assertStringNotContainsString('1 '.config('terms.services'), $texte);
    }

    public function test_several_services_keep_the_plural_label(): void
    {
        $texte = $this->renderDirectory(services: 3);

        $this->assertStringContainsString('3 '.config('terms.services'), $texte);
    }

    /**
     * Zero prend le PLURIEL en francais comme en anglais dans cette
     * terminologie — « 0 micro-services ». C'est la forme deja servie
     * aujourd'hui, et le correctif ne doit pas la changer : il ne s'agit pas
     * de reecrire la regle d'accord, seulement de cesser de forcer le pluriel
     * sur 1.
     */
    public function test_zero_service_keeps_the_plural_label(): void
    {
        $texte = $this->renderDirectory(services: 0);

        $this->assertStringContainsString('0 '.config('terms.services'), $texte);
    }

    /**
     * La garde generique : aucun compteur de cette page ne doit rendre « 1 »
     * suivi d'un libelle terminant par « s ». Elle attrapera aussi un futur
     * compteur ajoute a cette carte.
     *
     * La classe est `[\p{L}-]+` et non `\p{L}+` : le trait d'union de
     * « micro-services » n'est PAS une lettre. Ecrite avec `\p{L}+`, cette
     * garde etait verte quoi qu'il arrive — elle cherchait une forme que la
     * terminologie du produit ne peut pas produire. C'est le temoin
     * d'instrument qui l'a revele, en rougissant sur la signature exacte du
     * defaut.
     */
    public function test_no_counter_forces_a_plural_label_on_one(): void
    {
        $texte = $this->renderDirectory(services: 1);

        $this->assertDoesNotMatchRegularExpression(
            '/\b1\s+[\p{L}-]+s\b/u',
            $texte,
            'Un compteur a 1 rend un libelle au pluriel.'
        );
    }

    /**
     * Temoin d'instrument : sans lui, une page vide ou une 302 passeraient
     * toutes les gardes negatives ci-dessus.
     */
    public function test_the_probe_really_renders_the_service_counter(): void
    {
        $texte = $this->renderDirectory(services: 3);

        $this->assertStringContainsString(__('directory.title'), $texte);
        $this->assertStringContainsString('3 '.config('terms.services'), $texte);

        // Et la garde generique doit savoir rougir : jouee a l'identique sur
        // la signature exacte du defaut.
        $this->assertMatchesRegularExpression('/\b1\s+[\p{L}-]+s\b/u', '1 micro-services');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function renderDirectory(int $services): string
    {
        $organization = Organization::factory()->create(['is_active' => true, 'locale' => 'fr']);
        $membre = User::factory()->create(['organization_id' => $organization->id]);

        app()->instance('current_organization', $organization);
        app()->setLocale('fr');

        $categorie = Category::factory()->create();

        Service::factory()->count($services)->create([
            'organization_id' => $organization->id,
            'user_id' => $membre->id,
            'category_id' => $categorie->id,
            'status' => 'active',
        ]);

        // TASK-1479 (P0 privacy) : l'annuaire n'est plus servi a un visiteur
        // ANONYME — il rendait 200 sans aucun cookie, sur des Organizations
        // privees comprises. Ce que cette sonde mesure — le libelle et le
        // compteur rendus — ne change pas ; elle regarde depuis un membre.
        $reponse = $this->actingAs($membre)->get('/membres');
        $reponse->assertOk();

        return $this->normalize($reponse->getContent());
    }

    private function normalize(string $html): string
    {
        $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $texte));
    }
}
