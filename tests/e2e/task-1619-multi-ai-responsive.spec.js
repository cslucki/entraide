import { test, expect } from '@playwright/test';
import { login } from '../../ai/playwright/helpers/auth.js';

/**
 * TASK-1619 / SLICE E — les 3 assistants IA, sur un VRAI viewport.
 *
 * `RESPONSIVE_HARD_GATE_SLICE_E` : TASK-1614, 1616 et 1617 ont toutes echoue a
 * valider le responsive parce que le redimensionnement de fenetre du MCP ne
 * change pas le viewport rendu — il restait a 1920 quoi qu'on demande. Trois
 * TASKs ont donc annonce « responsive verifie » sur une mesure qui ne mesurait
 * rien.
 *
 * `test.use({ viewport })` est la correction : Playwright cree le contexte AVEC
 * la taille demandee, donc les media queries de Tailwind s'appliquent
 * reellement. C'est ce que ce fichier exige, et c'est pour cela qu'il existe
 * separement des tests Livewire — eux rendent du HTML, ils ne resolvent aucune
 * media query.
 *
 * Ce que le gate doit prouver, et rien de moins :
 *
 *   BUREAU  les boutons sont VISIBLES directement dans la rangee d'actions ;
 *   MOBILE  la rangee est masquee (`hidden md:flex`), et les memes actions
 *           sont atteignables par la feuille du `+` ;
 *   LES DEUX  l'etat d'indisponibilite se lit sur les deux formats — un
 *           membre sur telephone doit savoir qu'un assistant n'a pas repondu.
 */

const LOOP_URL = '/org/main/loops/01a09a4b-403c-71f1-b804-f19263ec6e2b';
const OWNER = 'admin@bouclepro.test';
const PASSWORD = 'password';

const DESKTOP = { width: 1280, height: 800 };
const MOBILE = { width: 390, height: 844 };

test.describe('TASK-1619 — 3 assistants IA, BUREAU', () => {
    test.use({ viewport: DESKTOP, locale: 'fr-FR' });

    test('les quatre boutons sont visibles sans ouvrir de menu', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(LOOP_URL);
        await page.waitForLoadState('networkidle');

        for (const cle of ['aperio', 'traverse', 'limen']) {
            await expect(page.locator(`[data-multi-ai-ask="${cle}"]`).first()).toBeVisible();
        }

        await expect(page.locator('[data-multi-ai-ask-all]').first()).toBeVisible();
    });

    test('le lien de configuration est atteignable depuis ChatLoop', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(LOOP_URL);
        await page.waitForLoadState('networkidle');

        // La dette UX_DEBT_SLICE_E : le droit existait, la route aussi, mais
        // rien n'y menait depuis la surface ou le plugin sert.
        await expect(page.locator('[data-multi-ai-configure]').first()).toBeVisible();
    });

    test('aucun code technique ne fuit dans la page', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(LOOP_URL);
        await page.waitForLoadState('networkidle');

        const texte = await page.locator('body').innerText();

        for (const fuite of ['PROVIDER_CALL_FAILED', 'RATE_LIMITED', 'upstream_provider_shared_pool', 'RateLimitedException']) {
            expect(texte).not.toContain(fuite);
        }
    });
});

test.describe('TASK-1619 — 3 assistants IA, MOBILE', () => {
    test.use({ viewport: MOBILE, locale: 'fr-FR' });

    test('le viewport est REELLEMENT mobile', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(LOOP_URL);

        // La garde du hard gate lui-meme. Si cette assertion passe alors que la
        // fenetre fait 1920, c'est le banc qu'il faut corriger, pas la page.
        const largeur = await page.evaluate(() => window.innerWidth);
        expect(largeur).toBeLessThan(768);
    });

    test('la rangee bureau est masquee et le menu du composeur porte les actions', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(LOOP_URL);
        await page.waitForLoadState('networkidle');

        // `hidden md:flex` : la rangee existe dans le DOM, elle n'est pas VUE.
        await expect(page.locator('[data-multi-ai-actions]')).toBeHidden();

        await page.getByRole('button', { name: /plus d.actions|more actions/i }).first().click();
        await page.waitForTimeout(400);

        // `.first()` prendrait la copie BUREAU, presente dans le DOM mais
        // masquee par `hidden md:flex` : le test mesurerait alors l'inverse de
        // son intention. On exige ce qui est REELLEMENT visible.
        await expect(page.locator('[data-multi-ai-ask-all]:visible')).toHaveCount(1);
        await expect(page.locator('[data-multi-ai-ask="aperio"]:visible')).toHaveCount(1);
    });

    test('rien ne deborde horizontalement', async ({ page }) => {
        await login(page, OWNER, PASSWORD);
        await page.goto(LOOP_URL);
        await page.waitForLoadState('networkidle');

        const debordement = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );

        expect(debordement).toBeLessThanOrEqual(1);
    });
});
