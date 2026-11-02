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

    /**
     * Recette Android du 02/11 — le panneau montrait DEUX boutons à la fois.
     *
     * `hidden` ne masque que par la feuille de style du navigateur. La classe
     * `inline-flex` de Tailwind pose un `display` d'AUTEUR, qui gagne : les
     * deux boutons restaient visibles dans TOUS les états, depuis T1739. Un
     * bouton primaire « Activer les notifications » s'affichait donc sur un
     * appareil déjà activé, et détournait de la seule action qui restait.
     *
     * Aucun test PHP ne pouvait le voir : `assertSee()` lit le HTML, où
     * l'attribut `hidden` était bel et bien présent. Il fallait un navigateur
     * et une mesure de hauteur.
     *
     * Le banc est en état `denied` (Chromium headless refuse les
     * notifications) : AUCUN des deux boutons ne doit y être visible. Avant
     * le correctif, les deux l'étaient — y compris dans cet état-là.
     */
    test('aucun bouton factice ne reste visible', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/notifications/preferences');
        await page.waitForLoadState('networkidle');

        for (const bouton of ['data-push-enable', 'data-push-disable']) {
            await expect(
                page.locator(`[${bouton}]`),
                `[${bouton}] ne doit pas s'afficher dans un état qui ne l'appelle pas`,
            ).toBeHidden();
        }
    });

    /**
     * État « pas encore abonné » : le bouton Activer DOIT s'afficher.
     *
     * Le test précédent mesure l'état `denied`, où aucun bouton ne doit
     * paraître. Il ne dit rien de la réciproque, et la réciproque est ce qui
     * compte : si le correctif du 02/11 avait masqué les DEUX boutons en
     * toutes circonstances, plus personne n'aurait pu abonner un appareil —
     * une panne pire que celle qu'il corrigeait, et invisible depuis la
     * suite telle qu'elle était écrite.
     *
     * Chromium headless refuse les notifications quoi qu'on lui accorde. On
     * force donc la seule entrée que le script lit pour brancher,
     * `Notification.permission` ; tout le reste du chemin est le vrai.
     */
    test('sans abonnement, le bouton Activer est bien propose', async ({ page }) => {
        await page.addInitScript(() => {
            Object.defineProperty(Notification, 'permission', { get: () => 'granted', configurable: true });
        });

        await loginAsMember(page);
        await page.goto('/notifications/preferences');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('[data-push-enable]')).toBeVisible();
        await expect(page.locator('[data-push-disable]')).toBeHidden();
    });

    /**
     * L'aide doit précéder le panneau de diagnostic, et viser une ancre.
     *
     * Déplié, le diagnostic mesure ~280 px et s'intercalait entre l'état de
     * l'appareil et les réglages par événement : sur un téléphone, la
     * première case à cocher tombait à 724 px du haut, deux écrans sous la
     * phrase qui disait « cochez ci-dessous ». L'outil de recette empêchait
     * de faire la recette.
     */
    test('l aide precede le diagnostic et mene aux reglages', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/notifications/preferences');
        await page.waitForLoadState('networkidle');

        const y = async (selecteur) =>
            (await page.locator(selecteur).first().boundingBox())?.y ?? Number.MAX_SAFE_INTEGER;

        const aide = await y('[data-push-help-box]');
        const diag = await y('[data-push-diag-panel]');

        expect(aide, "l'aide doit venir AVANT le diagnostic").toBeLessThan(diag);

        // Replié : il ne repousse plus les réglages hors de l'écran.
        await expect(page.locator('[data-push-diag-panel]')).not.toHaveAttribute('open', /.*/);

        // Et la cible du lien existe vraiment — un lien mort vaut moins que
        // pas de lien du tout.
        await expect(page.locator('#reglages-par-evenement')).toHaveCount(1);
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
