<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1707 M5 — « BouclePro » au lieu d'un retour, sur Dossiers et Agenda.
 *
 * Cyril, captures a l'appui : « Sur Dossiers et Agenda, la barre haute affiche
 * BouclePro. Un simple ‹ renvoyant vers la homepage, comme sur les autres
 * ecrans. »
 *
 * ## La cause
 *
 * Ces deux routes ne figuraient ni dans `$levelOneTitles` ni dans la chaine des
 * `$routeTitle` de `components/mobile-topbar.blade.php` : le repli terminal
 * `config('app.name')` s'appliquait, et aucune branche ne leur donnait de
 * `$backHref`. La barre n'etait donc ni un titre ni une navigation — juste le
 * nom de la plateforme.
 *
 * ## Pourquoi AUCUN titre, et pas « Documents » / « Agenda »
 *
 * Les deux pages portent deja leur propre `<h1>`, a tous les calibres. Y
 * remettre un titre dans la barre creerait le doublon que M6 demande justement
 * de supprimer sur le Blog. C'est ce que dit le « juste un ‹ ».
 *
 * Les deux moities sont donc mesurees separement : la fleche APPARAIT, et le
 * titre DISPARAIT. Aucune ne suffit.
 */
class TASK1707MobileTopbarBackTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1707-topbar',
            'is_active' => true,
            'is_public' => true,
            'loops_enabled' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    private function page(string $chemin): string
    {
        app()->forgetInstance('current_organization');

        return $this->actingAs($this->membre)
            ->get('/org/'.$this->organization->slug.$chemin)
            ->assertOk()
            ->getContent();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ecrans(): array
    {
        return [
            'Dossiers' => ['/dossiers'],
            'Agenda' => ['/agenda'],
        ];
    }

    /**
     * La fleche existe, et elle mene a l'accueil DE CETTE Organization.
     *
     * `data-bp-topbar-back` porte la DESTINATION : sans lui, une assertion ne
     * pourrait que chercher l'adresse quelque part dans la page, ou elle figure
     * aussi via le rail et la barre basse — elle resterait verte avec une
     * fleche pointant ailleurs. C'est la lecon que TASK-1693 bug AQ a deja
     * ecrite dans ce composant.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ecrans')]
    public function test_la_barre_haute_mene_a_l_accueil(string $chemin): void
    {
        $html = $this->page($chemin);

        $this->assertSame(
            1,
            preg_match('/data-bp-topbar-back="([^"]+)"/', $html, $cible),
            'La barre haute ne porte aucun retour.'
        );

        $this->assertSame(
            route('organization.home', ['organization' => $this->organization->slug]),
            $cible[1],
            'Le retour ne mene pas a l\'accueil de cette Organization.'
        );
    }

    /**
     * La barre haute du telephone, ISOLEE du reste du document.
     *
     * ## Pourquoi la region, et pas un marqueur
     *
     * Ma premiere version de la garde suivante cherchait l'absence d'un
     * `data-bp-topbar-title` que je venais de poser. Elle est restee **VERTE**
     * quand j'ai remis la version d'origine du composant : l'ancien balisage
     * ne portait pas ce marqueur, donc « 0 occurrence » etait vrai pour rien,
     * alors que la barre affichait bel et bien « BouclePro ».
     *
     * La mesure porte donc sur la REGION — le `<header md:hidden fixed top-0>`
     * de la barre — et sur la presence d'un `<h1>` DEDANS. C'est le fait que
     * Cyril a vu, et il ne depend d'aucun attribut de mon cru.
     */
    private function barreHaute(string $chemin): string
    {
        $this->assertSame(
            1,
            preg_match('/<header[^>]*class="[^"]*md:hidden fixed top-0[^"]*"[^>]*>(.*?)<\/header>/s', $this->page($chemin), $barre),
            'La barre haute du telephone n\'est pas servie : la mesure ne porterait sur rien.'
        );

        return $barre[1];
    }

    /**
     * Et « BouclePro » a disparu de la barre.
     *
     * La mesure est bornee a la barre : le nom de la plateforme figure
     * legitimement ailleurs dans la page — pied de page, balise `title`,
     * attributs d'accessibilite — et une assertion posee sur le document
     * entier serait rouge pour de mauvaises raisons.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('ecrans')]
    public function test_la_barre_haute_ne_porte_plus_aucun_titre(string $chemin): void
    {
        $barre = $this->barreHaute($chemin);

        $this->assertSame(
            0,
            preg_match('/<h1[\s>]/', $barre),
            'La barre haute porte encore un titre : « BouclePro », ou un doublon du titre de la page.'
        );

        $this->assertStringNotContainsString(
            config('app.name'),
            $barre,
            'Le nom de la plateforme s\'affiche encore dans la barre haute.'
        );
    }

    /**
     * Ailleurs, le titre de la barre est INTACT.
     *
     * Sans ce volet, vider `$displayTitle` pour toutes les routes passerait les
     * deux tests precedents — et deshabillerait toutes les barres hautes de
     * l'application. Le sabotage `$titrePorteParLaPage = true` ne rougit QUE
     * celui-la.
     */
    public function test_ailleurs_la_barre_haute_porte_toujours_son_titre(): void
    {
        $barre = $this->barreHaute('/messages');

        $this->assertSame(
            1,
            preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $barre, $titre),
            'La barre haute d\'une autre page a perdu son titre.'
        );

        $this->assertSame(e(__('navigation.messages')), trim($titre[1]));
    }
}
