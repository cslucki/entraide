// TASK-1227 — Comportement IA : Constitution, doctrine de l'Organization,
// couverture du systeme nerveux, bac a sable reel (Admin Organization).
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation, ArtSciLab Demo). Parcours : login Maya (admin),
// lire la Constitution, editer la doctrine, enregistrer, voir la version
// active, les capabilities qui l'utilisent, le bloc de couverture, lancer
// un test sandbox (APPEL IA REEL avec la cle de l'Organization, comptabilise),
// smoke 390x844, console propre. Puis : un membre d'une AUTRE Organization
// n'entre pas (403) ; une autre Organization voit SA doctrine, jamais celle-ci.
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/ai-behavior-doctrine.spec.js

import { test, expect } from '@playwright/test';

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const ADMIN_EMAIL = 'maya@artscilab-demo.test';
const PASSWORD = 'password';
const BEHAVIOR = `${ORG_ROOT}/admin/ai-behavior`;

const OTHER_ORG_SLUG = 'ai-validation-org-b';
const OTHER_MEMBER_EMAIL = 'member1@ai-validation-org-b.ai-validation.test';
const PLATFORM_ADMIN_ORG_SLUG = 'ai-validation-org-a';
const PLATFORM_ADMIN_EMAIL = 'admin@ai-validation-org-a.ai-validation.test';

const STAMP = `TASK1227-${Date.now()}`;
const DOCTRINE_V1 = `Doctrine ArtSciLab (${STAMP}) : tutoyer les membres, rappeler la charte d'entraide.`;
const DOCTRINE_V2 = `${DOCTRINE_V1}\nTerminer toujours la réponse par la phrase exacte : « — Doctrine ArtSciLab appliquée ».`;

