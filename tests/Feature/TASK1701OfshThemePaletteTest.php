<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1701 — la palette OFSH est EN BASE, et l'ecran d'administration la sert.
 *
 * Cyril, 25/10 : « remplir les valeurs des codes couleurs (tokens mode clair et
 * sombre) pour /admin/themes?theme=ofsh […] afin que je n'aie pas a resaisir ca
 * sur la prod. »
 *
 * ## Le defaut, et pourquoi il etait invisible
 *
 * TASK-1699 avait cree la ligne `themes` de clef `ofsh` volontairement VIDE, en
 * faisant porter la palette au seul `config/bouclepro_themes.php`. Le
 * raisonnement — `regenerateCache()` fusionne la base par-dessus la
 * configuration, donc y recopier la palette la figerait — est juste, et rate
 * l'essentiel : **l'ecran d'administration edite la LIGNE EN BASE**, pas le
 * fichier.
 *
 * Rien ne rougissait pour autant, et c'est ce qui rend ce defaut interessant :
 * le cache fusionne contenait bien les 40 valeurs (la configuration les
 * fournissait), donc les PAGES s'affichaient correctement. Les 15 gardes de
 * TASK-1699 mesuraient le rendu, pas l'editeur. Seul l'ecran d'administration
 * voyait la ligne nue — et personne ne le regardait.
 *
 * Mesure qui a tranche : les sept themes de la table portent tous 20 jetons
 * clairs et 20 sombres — `zen`, `sable`, `sea`, `inside`, `lp`, `ut`. Seul
 * `ofsh` etait a 0/0. Ce n'etait pas une convention du produit.
 *
 * ## DEUX CHEMINS MENENT A UNE LIGNE PLEINE, et il a fallu cinq sabotages
 * VERTS pour que je le voie
 *
 * Premier jet de ce fichier : sept assertions vertes, et CINQ sabotages de la
 * migration qui ne rougissaient pas. La cause :
 * `2026_06_09_000001_create_themes_table.php` insere EN BASE **tous les themes
 * de la configuration**, avec leurs 40 valeurs. Il y a donc deux chemins :
 *
 *   * base NEUVE (cette suite, `migrate:fresh`) — la migration de juin cree
 *     `ofsh` deja plein, puisque T1699 l'a ajoute a la configuration. La
 *     migration de T1701 ne fait alors RIEN, et des assertions posees sur
 *     l'etat final sont vertes meme si on la supprime entierement ;
 *   * base EXISTANTE (le poste de Cyril, la PROD) — la migration de juin a
 *     tourne bien avant que `ofsh` existe dans la configuration. La ligne y a
 *     ete creee VIDE par T1699, et seule la migration de T1701 la remplit.
 *
 * C'est le second chemin qui compte, et c'est exactement celui qu'une suite
 * partant d'une base neuve ne peut PAS observer. Les gardes ci-dessous
 * exercent donc la migration DIRECTEMENT, sur une ligne vidée a dessein.
 *
 * Le reste du fichier mesure l'etat final, qui reste utile : il dit que les
 * deux chemins arrivent au meme endroit, et que l'ecran sert ce qu'ils ont
 * ecrit.
 */
class TASK1701OfshThemePaletteTest extends TestCase
{
    use RefreshDatabase;

    private const CLE = 'ofsh';

