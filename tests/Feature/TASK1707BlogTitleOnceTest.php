<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1707 M6 — « Blog » s'affichait deux fois sur telephone.
 *
 * Cyril : « Blog contient 2 fois le terme blog. Mettre dans la top navigation
 * bar et qui renvoie vers la homepage. »
 *
 * Deux sources, les deux legitimes prises separement : `blog.index` est une
 * route de NIVEAU UN, donc la barre haute la titre ; et la page porte son
 * propre `<h1>`. Sur telephone les deux sont visibles en meme temps.
 *
 * ## Le seuil est un CONTRAT, pas un choix de style
 *
 * `x-mobile-topbar` est `md:hidden`. Le `<h1>` de la page est donc
 * `hidden md:block` : il reparait PILE ou la barre disparait. Tout autre seuil
 * laisserait soit un trou — aucun titre entre les deux — soit le doublon qu'on
 * vient de retirer. C'est la seule raison pour laquelle ce fichier se permet
 * d'asserter des classes de calibre : elles doivent se correspondre, et un
 * test est le seul endroit ou cette correspondance peut etre ecrite.
 */
class TASK1707BlogTitleOnceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1707-blog-titre',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
        ]);
    }

    private function blog(): string
    {
        app()->forgetInstance('current_organization');

        return $this->actingAs($this->membre)
            ->get('/org/'.$this->organization->slug.'/blog')
            ->assertOk()
            ->getContent();
    }

    /**
     * @return array{0: string, 1: string} la barre haute, puis le reste de la page
     */
    private function barreEtPage(): array
    {
        $html = $this->blog();

        $this->assertSame(
            1,
            preg_match('/<header[^>]*class="([^"]*md:hidden[^"]*)"[^>]*>(.*?)<\/header>/s', $html, $barre),
            'La barre haute du telephone n\'est pas servie, ou n\'est plus `md:hidden` : le seuil du titre de page ne lui correspondrait plus.'
        );

        return [$barre[2], str_replace($barre[0], '', $html)];
    }

    /**
     * Sur telephone, un seul « Blog » : celui de la barre.
     *
     * La mesure est en deux temps parce que les deux titres existent tous les
     * deux dans le HTML — c'est leur VISIBILITE qui differe. On verifie donc
     * que la barre porte le titre, et que l'autre est explicitement retire en
     * dessous du seuil de la barre.
     */
    public function test_le_titre_de_la_barre_est_le_seul_visible_sur_telephone(): void
    {
        [$barre, $page] = $this->barreEtPage();

        $this->assertSame(
            1,
            preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $barre, $titreBarre),
            'La barre haute ne titre plus le Blog.'
        );
        $this->assertSame(e(__('blog.title')), trim($titreBarre[1]));

        $this->assertSame(
            1,
            preg_match('/<h1([^>]*)>\s*'.preg_quote(e(__('blog.title')), '/').'\s*<\/h1>/s', $page, $titrePage),
            'Le titre propre de la page a disparu : il n\'y en aurait plus aucun sur desktop.'
        );

        $classes = $titrePage[1];
        $this->assertStringContainsString('hidden', $classes, 'Le titre de la page s\'affiche encore sous la barre : « Blog » apparait deux fois.');
        $this->assertStringContainsString('md:block', $classes, 'Le titre de la page ne reparait pas au calibre ou la barre disparait : plus aucun titre sur desktop.');
    }

    /**
     * Et la barre mene a l'accueil de l'Organization.
     *
     * `data-bp-topbar-back` porte la DESTINATION : l'adresse de l'accueil
     * figure aussi dans le rail et la barre basse, donc une assertion qui la
     * chercherait dans la page resterait verte avec une fleche pointant
     * ailleurs.
     */
    public function test_la_barre_haute_du_blog_mene_a_l_accueil(): void
    {
        [$barre] = $this->barreEtPage();

        $this->assertSame(
            1,
            preg_match('/data-bp-topbar-back="([^"]+)"/', $barre, $cible),
            'La barre haute du Blog ne porte aucun retour.'
        );

        $this->assertSame(
            route('organization.home', ['organization' => $this->organization->slug]),
            $cible[1],
            'Le retour du Blog ne mene pas a l\'accueil de cette Organization.'
        );
    }
}
