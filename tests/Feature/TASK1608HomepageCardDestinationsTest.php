<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1608, addendum MASTER — les quatre cartes d'accueil, sur TOUS les
 * gabarits scopes.
 *
 * ## Pourquoi ce fichier a du etre elargi
 *
 * La premiere version ne mesurait que `hero-v2`. Or `hero-v2` ne sert QUE
 * `main`, et le correctif y etait vert pendant que `audit-1014-alpha`
 * continuait d'envoyer « Je suis actuellement fascine par… » vers l'Annuaire.
 * Une garde qui ne couvre qu'un gabarit sur trois laisse passer la regression
 * qu'elle pretend interdire.
 *
 * `OrganizationLandingController` arbitre entre TROIS gabarits : `hero-v2`,
 * `artscilab-hero`, et le repli `home`. Les deux premiers portent les quatre
 * memes intentions sous des noms de classe differents (`card-*` / `c1..c4`) —
 * c'est ce depaysement de nommage qui m'avait fait conclure a tort que le
 * second « n'etait pas concerne ». On lit donc l'INTENTION, jamais le nom.
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

    /**
     * L'intention de chaque carte, et la classe qui la porte sur chaque gabarit.
     *
     * Les deux gabarits racontent les memes quatre intentions ; seuls les noms
     * de classe different. La table ci-dessous est la traduction, etablie sur
     * le LIBELLE par defaut ET sur l'icone — deux temoins concordants, aucun
     * des deux devine.
     *
     * | intention | `hero-v2` | `artscilab-hero` (icone) |
     * |---|---|---|
     * | demander de l'aide | `card-help` | `c2` (`ti-lifebuoy`) |
     * | offrir de l'aide | `card-offer` | `c1` (`ti-hand-stop`) |
     * | explorer une piste | `card-create` | `c3` (`ti-bulb`) |
     * | creer du lien | `card-meet` | `c4` (`ti-friends`) |
     */
    private const CLASSES = [
        'bouclepro_hero_v2' => [
            'need_help' => 'card-help',
            'offer_help' => 'card-offer',
            'explore_idea' => 'card-create',
            'connect' => 'card-meet',
        ],
        'artscilab_hero' => [
            'need_help' => 'c2',
            'offer_help' => 'c1',
            'explore_idea' => 'c3',
            'connect' => 'c4',
        ],
    ];

    private function organisation(bool $defaut, array $homepage = [], string $gabarit = 'bouclepro_hero_v2'): Organization
    {
        return Organization::factory()->create([
            'slug' => ($defaut ? 'main-' : 'autre-').bin2hex(random_bytes(4)),
            'is_active' => true,
            'is_public' => true,
            'is_default' => $defaut,
            'loops_enabled' => true,
            'homepage_template' => $gabarit,
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

    // =====================================================================
    // D. Le gabarit que la premiere version de ce fichier ne voyait pas
    // =====================================================================

    /**
     * La destination attendue de chaque intention, sur N'IMPORTE quel gabarit.
     *
     * @return array<string, string>
     */
    private function attendues(Organization $organisation): array
    {
        $explorer = route('organization.explorer', $organisation);

        return [
            'need_help' => $explorer,
            'offer_help' => $explorer.'?tab=requests',
            'explore_idea' => route('organization.blog.index', $organisation),
            'connect' => route('organization.members.index', $organisation),
        ];
    }

    /**
     * Le meme mapping sur les DEUX gabarits qui portent les quatre cartes.
     *
     * C'est la garde qui manquait : sans elle, corriger `hero-v2` rendait la
     * suite verte alors que toutes les autres Organizations scopees — celles
     * qui n'utilisent pas ce gabarit — gardaient l'inversion.
     */
    public function test_the_mapping_holds_on_every_scoped_template(): void
    {
        foreach (self::CLASSES as $gabarit => $classes) {
            $organisation = $this->organisation(false, [], $gabarit);
            $html = $this->landing($organisation)->assertOk()->getContent();
            $attendues = $this->attendues($organisation);

            foreach ($classes as $intention => $classe) {
                $this->assertSame(
                    $attendues[$intention],
                    $this->destination($html, $classe),
                    "Gabarit {$gabarit} : l'intention « {$intention} » (carte `{$classe}`) ne mene pas ou elle devrait.",
                );
            }
        }
    }

    /**
     * Sur chaque gabarit, les quatre cartes restent dans l'Organization.
     */
    public function test_every_scoped_template_keeps_its_cards_at_home(): void
    {
        foreach (self::CLASSES as $gabarit => $classes) {
            $organisation = $this->organisation(false, [], $gabarit);
            $html = $this->landing($organisation)->assertOk()->getContent();

            foreach ($classes as $classe) {
                $url = (string) $this->destination($html, $classe);

                $this->assertStringContainsString(
                    '/org/'.$organisation->slug.'/',
                    $url,
                    "Gabarit {$gabarit} : la carte `{$classe}` sort de l'Organization — {$url}",
                );
                $this->assertStringNotContainsString(
                    '/boucles',
                    $url,
                    "Gabarit {$gabarit} : la carte `{$classe}` mene encore au catalogue global.",
                );
            }
        }
    }

    /**
     * Le gabarit de REPLI ne sort pas non plus de l'Organization.
     *
     * `organization/home` ne porte pas les quatre intentions, mais son CTA
     * invite « Decouvrir les Boucles » visait la route GLOBALE `boucles.index`
     * depuis une page servie sous `/org/{slug}`.
     */
    public function test_the_fallback_template_has_no_global_route(): void
    {
        $organisation = $this->organisation(false, [], 'bouclepro_default');
        $html = $this->landing($organisation)->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'href="'.route('boucles.index').'"',
            $html,
            'Le gabarit de repli renvoie encore vers le catalogue global des Boucles.',
        );

        $this->assertStringContainsString(
            'href="'.route('organization.loops.index', $organisation).'"',
            $html,
            'Le CTA « Decouvrir les Boucles » a disparu au lieu d\'etre borne.',
        );
    }
}
