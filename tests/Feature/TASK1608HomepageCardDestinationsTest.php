<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1608, addendum MASTER — les quatre cartes de `hero-v2`.
 *
 * ## Deux defauts, les memes quatre lignes
 *
 * ### 1. Le mapping mentait
 *
 * Mesure AVANT correctif, dans `organization/hero-v2.blade.php` :
 *
 * | carte | libelle par defaut | destination |
 * |---|---|---|
 * | `card-create` | « J'explore une piste » | `route('members.index')` — l'Annuaire |
 * | `card-meet` | « Je cree du lien » | `route('boucles.index')` |
 *
 * Le libelle et la destination ne racontaient pas la meme action. Arbitrage
 * MASTER : explorer mene aux CONTENUS (Blog), creer du lien mene aux PERSONNES
 * (Annuaire). Consequence explicitement acceptee : plus aucune carte ne mene
 * aux Boucles.
 *
 * ### 2. La portee
 *
 * Les quatre `href` visaient les routes GLOBALES sur une page servie sous
 * `/org/{slug}`. Seule `main` utilise ce gabarit, et `main` etant
 * l'Organization par defaut, la fuite etait INVISIBLE — c'est pourquoi ce
 * fichier teste aussi une Organization NON PAR DEFAUT.
 *
 * ## Le texte ne pilote rien
 *
 * Un admin personnalise le LIBELLE ; la destination suit l'identifiant
 * fonctionnel de la carte, jamais sa chaine.
 */
class TASK1608HomepageCardDestinationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    private function organisation(bool $defaut, array $homepage = []): Organization
    {
        return Organization::factory()->create([
            'slug' => ($defaut ? 'main-' : 'autre-').bin2hex(random_bytes(4)),
            'is_active' => true,
            'is_public' => true,
            'is_default' => $defaut,
            'loops_enabled' => true,
            'homepage_template' => 'bouclepro_hero_v2',
            'homepage_settings' => $homepage,
        ]);
    }

    private function landing(Organization $organization)
    {
        app()->forgetInstance('current_organization');

        return $this->get('/org/'.$organization->slug);
    }

    /**
     * La destination de chaque carte, lue sur le HTML rendu.
     *
     * On repere par la CLASSE de la carte — son identifiant fonctionnel — et
     * jamais par son libelle, qui est personnalisable.
     */
    private function destination(string $html, string $classe): ?string
    {
        return preg_match('~<a href="([^"]+)"[^>]*class="ocard '.preg_quote($classe, '~').'"~', $html, $m)
            ? html_entity_decode($m[1])
            : null;
    }

    // =====================================================================
    // A. Le mapping final
    // =====================================================================

    /** « J'explore une piste » mene aux CONTENUS, pas a l'Annuaire. */
    public function test_explore_leads_to_the_blog(): void
    {
        $organisation = $this->organisation(true);
        $html = $this->landing($organisation)->assertOk()->getContent();

        $this->assertSame(
            route('organization.blog.index', $organisation),
            $this->destination($html, 'card-create'),
            'La carte « J\'explore une piste » est `card-create` — son nom ment, son role non.',
        );
    }

    /** « Je cree du lien » mene aux PERSONNES. */
    public function test_connect_leads_to_the_directory(): void
    {
        $organisation = $this->organisation(true);
        $html = $this->landing($organisation)->assertOk()->getContent();

        $this->assertSame(
            route('organization.members.index', $organisation),
            $this->destination($html, 'card-meet'),
        );
    }

    /** Les deux cartes d'entraide continuent de mener a l'Explorer. */
    public function test_help_cards_lead_to_the_explorer(): void
    {
        $organisation = $this->organisation(true);
        $html = $this->landing($organisation)->assertOk()->getContent();

        $explorer = route('organization.explorer', $organisation);

        $this->assertSame($explorer, $this->destination($html, 'card-help'));
        $this->assertSame($explorer.'?tab=requests', $this->destination($html, 'card-offer'));
    }

    /**
     * Plus aucune carte ne mene aux Boucles.
     *
     * Consequence EXPLICITEMENT acceptee par MASTER : les Boucles restent
     * atteintes par le CTA secondaire, la navigation et le logigramme. Le test
     * fige la decision pour qu'un retour en arriere soit un choix, pas un
     * accident.
     */
    public function test_no_card_leads_to_the_loops_catalogue(): void
    {
        $organisation = $this->organisation(true);
        $html = $this->landing($organisation)->assertOk()->getContent();

        foreach (['card-help', 'card-offer', 'card-meet', 'card-create'] as $classe) {
            $this->assertStringNotContainsString(
                '/boucles',
                (string) $this->destination($html, $classe),
                "La carte {$classe} mene encore au catalogue des Boucles.",
            );
        }
    }

    // =====================================================================
    // B. La portee — le cas qui etait invisible
    // =====================================================================

    /**
     * Sur une Organization NON PAR DEFAUT, les quatre cartes restent chez elle.
     *
     * C'est le test qui compte : sur `main`, une route globale rend la MEME
     * URL qu'une route bornee, et le defaut ne se voit pas.
     */
    public function test_the_four_cards_stay_within_a_non_default_organization(): void
    {
        $autre = $this->organisation(false);
        $html = $this->landing($autre)->assertOk()->getContent();

        foreach (['card-help', 'card-offer', 'card-meet', 'card-create'] as $classe) {
            $url = (string) $this->destination($html, $classe);

            $this->assertStringContainsString(
                '/org/'.$autre->slug.'/',
                $url,
                "La carte {$classe} sort de l'Organization : {$url}",
            );

            $this->assertStringNotContainsString('/org/main/', $url, "La carte {$classe} renvoie chez `main`.");
        }
    }

    /** Aucun `href` global ne subsiste dans le bloc des cartes. */
    public function test_no_global_route_remains_in_the_card_block(): void
    {
        $autre = $this->organisation(false);
        $html = $this->landing($autre)->assertOk()->getContent();

        foreach (['card-help', 'card-offer', 'card-meet', 'card-create'] as $classe) {
            $url = (string) $this->destination($html, $classe);
            $chemin = parse_url($url, PHP_URL_PATH) ?? '';

            $this->assertStringStartsWith(
                '/org/',
                $chemin,
                "La carte {$classe} vise une route globale : {$chemin}",
            );
        }
    }

    // =====================================================================
    // C. Le libelle est editorial, la destination ne l'est pas
    // =====================================================================

    /**
     * Personnaliser un libelle ne deplace pas sa carte.
     *
     * « Je decouvre des idees » reste `card-create`, et mene toujours au Blog.
     */
    public function test_a_custom_label_never_moves_the_destination(): void
    {
        $organisation = $this->organisation(true, [
            'card_create_label' => 'Je découvre des idées',
            'card_meet_label' => 'Je rencontre du monde',
        ]);

        $html = $this->landing($organisation)->assertOk()->getContent();

        $this->assertStringContainsString('Je découvre des idées', $html);

        $this->assertSame(
            route('organization.blog.index', $organisation),
            $this->destination($html, 'card-create'),
        );
        $this->assertSame(
            route('organization.members.index', $organisation),
            $this->destination($html, 'card-meet'),
        );
    }
}
