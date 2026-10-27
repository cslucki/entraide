<?php

namespace Tests\Concerns;

/**
 * TASK-1705 — rendre a `storage/app/bouclepro-themes.php` l'etat qu'il avait.
 *
 * ## Le defaut que ce trait ferme
 *
 * `Theme::regenerateCache()` ecrit un FICHIER. `RefreshDatabase` annule la
 * ligne en base ; il n'annule rien dans `storage/`. Un test qui regenere le
 * cache laisse donc derriere lui des couleurs qui ne correspondent a aucune
 * ligne — et toute page rendue ENSUITE, dans le meme processus, les porte.
 *
 * C'est arrive pour de vrai, et le symptome n'avait rien a voir avec les
 * themes : `TASK1701OfshThemePaletteTest` ecrivait `primary => '#123456'` dans
 * le cache, puis `TASK358Lot2ProfileEditTest` rendait une page portant
 * `--bp-primary: #123456` et son `assertDontSee('12345')` echouait. Un test de
 * confidentialite d'adresse postale, mis en echec par un test de palette.
 *
 * ## Pourquoi ce defaut est reste INVISIBLE si longtemps
 *
 * Il ne se declare que si les deux classes tombent dans le MEME shard de CI,
 * dans CET ordre. La repartition depend de la liste des tests : **ajouter un
 * fichier de tests ailleurs suffit a la changer.** C'est ce qui l'a revele —
 * une TASK sans aucun rapport avec les themes.
 *
 * ## Pourquoi un instantane du FICHIER, et pas une regeneration
 *
 * Regenerer en fin de test produirait le cache correspondant a l'etat de la
 * base A CE MOMENT-LA, qui n'est deja plus celui d'avant le test. Seul
 * l'instantane rend exactement ce qui etait la — y compris l'ABSENCE du
 * fichier, qui est un etat comme un autre.
 *
 * Laravel appelle `setUpPreservesThemeCache()` et
 * `tearDownPreservesThemeCache()` tout seul, par convention de nom.
 */
trait PreservesThemeCache
{
    private ?string $instantaneDuCacheDeThemes = null;

    private bool $leCacheDeThemesExistait = false;

    protected function setUpPreservesThemeCache(): void
    {
        $chemin = $this->cheminDuCacheDeThemes();

        $this->leCacheDeThemesExistait = is_file($chemin);
        $this->instantaneDuCacheDeThemes = $this->leCacheDeThemesExistait
            ? file_get_contents($chemin)
            : null;
    }

    protected function tearDownPreservesThemeCache(): void
    {
        $chemin = $this->cheminDuCacheDeThemes();

        if (! $this->leCacheDeThemesExistait) {
            // Il n'existait pas : le laisser serait deja une pollution.
            if (is_file($chemin)) {
                @unlink($chemin);
            }

            return;
        }

        if ($this->instantaneDuCacheDeThemes !== null) {
            @file_put_contents($chemin, $this->instantaneDuCacheDeThemes);
        }
    }

    private function cheminDuCacheDeThemes(): string
    {
        return storage_path('app/bouclepro-themes.php');
    }
}
