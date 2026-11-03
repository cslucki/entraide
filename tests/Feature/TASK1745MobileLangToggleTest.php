<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1745 (recette Cyril, 03/11) — la barre de Main sur téléphone.
 *
 * Deux pastilles FR / EN mangeaient un tiers de la barre pour un choix
 * **binaire**. Avec deux langues, un sélecteur n'a pas lieu d'être : il n'y
 * a qu'une destination possible. Le téléphone reçoit donc une **bascule**.
 *
 * ## Le choix d'afficher la langue COURANTE
 *
 * Et non la cible. L'état se lit sans réfléchir, et l'icône de langue dit
 * que c'est un bouton. Un simple « EN » aurait obligé à deviner s'il décrit
 * ou s'il agit — l'ambiguïté classique de ce composant.
 *
 * L'`aria-label`, lui, est écrit DANS la langue vers laquelle il mène :
 * c'est la seule forme qu'une personne qui ne lit pas la langue courante
 * peut comprendre.
 *
 * ## Les deux sont rendues, la feuille de style choisit
 *
 * Deviner la taille de l'écran depuis le User-Agent est une erreur que
 * T1737 a déjà payée. Un test PHP ne peut donc pas lire ce qui est visible :
 * il mesure la RÈGLE, et la recette sur appareil fait le reste.
 */
class TASK1745MobileLangToggleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 't1745-lang',
            'is_active' => true,
            'is_public' => true,
            'homepage_template' => 'bouclepro_hero_v2',
            'multilingual_enabled' => true,
        ]);
    }

    public function test_la_bascule_mene_a_l_AUTRE_langue(): void
    {
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        // L'application est en `fr` par défaut dans le banc : la bascule doit
        // donc viser `en`, et surtout PAS la langue courante — un bouton qui
        // reposte la langue déjà active ne fait rien, visiblement.
        $this->assertStringContainsString(
            'action="'.route('locale.switch', ['locale' => 'en']).'"',
            $html,
        );
        $this->assertStringContainsString('data-lang-toggle', $html);
    }

    public function test_la_bascule_AFFICHE_la_langue_courante(): void
    {
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-lang-toggle.*?<span>FR<\/span>/s',
            $html,
            "la pastille doit porter la langue COURANTE, pas la cible",
        );
    }

    public function test_l_intitule_est_ecrit_dans_la_langue_VISEE(): void
    {
        // Une personne qui ne lit pas le français doit pouvoir comprendre le
        // bouton qui l'emmène en anglais. « Switch to English » le permet ;
        // « Passer en anglais » ne le permet pas.
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        $this->assertStringContainsString('aria-label="Switch to English"', $html);
    }

    public function test_les_DEUX_formes_sont_rendues_et_la_CSS_choisit(): void
    {
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        $this->assertStringContainsString('class="lang"', $html, 'le couple du bureau');
        $this->assertStringContainsString('data-lang-toggle', $html, 'la bascule du téléphone');

        $css = file_get_contents(public_path('css/bouclepro-hero.css'));

        // Hors du point de rupture : la bascule est masquée, le couple non.
        $this->assertStringContainsString('.bp-hero-v2 .lang-toggle{display:none}', $css);

        // Sous 760 px : l'inverse, et « Connexion » maigrit.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width:760px\)\{.*?\.bp-hero-v2 \.nav-menu \.lang\{display:none\}.*?\.bp-hero-v2 \.lang-toggle\{display:inline-flex\}.*?\.bp-hero-v2 \.btn-join\{padding:[^}]*\}/s',
            $css,
            'les trois règles téléphone doivent vivre SOUS le point de rupture',
        );
    }

    public function test_la_cible_tactile_reste_confortable(): void
    {
        // Mesure du 03/11 : la pastille faisait 27 px de haut contre 35 pour
        // « Connexion » juste à côté — trop petite pour un pouce, et
        // désalignée. Une hauteur minimale est déclarée, et c'est elle qui
        // tient la promesse, pas un `padding` qui suivrait la police.
        $css = file_get_contents(public_path('css/bouclepro-hero.css'));

        $this->assertMatchesRegularExpression(
            '/\.bp-hero-v2 \.lang-toggle button\{[^}]*min-height:3[5-9]px/s',
            $css,
        );
    }

    public function test_une_organization_MONOLINGUE_n_affiche_ni_l_un_ni_l_autre(): void
    {
        // T1725 : le sélecteur suit le drapeau « site multilingue ». La
        // bascule hérite de la même règle — elle ne doit pas rouvrir une
        // porte que le réglage ferme.
        $mono = Organization::factory()->create([
            'slug' => 't1745-mono',
            'is_active' => true,
            'is_public' => true,
            'homepage_template' => 'bouclepro_hero_v2',
            'multilingual_enabled' => false,
        ]);

        $html = $this->get('/org/'.$mono->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('data-lang-toggle', $html);
        $this->assertStringNotContainsString('class="lang"', $html);
    }
}
