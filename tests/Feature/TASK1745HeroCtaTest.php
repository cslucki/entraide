<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1745 (demande Cyril, 03/11) — le bloc CTA de la homepage de Main.
 *
 * Trois changements qui tiennent ensemble :
 *
 * 1. le bouton rose « Connexion », à droite de « Rejoindre la boucle »,
 *    disparaît — la barre du haut en porte déjà un, visible sur bureau
 *    comme sur téléphone depuis T1743 ;
 * 2. « À propos » descend dans le bloc CTA, à la taille de « Voir une
 *    démo » et en rose ;
 * 3. l'entrée « À propos » de la barre est masquée sur TÉLÉPHONE seulement.
 *
 * Le point 3 est une décision de FEUILLE DE STYLE : le lien reste dans le
 * HTML, parce que le bureau s'en sert et que le retirer côté serveur
 * obligerait à deviner la taille de l'écran depuis le User-Agent — ce que
 * T1737 a déjà payé. Un test PHP ne peut donc pas le mesurer en lisant la
 * page : il mesure la RÈGLE, et la recette sur appareil fait le reste.
 */
class TASK1745HeroCtaTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 't1745-hero',
            'is_active' => true,
            'is_public' => true,
            'homepage_template' => 'bouclepro_hero_v2',
        ]);
    }

    public function test_le_bouton_rose_connexion_du_bloc_CTA_a_disparu(): void
    {
        // Un visiteur anonyme : c'est le seul cas où ce bouton existait.
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'class="cta-secondary"',
            $html,
            'le second bouton de connexion ne doit plus être rendu',
        );
    }

    public function test_la_porte_de_connexion_reste_ouverte_dans_la_BARRE(): void
    {
        // La garde qui compte : retirer un doublon ne doit pas fermer la
        // porte. Un visiteur anonyme doit toujours pouvoir se connecter.
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        $this->assertStringContainsString(
            'href="'.route('organization.login', $this->org).'"',
            $html,
            'la connexion doit rester atteignable depuis la barre',
        );
    }

    public function test_a_propos_est_un_bouton_du_bloc_CTA(): void
    {
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        // Même gabarit que « Voir une démo » (`btn-join`), plus le
        // modificateur de couleur. C'est ce qui garantit « la même taille ».
        $this->assertMatchesRegularExpression(
            '/<a[^>]*class="btn-join btn-join--about"[^>]*data-hero-about/s',
            $html,
        );
        $this->assertStringContainsString('data-hero-kit-demo', $html, '« Voir une démo » reste');
    }

    public function test_le_bouton_a_propos_a_la_TAILLE_de_voir_une_demo(): void
    {
        // « De même taille » n'est pas une intention : c'est une propriété
        // de la feuille de style. Le modificateur ne doit toucher QUE la
        // couleur — s'il redéclarait `padding` ou `font-size`, les deux
        // boutons divergeraient au premier ajustement de l'original.
        $css = file_get_contents(public_path('css/bouclepro-hero.css'));

        preg_match('/\.bp-hero-v2 \.btn-join--about\{([^}]*)\}/', $css, $trouve);

        $this->assertNotEmpty($trouve, 'la règle du modificateur doit exister');

        foreach (['padding', 'font-size', 'border-radius', 'font-weight'] as $geometrie) {
            $this->assertStringNotContainsString(
                $geometrie,
                $trouve[1],
                "le modificateur redéclare [{$geometrie}] : la taille cesserait de suivre `.btn-join`",
            );
        }
    }

    public function test_a_propos_a_QUITTE_la_barre_sur_les_deux_tailles_d_ecran(): void
    {
        // Consigne corrigée le 03/11 : d'abord « sur téléphone uniquement »,
        // puis sur les deux. La première version masquait par media query ;
        // le lien part maintenant du balisage.
        //
        // Même raison que pour le bouton « Connexion » retiré du bloc CTA :
        // « À propos » y est désormais un bouton rose bien visible, et le
        // garder aussi dans la barre fait hésiter plutôt que d'aider.
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('data-nav-about', $html);

        // La règle CSS qui le masquait sur téléphone part avec lui : une
        // règle qui ne vise plus rien devient une fausse piste pour qui lira
        // cette feuille.
        $css = file_get_contents(public_path('css/bouclepro-hero.css'));

        $this->assertStringNotContainsString('.nav-about', $css);
    }

    public function test_la_porte_vers_A_PROPOS_reste_OUVERTE(): void
    {
        // La garde qui compte, et la symétrique de celle sur la connexion :
        // retirer une entrée de barre ne doit pas fermer la destination.
        // Elle vit maintenant dans le bloc CTA, et nulle part ailleurs.
        $html = $this->get('/org/'.$this->org->slug)->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('about').'"', $html);
        $this->assertStringContainsString(__('navigation.about'), $html);
    }
}
