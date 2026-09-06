<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1404 — les compteurs pluralises de la page profil sont rendus par
 * `trans_choice()`, pas par `__()`.
 *
 * Le defaut : `__()` ne connait pas le separateur `|`. Sur une cle
 * `:count service|:count services`, il renvoie la chaine ENTIERE, les deux
 * formes collees — Roger lisait « 1 service|1 services ».
 *
 * Les cles etaient correctes des deux cotes : c'est l'APPEL qui etait faux.
 * Aucune modification de `lang/` n'a donc ete necessaire — et le temoin qui
 * rend le diagnostic certain est dans le meme fichier : `profile.exchanges`
 * (ligne 68) utilisait DEJA `trans_choice` et s'affichait correctement.
 *
 * Ces gardes mesurent le HTML SERVI, pas la presence de `trans_choice` dans
 * la source : c'est le rendu qui etait casse, c'est le rendu qu'on protege.
 */
class TASK1404ProfileCountPluralizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_counts_are_singular_for_one_item(): void
    {
        $rendu = $this->renderProfile('en', services: 1, requests: 1, articles: 1);

        $this->assertStringContainsString('1 service', $rendu);
        $this->assertStringContainsString('1 request', $rendu);
        $this->assertStringContainsString('1 article', $rendu);

        $this->assertStringNotContainsString('1 service|1 services', $rendu);
        $this->assertStringNotContainsString('1 request|1 requests', $rendu);
        $this->assertStringNotContainsString('1 article|1 articles', $rendu);
    }

    public function test_english_counts_are_plural_for_two_items(): void
    {
        $rendu = $this->renderProfile('en', services: 2, requests: 2, articles: 2);

        $this->assertStringContainsString('2 services', $rendu);
        $this->assertStringContainsString('2 requests', $rendu);
        $this->assertStringContainsString('2 articles', $rendu);

        $this->assertStringNotContainsString('2 service|2 services', $rendu);
        $this->assertStringNotContainsString('2 request|2 requests', $rendu);
        $this->assertStringNotContainsString('2 article|2 articles', $rendu);
    }

    public function test_french_counts_are_singular_for_one_item(): void
    {
        $rendu = $this->renderProfile('fr', services: 1, requests: 1, articles: 1);

        $this->assertStringContainsString('1 service', $rendu);
        $this->assertStringContainsString('1 demande', $rendu);
        $this->assertStringContainsString('1 article', $rendu);

        $this->assertStringNotContainsString('1 demande|1 demandes', $rendu);
    }

    public function test_french_counts_are_plural_for_two_items(): void
    {
        $rendu = $this->renderProfile('fr', services: 2, requests: 2, articles: 2);

        $this->assertStringContainsString('2 services', $rendu);
        $this->assertStringContainsString('2 demandes', $rendu);
        $this->assertStringContainsString('2 articles', $rendu);

        $this->assertStringNotContainsString('2 demande|2 demandes', $rendu);
    }

    /**
     * La garde generique, celle qui attrape aussi un compteur qu'on aurait
     * oublie : AUCUNE forme `<nombre> <mot>|` ne doit survivre dans le HTML
     * servi. On la joue dans les deux langues et sur les deux cardinalites.
     *
     * Elle ne cherche pas un `|` nu — la page en contient legitimement
     * ailleurs (attributs, scripts) : elle cherche la signature exacte du
     * defaut, un `|` colle a un compteur.
     */
    public function test_no_raw_pluralization_separator_survives_in_any_language(): void
    {
        foreach (['en', 'fr'] as $locale) {
            foreach ([1, 2] as $nombre) {
                $rendu = $this->renderProfile($locale, services: $nombre, requests: $nombre, articles: $nombre);

                $this->assertDoesNotMatchRegularExpression(
                    '/\d+\s+\p{L}+\|/u',
                    $rendu,
                    "Separateur de pluriel brut rendu en [{$locale}] pour count={$nombre}."
                );
            }
        }
    }

    /**
     * Temoin d'instrument : sans lui, une page profil vide (ou une 302)
     * passerait TOUTES les gardes negatives ci-dessus.
     */
    public function test_the_probe_really_renders_the_three_counters(): void
    {
        $rendu = $this->renderProfile('en', services: 2, requests: 2, articles: 2);

        $this->assertStringContainsString('2 services', $rendu);
        $this->assertStringContainsString('2 requests', $rendu);
        $this->assertStringContainsString('2 articles', $rendu);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function renderProfile(string $locale, int $services, int $requests, int $articles): string
    {
        $organization = Organization::factory()->create(['locale' => $locale]);
        $membre = User::factory()->create(['organization_id' => $organization->id]);
        $visiteur = User::factory()->create(['organization_id' => $organization->id]);
        app()->instance('current_organization', $organization);
        app()->setLocale($locale);

        $categorie = Category::factory()->create();

        Service::factory()->count($services)->create([
            'user_id' => $membre->id,
            'category_id' => $categorie->id,
            'status' => 'active',
        ]);

        ServiceRequest::factory()->count($requests)->create([
            'user_id' => $membre->id,
            'category_id' => $categorie->id,
            'status' => 'open',
        ]);

        for ($i = 0; $i < $articles; $i++) {
            BlogPost::create([
                'organization_id' => $organization->id,
                'user_id' => $membre->id,
                'title' => 'Article '.$i.' '.Str::uuid(),
                'slug' => 'article-'.Str::uuid(),
                'content' => '<p>x</p>',
                'status' => 'published',
                'published_at' => now()->subMinute(),
            ]);
        }

        $reponse = $this->actingAs($visiteur)->get(route('organization.profile.show', [
            'organization' => $organization->slug,
            'user' => $membre->id,
        ]));

        $reponse->assertOk();

        return $reponse->getContent();
    }
}
