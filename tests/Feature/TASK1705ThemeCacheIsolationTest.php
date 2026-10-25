<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\Concerns\PreservesThemeCache;
use Tests\TestCase;

/**
 * TASK-1705 — le cache de themes ne doit pas survivre au test qui l'ecrit.
 *
 * ## Le defaut, et comment il s'est montre
 *
 * `Theme::regenerateCache()` ecrit `storage/app/bouclepro-themes.php`. C'est un
 * FICHIER : `RefreshDatabase` annule la ligne en base, il n'annule rien dans
 * `storage/`. `TASK1701OfshThemePaletteTest` y ecrivait `primary => '#123456'`
 * et le laissait derriere lui.
 *
 * Le symptome n'avait aucun rapport avec les themes :
 * `TASK358Lot2ProfileEditTest::test_private_address_fields_are_saved_and_not_displayed_on_public_profile`
 * rendait ensuite une page portant `--bp-primary: #123456`, et son
 * `assertDontSee('12345')` echouait. **Un test de confidentialite d'adresse
 * postale, mis en echec par un test de palette.**
 *
 * ## Pourquoi il est reste invisible, et ce que cela coute de le laisser
 *
 * Il ne se declare que si les deux classes tombent dans le MEME shard, dans CET
 * ordre. La repartition depend de la liste des tests : **ajouter un fichier de
 * tests ailleurs suffit a la changer.** C'est exactement ce qui l'a revele —
 * la presente TASK, qui n'a aucun rapport avec les themes, a fait rougir la
 * gate bloquante.
 *
 * Un tel defaut ne se corrige donc pas « quand il se montrera » : il se montre
 * a un moment imprevisible, sur une TASK innocente, et fait perdre le temps de
 * chercher une regression qui n'existe pas.
 *
 * ## Ce que ce fichier mesure
 *
 * La garde est ARCHITECTURALE, et c'est deliberement le cas : une garde
 * comportementale ne protegerait que les deux classes d'aujourd'hui. Celle-ci
 * protege celles de demain — c'est la forme que `TASK1471PublicThemeFoundationTest`
 * emploie deja pour les themes.
 */
class TASK1705ThemeCacheIsolationTest extends TestCase
{
    use PreservesThemeCache;

    /**
     * L'appel cherche, nomme UNE SEULE FOIS.
     *
     * La garde et sa non-vacuite portaient chacune leur propre chaine
     * litterale : renommer celle de la garde la rendait aveugle pendant que la
     * non-vacuite, qui regardait l'autre, restait verte. Un sabotage l'a
     * montre. Deux chaines pour une idee, c'est exactement ce que TASK-1702
     * avait deja paye sur le nom d'un evenement.
     */
    private const APPEL = 'Theme::regenerateCache()';

    /**
     * Tout test qui REGENERE le cache doit le restaurer.
     *
     * La mesure est faite sur le code source des tests, parce que c'est la
     * seule facon de couvrir les classes qui n'existent pas encore.
     */
    public function test_tout_test_qui_regenere_le_cache_le_restaure(): void
    {
        $coupables = [];

        foreach (File::allFiles(base_path('tests')) as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }

            // L'APPEL, pas la MENTION. `php_strip_whitespace()` retire les
            // commentaires : sans cela, le trait lui-meme — qui decrit le
            // defaut dans sa documentation sans jamais appeler la methode —
            // etait denonce comme coupable. Ma premiere version de cette garde
            // l'a fait, et c'est elle qui l'a montre.
            $source = php_strip_whitespace($fichier->getPathname());

            if (! str_contains($source, self::APPEL)) {
                continue;
            }

            if (! str_contains((string) file_get_contents($fichier->getPathname()), 'use PreservesThemeCache;')) {
                $coupables[] = str_replace(base_path('tests').'/', '', $fichier->getPathname());
            }
        }

