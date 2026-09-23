<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1626 — une seule grammaire d'icones entre le rail et la barre.
 *
 * LE DEFAUT N'ETAIT PAS TROIS ICONES, C'ETAIT L'ABSENCE DE SOURCE.
 *
 * Le rail desktop et la barre mobile portaient chacun sa propre table de
 * traces SVG, recopies a la main. Deux tables independantes pour une meme
 * grammaire : la divergence n'etait pas un accident, c'etait l'etat par
 * defaut. Elle avait produit trois ecarts — Boucles, Flux, Annuaire — dont
 * personne n'avait decide.
 *
 * Corriger les trois traces n'aurait rien ferme : le quatrieme serait arrive.
 * Ce banc epingle donc la SOURCE, pas les dessins — sauf la ou le produit a
 * tranche sur un signe precis.
 */
class TASK1626NavigationIconsTest extends TestCase
{
    use RefreshDatabase;

    /** Les destinations rendues par les DEUX surfaces. */
    private const PARTAGEES = ['loops', 'feed', 'agenda', 'exchanges', 'messaging', 'directory', 'blog', 'my_dossiers'];

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        // `feed_post_publish_mode = members` ouvre « Flux » a tout membre de
        // l'Organization. Sans cela, l'onglet Flux n'est PAS rendu sur mobile
        // (garde `$canSeeFlux`) et la mesure de divergence serait aveugle sur
        // l'une des trois entrees que cette TASK harmonise.
        $this->organization = Organization::factory()->create([
            'name' => 'T1626',
            'is_active' => true,
            'feed_post_publish_mode' => 'members',
        ]);
        $this->organization->update(['is_default' => true]);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    // ── 1. TEMOIN D'INSTRUMENT ──────────────────────────────────────────────

    public function test_les_deux_surfaces_sont_bien_rendues(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('<aside', $page, 'Le rail desktop n\'est pas rendu.');
        $this->assertStringContainsString('data-bp-mobile-nav', $page, 'La barre mobile n\'est pas rendue.');
    }

    // ── 2. LA MESURE CENTRALE ───────────────────────────────────────────────

    public function test_chaque_destination_porte_le_MEME_trace_des_deux_cotes(): void
    {
        $page = $this->page();
        $rail = $this->rail($page);
        $barre = $this->barre($page);

        foreach (self::PARTAGEES as $cle) {
            $trace = config('navigation_icons.'.$cle);

            $this->assertNotEmpty($trace, "Aucun trace pour {$cle}.");
            $this->assertStringContainsString($trace, $rail, "Le rail ne porte pas le trace commun de {$cle}.");
            $this->assertStringContainsString($trace, $barre, "La barre mobile ne porte pas le trace commun de {$cle}.");
        }
    }

    public function test_la_cloche_des_notifications_vient_aussi_de_la_source(): void
    {
        // Elle vit dans le rail ET dans le header mobile depuis TASK-1625 :
        // deux endroits, donc deux occasions de diverger.
        $page = $this->page();
        $cloche = config('navigation_icons.notifications');

        $this->assertStringContainsString($cloche, $this->rail($page));
        $this->assertStringContainsString($cloche, $this->header($page));
    }

    // ── 3. LA GARDE DE NON-RETOUR ───────────────────────────────────────────
    //
    // Sans elle, rien n'empecherait de recoller un trace en dur dans une vue,
    // et la divergence reviendrait par ou elle etait venue.

    public function test_aucune_vue_de_navigation_ne_recolle_un_trace_en_dur(): void
    {
        foreach ([
            'resources/views/components/app-side-nav.blade.php',
            'resources/views/components/mobile-bottom-nav.blade.php',
        ] as $vue) {
            $source = file_get_contents(base_path($vue));

            preg_match_all("/'icon'\s*=>\s*'([^']+)'/", $source, $durs);

            foreach ($durs[1] as $trace) {
                $this->assertNotContains(
                    $trace,
                    array_values(config('navigation_icons')),
                    "Le trace d'une destination de navigation est recopie en dur dans {$vue}.",
                );
            }
        }
    }

    public function test_la_source_ne_melange_pas_deux_epaisseurs_de_trait(): void
    {
        // La grammaire commune suppose un trait unique. TASK-1625 avait du
        // affiner celui de Boucles a 1.1 pour sauver la rosette ; l'abandonner
        // a rendu cette exception inutile. Qu'elle ne revienne pas en douce :
        // un trace qui demande sa propre epaisseur n'appartient pas a cette
        // barre, il n'est simplement pas lisible a la taille voulue.
        $barre = file_get_contents(base_path('resources/views/components/mobile-bottom-nav.blade.php'));

        $this->assertStringContainsString('stroke-width="1.8"', $barre);
        $this->assertStringNotContainsString("'stroke'", $barre);
    }

    // ── 4. CE QUE LE PRODUIT A TRANCHE ──────────────────────────────────────

    public function test_annuaire_montre_des_personnes_et_non_un_diagramme(): void
    {
        // Les deux cotes montraient un diagramme a BARRES — et pas meme le
        // meme. Des barres ne disent ni membre, ni contact, ni repertoire.
        $trace = config('navigation_icons.directory');

        $this->assertStringNotContainsString('M9 19v-6a2 2 0 00-2-2H5', $trace, 'Annuaire montre encore des barres.');

        // Le trace retenu est celui que le depot utilise deja pour
        // « Membres » : rien n'est invente, rien n'est ajoute.
        $registre = file_get_contents(base_path('resources/views/components/loops/card-icon.blade.php'));
        $this->assertStringContainsString($trace, $registre, 'Annuaire n\'utilise pas le trace « users » du depot.');
    }

    public function test_flux_ne_montre_plus_le_signe_de_dossiers(): void
    {
        // La barre mobile montrait un DOSSIER pour « Flux » : le meme signe
        // servait donc deux destinations differentes.
        $this->assertNotSame(
            config('navigation_icons.my_dossiers'),
            config('navigation_icons.feed'),
            'Flux et Dossiers partagent leur signe.',
        );
    }

    public function test_aucune_destination_ne_partage_son_signe_avec_une_autre(): void
    {
        // La regle generale derriere les deux tests precedents. Une icone qui
        // vaut pour deux destinations n'aide personne.
        $traces = config('navigation_icons');

        $this->assertSame(
            count($traces),
            count(array_unique($traces)),
            'Deux destinations portent le meme trace : '.implode(', ', array_keys(array_diff_key($traces, array_unique($traces)))),
        );
    }

    // ── Fixtures et mesures ─────────────────────────────────────────────────

    private function page(): string
    {
        return $this->actingAs($this->membre)->get(route('dashboard'))->assertOk()->getContent();
    }

    private function extraire(string $html, string $ouvrant, string $fermant, string $quoi): string
    {
        $debut = strpos($html, $ouvrant);
        $this->assertNotFalse($debut, "{$quoi} n'est pas rendu.");

        $fin = strpos($html, $fermant, $debut);
        $this->assertNotFalse($fin);

        return substr($html, $debut, $fin - $debut);
    }

    private function rail(string $html): string
    {
        return $this->extraire($html, '<aside', '</aside>', 'Le rail desktop');
    }

    private function barre(string $html): string
    {
        return $this->extraire($html, '<nav data-bp-mobile-nav', '</nav>', 'La barre mobile');
    }

    private function header(string $html): string
    {
        return $this->extraire($html, '<header x-data="{ menuOuvert: false }"', '</header>', 'Le header mobile');
    }
}
