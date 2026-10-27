<?php

use App\Models\Theme;
use Illuminate\Database\Migrations\Migration;

/**
 * TASK-1701 — la palette OFSH entre EN BASE, et cette migration corrige
 * TASK-1699.
 *
 * ## Ce que TASK-1699 avait decide, et pourquoi c'etait faux
 *
 * TASK-1699 a cree la ligne `themes` de cle `ofsh` volontairement VIDE de
 * jetons, en faisant porter la palette au seul `config/bouclepro_themes.php`.
 * Le raisonnement ecrit alors : `Theme::regenerateCache()` fusionne la base
 * PAR-DESSUS la configuration, donc y recopier la palette la figerait et toute
 * correction ulterieure du fichier serait silencieusement ignoree.
 *
 * Ce raisonnement est juste, et il rate l'essentiel : **l'ecran
 * d'administration des themes edite la LIGNE EN BASE**, pas le fichier.
 * `admin/themes/index.blade.php` fait `window.__themeTokens =
 * @json($currentTheme->tokens ?? [])`, puis deroule une liste figee de vingt
 * jetons en liant chaque selecteur de couleur a `tokens['<nom>']`. Avec une
 * ligne vide, les vingt selecteurs sont lies a `undefined` et l'apercu n'a
 * aucune couleur a rendre.
 *
 * Mesure, avant cette migration : les sept themes de la table portent tous
 * 20 jetons clairs et 20 sombres — `zen`, `sable`, `sea`, `inside`, `lp`,
 * `ut`. Seul `ofsh` etait a 0/0. Ce n'etait pas une convention du produit,
 * c'etait une exception introduite par T1699.
 *
 * Cyril, 25/10 : « n'oublies pas que les valeurs feront l'objet d'une
 * migration, afin que je n'ai pas a resaisir ca sur la prod. » C'est la
 * consequence concrete du defaut : sans cette migration, la PROD recevrait une
 * ligne vide et la palette devrait etre ressaisie a la main, jeton par jeton.
 *
 * ## Le modele, desormais explicite
 *
 * La ligne en base est la SOURCE pour ce theme, parce que c'est elle que
 * l'administration edite. L'entree de `config/bouclepro_themes.php` reste la
 * SEMENCE : elle alimente cette migration, et sert de repli dans tout
 * environnement ou la ligne n'existerait pas. La divergence que T1699
 * craignait devient donc le comportement VOULU — une couleur changee dans
 * l'ecran doit l'emporter sur le fichier, sans quoi l'ecran ne servirait a
 * rien.
 *
 * ## Idempotence : ne JAMAIS ecraser une couleur choisie a l'ecran
 *
 * La migration ne remplit que ce qui est VIDE. Rejouee sur une base ou
 * quelqu'un a deja ajuste la palette depuis l'administration, elle ne touche a
 * rien. C'est la seule facon de rendre ce `up()` sur une base deja en service
 * sans detruire un reglage.
 */
return new class extends Migration
{
    private const KEY = 'ofsh';

    public function up(): void
    {
        $theme = Theme::where('key', self::KEY)->first();

        // La ligne est creee par la migration de TASK-1699. Si elle manque,
        // c'est que celle-la n'a pas tourne : on ne la recree pas ici, sous
        // peine d'avoir deux endroits qui pretendent creer le meme objet.
        if (! $theme) {
            return;
        }

        $semence = config('bouclepro_themes.themes.'.self::KEY);

        if (! $semence) {
            return;
        }

        $modifie = false;

        if (empty($theme->tokens)) {
            $theme->tokens = $semence['tokens'];
            $modifie = true;
        }

        if (empty($theme->dark_tokens)) {
            $theme->dark_tokens = $semence['dark'];
            $modifie = true;
        }

        if (! $modifie) {
            return;
        }

        $theme->save();

        Theme::regenerateCache();
    }

    /**
     * Le `down()` rend la ligne a l'etat ou TASK-1699 l'avait laissee : vide.
     *
     * Il ne supprime PAS la ligne — ce n'est pas lui qui l'a creee, et
     * `organizations.theme_id` s'y rattache. Et la palette ne disparait pas
     * pour autant : `regenerateCache()` la reprend a la configuration, qui ne
     * bouge pas.
     */
    public function down(): void
    {
        $theme = Theme::where('key', self::KEY)->first();

        if (! $theme) {
            return;
        }

        $theme->tokens = [];
        $theme->dark_tokens = [];
        $theme->save();

        Theme::regenerateCache();
    }
};