async function login(page, orgSlug, email) {
    await page.goto(`/org/${orgSlug}/login`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.getByRole('button', { name: /sign in|se connecter/i }).click();
    await page.waitForURL((url) => url.pathname !== `/org/${orgSlug}/login`);
}

function watchConsole(page) {
    const errors = [];
    const failed = [];
    const serverErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') errors.push(message.text());
    });
    page.on('requestfailed', (request) => {
        // `_boost/browser-logs` : sonde Laravel Boost du banc, hors produit.
        if (!/_boost\//.test(request.url())) failed.push(`${request.method()} ${request.url()} :: ${request.failure()?.errorText}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });
    return { errors, failed, serverErrors };
}

function assertClean(watch) {
    const relevantErrors = watch.errors.filter((e) => !/favicon|_boost/i.test(e));
    expect(relevantErrors, relevantErrors.join('\n')).toEqual([]);
    expect(watch.failed, watch.failed.join('\n')).toEqual([]);
    expect(watch.serverErrors, watch.serverErrors.join('\n')).toEqual([]);
}

async function withdrawIfActive(page) {
    await page.goto(BEHAVIOR);
    if (await page.locator('[data-behavior-doctrine-withdraw]').count()) {
        await page.locator('[data-behavior-doctrine-withdraw]').click();
        await page.locator('[data-behavior-doctrine-withdraw-confirm]').click();
        await page.waitForURL((url) => url.pathname.endsWith('/admin/ai-behavior'));
    }
}

test.describe('TASK-1227 Comportement IA — doctrine Organization', () => {
    test('desktop : Constitution, doctrine v1 -> v2, capabilities, couverture, sandbox reel', async ({ page }) => {
        test.setTimeout(120000);
        const watch = watchConsole(page);
        await login(page, ORG_SLUG, ADMIN_EMAIL);

        // Etat de depart propre (l'historique reste, seule l'activation change).
        await withdrawIfActive(page);

        const response = await page.goto(BEHAVIOR);
        expect(response?.status()).toBe(200);

        // 1. Constitution en lecture seule.
        await expect(page.locator('h1[data-behavior-title]')).toBeVisible();
        await expect(page.locator('[data-behavior-constitution-badge]')).toBeVisible();
        await expect(page.locator('[data-behavior-constitution-text]')).toContainText('Constitution BouclePro IA — v1');
        expect(await page.locator('[data-behavior-constitution] textarea, [data-behavior-constitution] input').count()).toBe(0);

        // 2. Doctrine vide.
        await expect(page.locator('[data-behavior-doctrine-status]')).toHaveAttribute('data-behavior-doctrine-status', 'none');

        // Cascade et bloc « ne peut pas ».
        await expect(page.locator('[data-behavior-cascade]')).toBeVisible();
        await expect(page.locator('[data-behavior-doctrine-limits]')).toBeVisible();

        // 3. Ecrire, enregistrer -> « version N active ».
        await page.fill('#doctrine-body', DOCTRINE_V1);
        await expect(page.locator('[data-behavior-doctrine-counter]')).toContainText(String(DOCTRINE_V1.length));
        await page.locator('[data-behavior-doctrine-save]').click();
        await page.waitForURL((url) => url.pathname.endsWith('/admin/ai-behavior'));
        const status = page.locator('[data-behavior-doctrine-status]');
        await expect(status).toHaveAttribute('data-behavior-doctrine-status', 'active');
        const v1 = Number(await status.getAttribute('data-behavior-doctrine-version'));
        expect(v1).toBeGreaterThan(0);
        await expect(status).toContainText(new RegExp(`v${v1}`));
        await expect(page.locator('[data-behavior-doctrine-meta]')).toContainText(/Maya/);
        await expect(page.locator('#doctrine-body')).toHaveValue(DOCTRINE_V1);

        // 4. Capabilities qui l'utilisent : les 3 canoniques.
        for (const id of ['clarify_help_request', 'loop_summary', 'loop_knowledge_answer']) {
            await expect(page.locator(`[data-behavior-used-by-capability="${id}"]`)).toBeVisible();
        }

        // 5. Couverture : couvert / herite honnetement, compte coherent.
        const coverage = page.locator('[data-behavior-coverage]');
        const covered = Number(await coverage.getAttribute('data-behavior-coverage-covered'));
        const total = Number(await coverage.getAttribute('data-behavior-coverage-total'));
        expect(await page.locator('[data-behavior-coverage-kind="covered"]').count()).toBe(covered);
        expect(await page.locator('[data-behavior-coverage-kind="inherited"]').count()).toBe(total - covered);
        await expect(page.locator('[data-behavior-coverage-summary]')).toContainText(new RegExp(`${covered}.*${total}`));
        await expect(page.locator('[data-behavior-coverage-item="member_profile_agent"]')).toBeVisible();

        // Version 2.
        await page.fill('#doctrine-body', DOCTRINE_V2);
        await page.locator('[data-behavior-doctrine-save]').click();
        await page.waitForURL((url) => url.pathname.endsWith('/admin/ai-behavior'));
        await expect(status).toHaveAttribute('data-behavior-doctrine-version', String(v1 + 1));
        await page.locator('[data-behavior-history] summary').click();
        await expect(page.locator(`[data-behavior-history-row="${v1}"]`)).toHaveAttribute('data-behavior-history-status', 'superseded');
        await expect(page.locator(`[data-behavior-history-row="${v1 + 1}"]`)).toHaveAttribute('data-behavior-history-status', 'active');

        // 6. Sandbox REEL (Questions aux Dossiers) : le brouillon = le champ.
        await page.selectOption('#sandbox-capability', 'loop_knowledge_answer');
        await page.fill('#sandbox-question', 'Que doit contenir une installation itinérante ?');
        await page.locator('[data-behavior-sandbox-run]').click();
        await page.waitForURL((url) => url.pathname.endsWith('/admin/ai-behavior'), { timeout: 60000 });
        const result = page.locator('[data-behavior-sandbox-result]');
        await expect(result).toBeVisible();
        const sandboxStatus = await result.getAttribute('data-behavior-sandbox-status');
        expect(['answered', 'no_sources', 'refused']).toContain(sandboxStatus);
        await expect(page.locator('[data-behavior-sandbox-doctrine]')).toHaveAttribute('data-behavior-sandbox-doctrine', 'draft');
        await expect(page.locator('[data-behavior-sandbox-guided]')).toContainText(/Constitution v1/);
        if (sandboxStatus === 'answered') {
            await expect(page.locator('[data-behavior-sandbox-ledgered]')).toHaveAttribute('data-behavior-sandbox-ledgered', '1');
            await expect(page.locator('[data-behavior-sandbox-answer]')).toContainText(/Doctrine ArtSciLab appliquée/);
            expect(Number(await page.locator('[data-behavior-sandbox-sources]').getAttribute('data-behavior-sandbox-sources'))).toBeGreaterThan(0);
        } else {
            await expect(page.locator('[data-behavior-sandbox-ledgered]')).toHaveAttribute('data-behavior-sandbox-ledgered', '0');
        }
        // Le brouillon revient dans le champ ; la version active n'a pas bouge.
        await expect(page.locator('#doctrine-body')).toHaveValue(DOCTRINE_V2);
        await expect(status).toHaveAttribute('data-behavior-doctrine-version', String(v1 + 1));

        // Aucune cle, aucune donnee tenant etrangere.
        const html = await page.content();
        expect(html).not.toMatch(/sk-or-|sk-[a-z0-9]{10,}/i);
        expect(html).not.toMatch(/ai-validation-org|SENTINEL-(A|B)/i);

        assertClean(watch);
    });

    test('mobile 390x844 : page lisible, formulaire et couverture visibles', async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const page = await context.newPage();
        const watch = watchConsole(page);
        await login(page, ORG_SLUG, ADMIN_EMAIL);
        const response = await page.goto(BEHAVIOR);
        expect(response?.status()).toBe(200);
        await expect(page.locator('h1[data-behavior-title]')).toBeVisible();
        await expect(page.locator('[data-behavior-constitution-text]')).toBeVisible();
        await expect(page.locator('#doctrine-body')).toBeVisible();
        await page.locator('[data-behavior-coverage-summary]').scrollIntoViewIfNeeded();
        await expect(page.locator('[data-behavior-coverage-summary]')).toBeVisible();
        // Pas de debordement horizontal.
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        expect(overflow).toBe(false);
        assertClean(watch);
        await context.close();
    });

    test('tenant : un membre d une autre Organization est refuse ; une autre Organization voit sa propre doctrine', async ({ browser }) => {
        // Membre ordinaire de l'Organization B : aucun droit sur l'ArtSciLab.
        const memberContext = await browser.newContext();
        const memberPage = await memberContext.newPage();
        await login(memberPage, OTHER_ORG_SLUG, OTHER_MEMBER_EMAIL);
        const forbidden = await memberPage.goto(BEHAVIOR);
        expect(forbidden?.status()).toBe(403);
        const put = await memberPage.request.put(`${BEHAVIOR}/doctrine`, { form: { body: 'x' } });
        expect([403, 419]).toContain(put.status());
        await memberContext.close();

        // Depuis l'Organization A (admin plateforme du banc) : SA page, jamais la doctrine ArtSciLab.
        const otherContext = await browser.newContext();
        const otherPage = await otherContext.newPage();
        await login(otherPage, PLATFORM_ADMIN_ORG_SLUG, PLATFORM_ADMIN_EMAIL);
        const own = await otherPage.goto(`/org/${PLATFORM_ADMIN_ORG_SLUG}/admin/ai-behavior`);
        expect(own?.status()).toBe(200);
        const html = await otherPage.content();
        expect(html).not.toContain('Doctrine ArtSciLab');
        expect(html).not.toContain(STAMP);
        await expect(otherPage.locator('[data-behavior-doctrine-status]')).toBeVisible();
        await otherContext.close();
    });
});