    private function administrateur(): User
    {
        return User::factory()->complete()->create([
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Les jetons servis a l'editeur, lus la ou l'editeur les lit.
     *
     * `admin/themes/index.blade.php` pose `window.__themeTokens` et
     * `window.__themeDarkTokens`, puis deroule une liste FIGEE de vingt jetons
     * en liant chaque selecteur de couleur a `tokens['<nom>']`. C'est donc ces
     * deux objets qu'il faut inspecter : compter les `<input type="color">`
     * ne prouverait rien, ils sont rendus meme vides.
     */
    private function jetonsDeLEcran(string $html): array
    {
        $extrait = function (string $variable) use ($html): array {
            $this->assertSame(
                1,
                preg_match('/window\.__'.$variable.' = (\{.*?\}|\[\]);/s', $html, $m),
                "L'ecran ne pose pas `window.__{$variable}`."
            );

            return json_decode($m[1], true) ?: [];
        };

        return [$extrait('themeTokens'), $extrait('themeDarkTokens')];
    }

    /** La migration de TASK-1701, instanciee depuis son fichier. */
    private function migration(): object
    {
        return require database_path('migrations/2026_10_25_230000_write_the_ofsh_palette_into_its_theme_row.php');
    }

    /** Remet la ligne dans l'etat d'une base EXISTANTE : creee, mais vide. */
    private function viderLaLigne(): Theme
    {
        $theme = Theme::where('key', self::CLE)->firstOrFail();
        $theme->tokens = [];
        $theme->dark_tokens = [];
        $theme->save();

        return $theme->fresh();
    }

    // ── La migration, exercee sur le chemin qui compte ──────────────────────

    /**
     * Le cas REEL : une base ou la ligne existe mais est vide.
     *
     * C'est l'etat du poste de Cyril et celui de la PROD — la migration de juin
     * y a tourne bien avant que `ofsh` existe dans la configuration, et T1699
     * y a cree la ligne vide. Aucune base neuve ne reproduit cet etat, donc
     * aucune assertion sur l'etat final ne peut le mesurer : il faut le
     * FABRIQUER.
     */
    public function test_une_base_existante_recoit_la_palette_par_la_migration(): void
    {
        $this->viderLaLigne();

        $this->migration()->up();

        $theme = Theme::where('key', self::CLE)->firstOrFail();
        $semence = config('bouclepro_themes.themes.'.self::CLE);

        $this->assertSame($semence['tokens'], $theme->tokens, 'La migration n\'a pas inscrit les jetons clairs.');
        $this->assertSame($semence['dark'], $theme->dark_tokens, 'La migration n\'a pas inscrit les jetons sombres.');
    }

    /**
     * Et elle n'ecrase JAMAIS un reglage fait a l'ecran.
     *
     * C'est la contrepartie de la precedente, et elle compte autant : la
     * migration peut etre rejouee sur une base en service (un
     * `migrate:refresh`, un redeploiement). Si elle recopiait la semence sans
     * regarder, elle detruirait silencieusement une couleur choisie dans
     * l'administration.
     */
    public function test_la_migration_n_ecrase_pas_un_reglage_fait_a_l_ecran(): void
    {
        $theme = Theme::where('key', self::CLE)->firstOrFail();
        $reglage = array_merge($theme->tokens, ['primary' => '#ABCDEF']);
        $theme->tokens = $reglage;
        $theme->save();

        $this->migration()->up();

        $this->assertSame(
            '#ABCDEF',
            Theme::where('key', self::CLE)->firstOrFail()->tokens['primary'],
            'La migration a ecrase une couleur choisie a l\'ecran.'
        );
    }

    /**
     * Le `down()` rend la ligne a l'etat de T1699 — vide — sans la supprimer.
     *
     * La supprimer casserait `organizations.theme_id`, qui s'y rattache.
     */
    public function test_le_retour_arriere_vide_la_ligne_sans_la_supprimer(): void
    {
        $this->migration()->down();

        $theme = Theme::where('key', self::CLE)->first();

        $this->assertNotNull($theme, 'Le retour arriere a supprime la ligne.');
        $this->assertSame([], $theme->tokens);
        $this->assertSame([], $theme->dark_tokens);
    }

    // ── L'etat final, quel que soit le chemin ───────────────────────────────

    /**
     * Sur une base NEUVE, c'est la migration de juin qui a rempli la ligne.
     * Cette garde ne mesure donc pas TASK-1701 — elle mesure que les deux
     * chemins arrivent au meme endroit, ce qui vaut d'etre fige.
     */
    public function test_la_ligne_en_base_porte_les_vingt_jetons_clairs_et_les_vingt_sombres(): void
    {
        $theme = Theme::where('key', self::CLE)->first();

        $this->assertNotNull($theme, 'La ligne de theme `ofsh` n\'existe pas.');
        $this->assertCount(20, $theme->tokens, 'Les jetons CLAIRS ne sont pas inscrits en base.');
        $this->assertCount(20, $theme->dark_tokens, 'Les jetons SOMBRES ne sont pas inscrits en base.');
    }

    /**
     * Et ce sont bien les valeurs de la semence, pas un jeu approchant.
     *
     * Sans cette comparaison, une migration qui aurait ecrit vingt fois la
     * meme couleur passerait le test precedent.
     */
    public function test_les_valeurs_inscrites_sont_celles_de_la_semence(): void
    {
        $theme = Theme::where('key', self::CLE)->firstOrFail();
        $semence = config('bouclepro_themes.themes.'.self::CLE);

        $this->assertSame($semence['tokens'], $theme->tokens);
        $this->assertSame($semence['dark'], $theme->dark_tokens);
    }

    /**
     * La ligne `ofsh` n'est plus l'exception de la table.
     *
     * C'est la mesure qui a revele le defaut, et elle vaut d'etre figee : elle
     * rougira pour TOUT theme futur cree sans sa palette, pas seulement pour
     * celui-ci.
     */
    public function test_aucun_theme_de_la_table_n_est_servi_sans_sa_palette(): void
    {
        $themes = Theme::all();

        $this->assertGreaterThanOrEqual(1, $themes->count(), 'Aucun theme en base : la garde ne mesure rien.');

        $vides = $themes
            ->filter(fn (Theme $t): bool => count($t->tokens ?? []) !== 20 || count($t->dark_tokens ?? []) !== 20)
            ->map(fn (Theme $t): string => sprintf('%s (%d/%d)', $t->key, count($t->tokens ?? []), count($t->dark_tokens ?? [])))
            ->values()
            ->all();

        $this->assertSame([], $vides, 'Themes sans palette complete : '.implode(', ', $vides));
    }

    // ── L'ecran d'administration ────────────────────────────────────────────

    public function test_l_ecran_d_administration_sert_les_quarante_valeurs(): void
    {
        $html = $this->actingAs($this->administrateur())
            ->get('/admin/themes?theme='.self::CLE)
            ->assertOk()
            ->getContent();

        [$clairs, $sombres] = $this->jetonsDeLEcran($html);

        $this->assertCount(20, $clairs, 'L\'editeur ne recoit pas les jetons clairs.');
        $this->assertCount(20, $sombres, 'L\'editeur ne recoit pas les jetons sombres.');

        $semence = config('bouclepro_themes.themes.'.self::CLE);
        $this->assertSame($semence['tokens'], $clairs);
        $this->assertSame($semence['dark'], $sombres);
    }

    /**
     * Les vingt jetons que l'ecran SAIT editer sont tous presents.
     *
     * L'editeur deroule une liste figee de noms. Un jeton qui manquerait a la
     * palette y serait un selecteur lie a `undefined` — exactement le defaut
     * corrige ici, mais sur une seule couleur au lieu de vingt, donc bien plus
     * facile a laisser passer.
     */
    public function test_la_palette_couvre_exactement_les_jetons_que_l_editeur_connait(): void
    {
        $attendus = [
            'page', 'surface', 'surface-soft', 'panel',
            'primary', 'primary-deep', 'accent', 'progress', 'validation', 'info', 'warning',
            'text', 'muted', 'disabled', 'border',
            'card-loop', 'card-exchange', 'card-directory', 'card-news', 'card-welcome',
        ];

        $theme = Theme::where('key', self::CLE)->firstOrFail();

        $this->assertSame($attendus, array_keys($theme->tokens));
        $this->assertSame($attendus, array_keys($theme->dark_tokens));
    }

    /**
     * Clair et sombre different REELLEMENT.
     *
     * Une palette sombre recopiee de la claire passerait toutes les assertions
     * de comptage ci-dessus, et l'ecran afficherait deux colonnes identiques.
     */
    public function test_le_volet_sombre_n_est_pas_une_copie_du_clair(): void
    {
        $theme = Theme::where('key', self::CLE)->firstOrFail();

        $identiques = array_keys(array_intersect_assoc($theme->tokens, $theme->dark_tokens));

        // L'ambre #FFC247 est volontairement identique dans les deux modes :
        // elle est lisible sur fond clair comme sur fond sombre, et c'est la
        // seule des quatre couleurs d'ADN dans ce cas. Elle porte DEUX roles —
        // `validation` et la carte `card-directory` — d'ou deux jetons et non
        // un seul : les nommer tous les deux ici dit que c'est la meme couleur
        // a deux endroits, pas deux coincidences.
        //
        // Toute autre egalite serait une recopie du volet clair.
        $this->assertSame(
            ['validation', 'card-directory'],
            $identiques,
            'Trop de jetons identiques entre clair et sombre : recopie probable.'
        );

        // Et le reste differe vraiment : 18 jetons sur 20.
        $this->assertCount(18, array_diff_assoc($theme->tokens, $theme->dark_tokens));
    }

    // ── Le cache ────────────────────────────────────────────────────────────

    /**
     * La base et le cache disent la MEME chose.
     *
     * `regenerateCache()` fusionne la base par-dessus la configuration. Tant
     * que les deux portaient les memes valeurs, un ecart etait impossible a
     * voir ; maintenant que la base est la source, un cache perime servirait
     * des couleurs que l'administration ne montre plus.
     */
    public function test_le_cache_de_themes_reflete_la_ligne_en_base(): void
    {
        $theme = Theme::where('key', self::CLE)->firstOrFail();

        $theme->tokens = array_merge($theme->tokens, ['primary' => '#123456']);
        $theme->save();
        Theme::regenerateCache();

        $cache = bp_themes();

        $this->assertSame('#123456', $cache['themes'][self::CLE]['tokens']['primary']);
        $this->assertCount(20, $cache['themes'][self::CLE]['dark']);
    }
}
