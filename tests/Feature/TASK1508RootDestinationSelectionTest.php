<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Homepage\RootDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1508 — sur `/admin/homepage`, cliquer une carte ne se voyait PAS.
 *
 * Cyril : « on ne peut pas choisir le type de page d'accueil, le selecteur ne
 * fonctionne pas ». Mesure : la radio se cochait bien et le formulaire
 * enregistrait — mais l'etat selectionne etait calcule cote SERVEUR seulement.
 * Rien ne reagissait au clic ; la seule bordure qui bougeait venait du SURVOL,
 * et le badge restait sur l'ancienne carte. Un selecteur qui ne montre pas ce
 * qu'on a choisi est casse, meme si la donnee part.
 *
 * Ce que ces tests gardent :
 *  - l'etat visuel de selection est INCONDITIONNEL dans le rendu (il ne depend
 *    plus de la valeur enregistree) ;
 *  - `peer-checked:` n'est pas utilise : il compile en `~`, combinateur de
 *    FRERES, et n'atteint pas les descendants d'un frere — le piege exact ;
 *  - les deux etats restent DISTINCTS : « selectionne » suit le clic,
 *    « actuellement servi » dit ce que la racine sert vraiment ;
 *  - les variantes existent dans le BUNDLE : une classe Tailwind non generee
 *    est un no-op silencieux.
 */
class TASK1508RootDestinationSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        Organization::factory()->create([
            'slug' => 'main', 'is_active' => true, 'is_public' => true, 'is_default' => true,
            'homepage_template' => 'default', 'root_destination' => RootDestination::HOMEPAGE,
        ]);

        $platform = Organization::factory()->create(['slug' => 'plateforme-1508']);
        $admin = User::factory()->create(['is_admin' => true, 'organization_id' => $platform->id]);

        return $this->actingAs($admin)->get(route('admin.homepage'))->assertOk()->getContent();
    }

    public function test_every_card_carries_the_selected_state_whatever_is_stored(): void
    {
        $html = $this->page();

        foreach (RootDestination::MODES as $mode) {
            $this->assertSame(1, preg_match('/<label([^>]*)data-root-destination-option="'.$mode.'"/s', $html, $m), "carte {$mode} introuvable");
            $class = $m[1];

            // Inconditionnel : la carte reagit a SA radio, pas a la valeur enregistree.
            $this->assertStringContainsString('has-[:checked]:border-indigo-600', $class, "carte {$mode} : la selection ne se verrait pas");
            $this->assertStringContainsString('has-[:checked]:bg-indigo-50', $class, "carte {$mode} : la selection ne se verrait pas");
            $this->assertStringContainsString('group', $class, "carte {$mode} : les descendants ne pourraient pas reagir");
        }
    }

    public function test_peer_checked_is_never_used_because_it_cannot_reach_a_nested_element(): void
    {
        $html = $this->page();

        $this->assertSame(1, preg_match('/<form[^>]*data-root-destination-form.*?<\/form>/s', $html, $m), 'formulaire introuvable');
        $this->assertStringNotContainsString('peer-checked:', $m[0], 'peer-checked compile en « ~ » : il n atteint pas les descendants d un frere');
        $this->assertStringContainsString('group-has-[:checked]:', $m[0]);
    }

    public function test_selected_and_currently_served_stay_two_different_things(): void
    {
        $html = $this->page();

        // « Selectionne » existe sur CHAQUE carte, masque tant que la radio ne l'est pas.
        $this->assertSame(count(RootDestination::MODES), substr_count($html, 'data-root-destination-selected'));

        // « Actuellement servi » n'existe que sur celle que la racine sert.
        $this->assertSame(1, substr_count($html, 'data-root-destination-current'));
        $this->assertSame(1, preg_match('/data-root-destination-option="homepage".*?data-root-destination-current/s', $html), 'le badge « servi » doit etre sur la modalite enregistree');

        $this->assertNotSame(__('admin.root_destination_selected'), __('admin.root_destination_current'), 'deux etats differents, deux libelles differents');
    }

    /**
     * Une variante Tailwind absente du build est un no-op SILENCIEUX : la
     * classe est dans le HTML, la regle n'existe pas, et rien ne change a
     * l'ecran — exactement le defaut d'origine.
     */
    public function test_the_build_really_carries_the_variants(): void
    {
        $bundles = glob(public_path('build/assets/app-*.css'));

        if ($bundles === [] || $bundles === false) {
            $this->markTestSkipped('bundle absent (build non produit dans cet environnement)');
        }

        // Le build produit PLUSIEURS `app-*.css`, parfois a la meme seconde :
        // prendre « le plus recent » en designe un au hasard, et souvent le
        // mauvais. On lit l'ensemble — la regle doit exister quelque part.
        $css = implode("\n", array_map(fn (string $f) => (string) file_get_contents($f), $bundles));

        foreach ([
            '.has-\[\:checked\]\:border-indigo-600',
            '.has-\[\:checked\]\:bg-indigo-50',
            '.group-has-\[\:checked\]\:text-indigo-600',
            '.group-has-\[\:checked\]\:inline-flex',
        ] as $selector) {
            $this->assertStringContainsString($selector, $css, "variante {$selector} absente du bundle : elle serait un no-op");
        }
    }

    public function test_both_locales_name_the_two_states(): void
    {
        $fr = require lang_path('fr/admin.php');
        $en = require lang_path('en/admin.php');

        foreach (['root_destination_selected', 'root_destination_current'] as $key) {
            $this->assertArrayHasKey($key, $fr, "cle {$key} absente du francais");
            $this->assertArrayHasKey($key, $en, "cle {$key} absente de l anglais");
            $this->assertNotSame('', trim((string) $fr[$key]));
        }

        $this->assertStringContainsString('é', (string) $fr['root_destination_selected'], 'accent manquant : « Sélectionné »');
    }
}
