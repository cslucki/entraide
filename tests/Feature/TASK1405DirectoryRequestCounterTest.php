<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1405 — le compteur de demandes de l'Annuaire rend le nombre UNE fois.
 *
 * Le defaut : la carte de membre rendait le nombre deux fois — une premiere
 * fois dans le `<span>` stylé qui porte la couleur, une seconde fois DANS la
 * chaine traduite, parce que la cle `directory.request_count` contenait deja
 * `:count` (`:count demande|:count demandes`). Roger lisait « 7 7 demandes ».
 *
 * Ce n'est PAS le defaut de TASK-1404 : ici `trans_choice()` etait correctement
 * appele et la pluralisation etait juste. C'est le prefixe qui doublait le
 * nombre. Le temoin de la bonne forme etait dans le meme fichier, quatre lignes
 * plus haut : le compteur de services rend un nombre stylé suivi d'un libelle
 * SANS `:count`. Le correctif aligne le compteur de demandes sur ce patron,
 * via une cle pluralisee sans `:count` (`directory.request_label`).
 *
 * Ces gardes mesurent le TEXTE NORMALISE servi (balises retirees, espaces
 * ramenes a un seul), et non le HTML brut : un `</span>` separe le nombre du
 * libelle, si bien qu'une assertion sur le HTML brut ne verrait JAMAIS la
 * signature « 7 7 demandes » — ni avant ni apres le correctif.
 */
class TASK1405DirectoryRequestCounterTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_request_counter_renders_the_number_once(): void
    {
        $texte = $this->renderDirectory('en', requests: 7);

        $this->assertStringContainsString('7 requests', $texte);
        $this->assertStringNotContainsString('7 7 requests', $texte);
    }

    public function test_french_request_counter_renders_the_number_once(): void
    {
        $texte = $this->renderDirectory('fr', requests: 7);

        $this->assertStringContainsString('7 demandes', $texte);
        $this->assertStringNotContainsString('7 7 demandes', $texte);
    }

    public function test_request_counter_is_singular_for_one_request(): void
    {
        $anglais = $this->renderDirectory('en', requests: 1);

        $this->assertStringContainsString('1 request', $anglais);
        $this->assertStringNotContainsString('1 1 request', $anglais);
        $this->assertStringNotContainsString('1 requests', $anglais);

        $francais = $this->renderDirectory('fr', requests: 1);

        $this->assertStringContainsString('1 demande', $francais);
        $this->assertStringNotContainsString('1 1 demande', $francais);
        $this->assertStringNotContainsString('1 demandes', $francais);
    }

    /**
     * La garde generique, celle qui attrape aussi un compteur qu'on aurait
     * oublie : aucun nombre ne doit etre suivi du MEME nombre puis d'un mot.
     * La backreference `\1` est ce qui distingue « 7 7 demandes » (le defaut)
     * d'une suite legitime de deux nombres differents.
     */
    public function test_no_counter_repeats_its_number_in_any_language(): void
    {
        foreach (['en', 'fr'] as $locale) {
            foreach ([1, 2, 7] as $nombre) {
                $texte = $this->renderDirectory($locale, requests: $nombre);

                $this->assertDoesNotMatchRegularExpression(
                    '/\b(\d+)\s+\1\s+\p{L}/u',
                    $texte,
                    "Nombre rendu en double en [{$locale}] pour count={$nombre}."
                );
            }
        }
    }

    /**
     * Temoin d'instrument : sans lui, une page d'annuaire vide, une 302 ou une
     * carte de membre qui ne rendrait plus du tout le compteur passeraient
     * TOUTES les gardes negatives ci-dessus.
     */
    public function test_the_probe_really_renders_the_request_counter(): void
    {
        $texte = $this->renderDirectory('en', requests: 7);

        $this->assertStringContainsString('Member directory', $texte);
        $this->assertStringContainsString('7 requests', $texte);

        // Et la garde generique doit savoir rougir : elle rougit sur la
        // signature exacte du defaut, jouee ici a l'identique sur une chaine
        // temoin. Sans cette verification, une regex qui ne matche jamais rien
        // (classe Unicode mal ecrite, par exemple) passerait pour une garde.
        $this->assertMatchesRegularExpression('/\b(\d+)\s+\1\s+\p{L}/u', '7 7 requests');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Rend `/membres` avec UN membre portant `$requests` demandes ouvertes, et
     * retourne le texte normalise de la page.
     */
    private function renderDirectory(string $locale, int $requests): string
    {
        $organization = Organization::factory()->create([
            'is_active' => true,
            'locale' => $locale,
        ]);

        $membre = User::factory()->create(['organization_id' => $organization->id]);

        app()->instance('current_organization', $organization);
        app()->setLocale($locale);

        $categorie = Category::factory()->create();

        ServiceRequest::factory()->count($requests)->create([
            'organization_id' => $organization->id,
            'user_id' => $membre->id,
            'category_id' => $categorie->id,
            'status' => 'open',
        ]);

        $reponse = $this->get('/membres');
        $reponse->assertOk();

        return $this->normalize($reponse->getContent());
    }

    /**
     * Balises retirees, entites decodees, espaces (y compris les insecables
     * que Blade peut produire) ramenes a un seul. C'est le texte que Roger lit.
     */
    private function normalize(string $html): string
    {
        $texte = strip_tags($html);
        $texte = html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $texte));
    }
}
