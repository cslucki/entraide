import { test, expect } from '@playwright/test';
import { loginAsMember } from './helpers/auth.js';

/**
 * TASK-1744 — les quatre reglages Push sont VISIBLES sur l'ecran.
 *
 * Pourquoi un spec navigateur alors que la suite PHP mesure deja le rendu :
 * `assertSee()` lit le HTML, pas l'ECRAN. Un `display: none` herite, un
 * conteneur a hauteur nulle ou une case rendue hors du cadre passeraient
 * verts en PHP et laisseraient la personne sans moyen d'allumer son Push —
 * lecon payee en T1737.
 *
 * L'ecran ne porte AUCUNE ligne de code de cette TASK : il derive du
 * catalogue depuis T1375. C'est precisement ce qu'on verifie — que la
 * derivation suffit, et qu'on n'a pas eu besoin de la toucher.
 *
 * Lecture seule : aucun reglage n'est enregistre. Allumer le Push de quelqu'un
 * depuis un banc de test reviendrait a prendre la decision a sa place, et
 * c'est l'inverse de ce que `default => false` protege.
 */
test.describe('TASK-1744 — reglages Web Push', () => {
    test('les quatre evenements exposent un reglage « Sur mes appareils » visible', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/notifications/preferences');
        await page.waitForLoadState('domcontentloaded');

        const cles = [
            'loop.invitation',
            'loop.member_added',
            'loop.join_request.accepted',
            'blog.co_author.added',
        ];

        for (const cle of cles) {
            const ligne = page.locator(`[data-preference-channel="${cle}:web_push"]`);

            await expect(ligne, `[${cle}] la ligne du canal doit exister`).toHaveCount(1);
            await expect(ligne, `[${cle}] et etre VISIBLE a l'ecran`).toBeVisible();

            // Le libelle nomme l'APPAREIL, pas la technique : « web push » ne
            // veut rien dire pour la personne qui regle.
            await expect(ligne).toContainText(/Sur mes appareils|On my devices/);

            // Reglable, donc une case — et non le badge « Toujours active ».
            await expect(
                ligne.locator('input[type="checkbox"]'),
                `[${cle}] le Push doit etre REGLABLE`,
            ).toBeVisible();
        }

        // Et le defaut est OFF : la case est DECOCHEE pour qui n'a rien regle.
        // C'est la propriete produit du lot, et elle se verifie a l'ecran.
        for (const cle of cles) {
            await expect(
                page.locator(`[data-preference-channel="${cle}:web_push"] input[type="checkbox"]`),
                `[${cle}] le Push ne doit pas etre pre-coche`,
            ).not.toBeChecked();
        }
    });

    test('les deux evenements ecartes n exposent AUCUN reglage Push', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/notifications/preferences');
        await page.waitForLoadState('domcontentloaded');

        // Anti-bruit du CDC 6.2 : un refus d'adhesion n'ouvre aucune porte, un
        // commentaire de blog ne vous est pas adresse. Pas de reglage affiche
        // pour un canal que le catalogue n'autorise pas — l'ecran n'invente
        // rien.
        for (const cle of ['loop.join_request.rejected', 'blog.comment.created']) {
            await expect(
                page.locator(`[data-preference-channel="${cle}:web_push"]`),
                `[${cle}] ne doit porter aucun reglage Push`,
            ).toHaveCount(0);
        }
    });
});
