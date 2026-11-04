<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1749 — les portes de connexion heritent du fond de LEUR vitrine.
 *
 * ## Ce que ce test achete, et pourquoi il existe
 *
 * `public/css/bp-door-backgrounds.css` RECOPIE la declaration `background` de
 * chacune des trois feuilles de vitrine. Charger la feuille de la vitrine sur
 * la porte n'etait pas possible — elles habillent une page entiere et
 * demoliraient la carte du formulaire —, donc seul le fond est repris.
 *
 * Une copie finit toujours par diverger de son original. Le jour ou quelqu'un
 * retouche le degrade d'une vitrine, la porte garderait l'ancien SANS QUE RIEN
 * NE LE DISE : les deux pages resteraient belles, simplement plus assorties.
 * C'est exactement le genre de defaut qu'aucune suite ne rattrape.
 *
 * Ce test lit les DEUX fichiers et compare les valeurs reellement ecrites. Il
 * rougit a la premiere divergence.
 */
class TASK1749DoorBackgroundTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les trois gabarits, et le marqueur qui permet de retrouver la regle
     * gouvernante dans chaque feuille de vitrine.
     *
     * Pour `ofsh_hero`, `body` est declare DEUX FOIS dans la meme feuille : la
     * seconde ecrase la premiere. C'est pourquoi on retient la DERNIERE
     * occurrence et jamais la premiere — la surcharge gouverne.
     */
    private const VITRINES = [
        'bouclepro_hero_v2' => ['fichier' => 'bouclepro-hero.css', 'selecteur' => 'body.bp-hero-v2'],
        'ofsh_hero' => ['fichier' => 'ofsh-hero.css', 'selecteur' => 'body'],
        'artscilab_hero' => ['fichier' => 'artscilab-hero.css', 'selecteur' => 'body.bp-artscilab'],
    ];

    public function test_chaque_porte_declare_le_gabarit_de_sa_vitrine(): void
    {
        $organization = Organization::factory()->create([
            'slug' => 'porte-1749',
            'is_active' => true,
            'is_public' => true,
            'homepage_template' => 'ofsh_hero',
        ]);

        $html = $this->get(route('organization.login', $organization))->assertOk()->getContent();

        $this->assertStringContainsString('data-bp-door="ofsh_hero"', $html,
            "La porte doit annoncer le gabarit de sa vitrine, sinon la feuille des portes ne peut pas l'habiller.");
    }

    /**
     * `/login` n'est la porte de personne : elle ne doit pas emprunter le fond
     * d'une Organization qu'on aurait devinee.
     */
    public function test_la_porte_globale_prend_la_vitrine_par_defaut(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('data-bp-door="bouclepro_hero_v2"', $html);
    }

    /**
     * LE test de non-derive : chaque couleur ecrite dans la feuille des portes
     * est-elle encore celle de la vitrine ?
     */
    public function test_les_fonds_des_portes_sont_ceux_des_vitrines(): void
    {
        $portes = file_get_contents(public_path('css/bp-door-backgrounds.css'));

        foreach (self::VITRINES as $gabarit => $source) {
            $blocPorte = $this->bloc($portes, 'body[data-bp-door="'.$gabarit.'"]');
            $this->assertNotNull($blocPorte, "Aucun bloc de fond pour le gabarit « {$gabarit} ».");

            $vitrine = file_get_contents(public_path('css/'.$source['fichier']));
            $blocVitrine = $this->bloc($vitrine, $source['selecteur'], derniere: true);
            $this->assertNotNull($blocVitrine,
                "La regle « {$source['selecteur']} » est introuvable dans {$source['fichier']}.");

            // Les variables CSS de la vitrine sont resolues dans la feuille des
            // portes (elle n'importe pas le reste de la palette) : on compare
            // donc les COULEURS, qui sont ce que l'oeil voit, et pas le texte.
            $couleursPorte = $this->couleurs($blocPorte);
            $couleursVitrine = $this->couleurs($this->resoudreVariables($blocVitrine, $vitrine));

            $this->assertSame(
                $couleursVitrine,
                $couleursPorte,
                "Le fond de la porte « {$gabarit} » a DIVERGE de sa vitrine ({$source['fichier']}). ".
                'Reporter la nouvelle valeur dans `public/css/bp-door-backgrounds.css`.'
            );
        }
    }

    /**
     * La « ring » de BouclePro n'est pas une couleur mais un FICHIER : la
     * derive possible n'est pas une teinte qui change, c'est l'image qu'on
     * renomme ou qu'on deplace.
     *
     * Le test verifie donc deux choses : que la porte et la vitrine designent
     * le MEME fichier, et que ce fichier existe. Un `url()` qui ne resout pas
     * ne leve aucune erreur — le halo disparaitrait en silence.
     */
    public function test_la_ring_de_bouclepro_est_celle_de_sa_vitrine(): void
    {
        $portes = file_get_contents(public_path('css/bp-door-backgrounds.css'));
        $gabarit = file_get_contents(resource_path('views/organization/hero-v2.blade.php'));

        $blocPorte = $this->bloc($portes, 'body[data-bp-door="bouclepro_hero_v2"]::before');
        $this->assertNotNull($blocPorte, 'La ring a disparu de la feuille des portes.');

        $this->assertTrue(
            (bool) preg_match("#url\(['\"]?([^'\")]+)['\"]?\)#", $blocPorte, $cote),
            'La ring de la porte ne reference aucune image.'
        );

        $this->assertTrue(
            (bool) preg_match('#rings-img[^>]*src="[^"]*?([a-z0-9/_-]+\.svg)#i', $gabarit, $vitrine),
            '`rings-img` est introuvable dans `hero-v2.blade.php`.'
        );

        $this->assertStringEndsWith(
            $vitrine[1],
            $cote[1],
            'La ring de la porte ne designe plus le meme fichier que la vitrine.'
        );

        $this->assertFileExists(
            public_path(ltrim($cote[1], '/')),
            "L'image de la ring est introuvable : un `url()` qui ne resout pas ne leve aucune erreur, le halo disparaitrait en silence."
        );
    }

    /**
     * La bande arc-en-ciel d'OFSH suit la meme regle que les fonds : elle est
     * COPIEE de la vitrine, donc elle peut deriver comme eux.
     *
     * Elle a son assertion propre parce qu'elle vit dans un pseudo-element, que
     * la comparaison des fonds ci-dessus ne regarde pas.
     */
    public function test_la_bande_ofsh_est_celle_de_sa_vitrine(): void
    {
        $portes = file_get_contents(public_path('css/bp-door-backgrounds.css'));
        $vitrine = file_get_contents(public_path('css/ofsh-hero.css'));

        $blocPorte = $this->bloc($portes, 'body[data-bp-door="ofsh_hero"]::before');
        $blocVitrine = $this->bloc($vitrine, 'body::before');

        $this->assertNotNull($blocPorte, 'La bande du haut a disparu de la feuille des portes.');
        $this->assertNotNull($blocVitrine, '`body::before` est introuvable dans ofsh-hero.css.');

        $this->assertSame(
            $this->couleurs($blocVitrine),
            $this->couleurs($blocPorte),
            'La bande du haut de la porte OFSH a DIVERGE de sa vitrine (ofsh-hero.css).'
        );
    }

    /**
     * Le corps d'une regle CSS declaree AU PREMIER NIVEAU, hors de tout
     * `@media`.
     *
     * La premiere version prenait simplement la derniere occurrence du
     * selecteur. Elle a rougi, et pour une bonne raison : `ofsh-hero.css`
     * declare `body` QUATRE fois — la regle gouvernante (ligne 166), une
     * variante mobile sous `@media(max-width:720px)`, et une variante sombre
     * sous `@media (prefers-color-scheme: dark)`. « La derniere » ramassait la
     * SOMBRE, et le test comparait un fond clair a un fond de nuit.
     *
     * On compte donc les accolades ouvertes avant chaque occurrence : a
     * profondeur 0, la regle est au premier niveau. Entre deux regles de meme
     * niveau, la derniere gouverne — c'est la surcharge, et `ofsh-hero.css` en
     * fait justement usage entre ses lignes 59 et 166.
     */
    private function bloc(string $css, string $selecteur, bool $derniere = false): ?string
    {
        $motif = '/(?<![\w.#-])'.preg_quote($selecteur, '/').'\s*\{([^}]*)\}/';

        if (! preg_match_all($motif, $css, $trouves, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $auPremierNiveau = [];

        foreach ($trouves[0] as $i => [$texte, $position]) {
            $avant = substr($css, 0, $position);

            if (substr_count($avant, '{') === substr_count($avant, '}')) {
                $auPremierNiveau[] = $trouves[1][$i][0];
            }
        }

        if ($auPremierNiveau === []) {
            return null;
        }

        return $derniere ? end($auPremierNiveau) : $auPremierNiveau[0];
    }

    /** Remplace `var(--x)` par la valeur declaree dans la meme feuille. */
    private function resoudreVariables(string $bloc, string $css): string
    {
        return preg_replace_callback('/var\(\s*(--[a-z0-9-]+)\s*\)/i', function ($m) use ($css) {
            return preg_match('/'.preg_quote($m[1], '/').'\s*:\s*([^;}]+)/i', $css, $v)
                ? trim($v[1])
                : $m[0];
        }, $bloc);
    }

    /**
     * Les couleurs des declarations de FOND du bloc, normalisees et dans
     * l'ordre : `#RGB`, `#RRGGBB` et `rgba(...)`.
     *
     * Bornee a `background` et `background-image`, et la premiere version ne
     * l'etait pas — elle ramassait tout le bloc. Elle a rougi sur
     * `color:var(--ink)` de `bouclepro-hero.css`, une couleur de TEXTE que la
     * feuille des portes n'a aucune raison de reprendre. Le test mesurait autre
     * chose que son objet.
     *
     * La mise en forme du CSS, elle, n'a pas a rougir un test : seules les
     * valeurs comptent.
     */
    private function couleurs(string $bloc): array
    {
        preg_match_all('/background(?:-image)?\s*:\s*([^;]+)/i', $bloc, $declarations);

        $fonds = implode(' ', $declarations[1]);

        preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\([^)]*\)/i', $fonds, $trouves);

        return array_map(
            fn (string $c) => strtolower(preg_replace('/\s+/', '', $c)),
            $trouves[0],
        );
    }
}