        $this->assertSame(
            [],
            $coupables,
            "Ces tests regenerent le cache de themes sans le restaurer ; ils contamineront toute page rendue ensuite :\n  "
                .implode("\n  ", $coupables)
        );
    }

    /**
     * Non-vacuite : la recherche trouve REELLEMENT des appelants.
     *
     * Sans ce volet, renommer `regenerateCache()` rendrait la garde ci-dessus
     * verte en ne regardant plus rien — le faux vert le plus courant de ce
     * depot.
     */
    public function test_la_recherche_trouve_reellement_des_appelants(): void
    {
        $appelants = 0;

        foreach (File::allFiles(base_path('tests')) as $fichier) {
            if ($fichier->getExtension() === 'php'
                && str_contains(php_strip_whitespace($fichier->getPathname()), self::APPEL)) {
                $appelants++;
            }
        }

        $this->assertGreaterThanOrEqual(
            2,
            $appelants,
            'La garde ne trouve plus d\'appelant : elle serait verte sans rien mesurer.'
        );
    }

    /**
     * Il rend aussi l'ABSENCE du fichier.
     *
     * C'est un etat comme un autre, et c'est celui d'une machine neuve ou d'une
     * CI propre : y laisser un cache genere par un test, c'est servir a tous
     * les suivants des couleurs qui ne viennent d'aucune ligne.
     */
    public function test_le_trait_rend_aussi_l_absence_du_fichier(): void
    {
        $this->avecLeCacheRendu(function (string $chemin) {
            if (is_file($chemin)) {
                unlink($chemin);
            }

            // L'instantane est pris sur un etat SANS fichier.
            $this->setUpPreservesThemeCache();

            file_put_contents($chemin, "<?php return ['themes' => []];\n");
            $this->tearDownPreservesThemeCache();

            $this->assertFalse(is_file($chemin), 'Le trait laisse un fichier la ou il n\'y en avait aucun.');
        });
    }

    /**
     * Il rend le CONTENU exact quand le fichier existait.
     *
     * Le fichier est cree AVANT de prendre l'instantane, et ce n'est pas une
     * precaution decorative : sur une machine ou il n'existe pas, c'est l'autre
     * branche qui s'exercerait et cette garde ne mesurerait rien. Un sabotage
     * qui vidait la restauration du contenu est reste VERT exactement pour
     * cette raison — le meme piege qu'en TASK-1701, ou le chemin de test
     * n'atteignait pas le chemin de code qui comptait.
     */
    public function test_le_trait_restaure_le_contenu_exact(): void
    {
        $this->avecLeCacheRendu(function (string $chemin) {
            file_put_contents($chemin, "<?php return ['themes' => ['origine' => 1]];\n");
            $this->setUpPreservesThemeCache();
            $attendu = file_get_contents($chemin);

            file_put_contents($chemin, "<?php return ['themes' => ['sentinelle' => 1]];\n");
            $this->tearDownPreservesThemeCache();

            $this->assertTrue(is_file($chemin), 'Le trait a supprime un fichier qui existait.');
            $this->assertSame($attendu, file_get_contents($chemin), 'Le trait n\'a pas rendu le contenu d\'avant.');
        });
    }

    /**
     * Exercer le trait SUR LE VRAI FICHIER, puis rendre l'environnement.
     *
     * Ces deux gardes manipulent le cache reel — c'est le seul chemin que le
     * trait connaisse. Elles se doivent donc, elles aussi, de le rendre : sans
     * quoi elles seraient le defaut qu'elles denoncent.
     *
     * Le `setUpPreservesThemeCache()` final n'est pas facultatif. Laravel
     * rappelle `tearDownPreservesThemeCache()` automatiquement en fin de test ;
     * si l'instantane y decrivait encore l'etat FABRIQUE au milieu du test,
     * cette restauration automatique defferait la remise en etat. C'est ce qui
     * s'est produit : la premiere version de ces gardes a SUPPRIME le cache de
     * la machine de developpement.
     */
    private function avecLeCacheRendu(callable $corps): void
    {
        $chemin = storage_path('app/bouclepro-themes.php');
        $original = is_file($chemin) ? file_get_contents($chemin) : null;

        try {
            $corps($chemin);
        } finally {
            if ($original !== null) {
                file_put_contents($chemin, $original);
            } elseif (is_file($chemin)) {
                unlink($chemin);
            }

            // L'instantane redecrit la REALITE : la restauration automatique
            // de Laravel devient alors sans effet, au lieu d'etre nuisible.
            $this->setUpPreservesThemeCache();
        }
    }
}
