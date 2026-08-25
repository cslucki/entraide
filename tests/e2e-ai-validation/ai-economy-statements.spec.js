// TASK-1228 — Economie visible : releve User, releve Organization, agregat
// plateforme, preuve d'isolation depuis un second compte.
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation, ArtSciLab Demo). Parcours « ce que Cyril doit
// VOIR » (7 etapes) : une invocation REELLE declenchee par Maya (question aux
// Dossiers depuis une Boucle), qui apparait dans SON releve, dans le releve de
// l'Organization (bonne categorie), dans l'agregat plateforme ; coherence des
// trois chiffres ; un appel au cout non mesurable visible COMME TEL ; depuis
// une autre Organization : rien de tout cela.
//
// LIVRABLE VISUEL : captures PNG 01..07 + video dans _local/captures/TASK-1228/
// (ecran REEL, aucun montage). `test.use({ video, screenshot })` ICI seulement :
// la configuration partagee reste intacte.
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/ai-economy-statements.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1228');
fs.mkdirSync(CAPTURES, { recursive: true });

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const ADMIN_EMAIL = 'maya@artscilab-demo.test';
const PASSWORD = 'password';
const LOOP = `${ORG_ROOT}/loops/artscilab-launchpals`;
const USER_PAGE = `${ORG_ROOT}/profile/ai-usage`;
const ORG_PAGE = `${ORG_ROOT}/admin/ai-consumption`;
const PLATFORM_PAGE = '/admin/ai-organizations';

const OTHER_ORG_SLUG = 'ai-validation-org-b';
const OTHER_MEMBER_EMAIL = 'member1@ai-validation-org-b.ai-validation.test';
const PLATFORM_ADMIN_ORG_SLUG = 'ai-validation-org-a';
const PLATFORM_ADMIN_EMAIL = 'admin@ai-validation-org-a.ai-validation.test';

const QUESTION = 'Que doit contenir une installation itinérante ?';

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
        if (!/_boost\//.test(request.url())) failed.push(`${request.method()} ${request.url()} :: ${request.failure()?.errorText}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });
    return { errors, failed, serverErrors };
}

function assertClean(watch) {
    const relevantErrors = watch.errors.filter((e) => !/favicon|_boost|Failed to send logs/i.test(e));
    expect(relevantErrors, relevantErrors.join('\n')).toEqual([]);
    expect(watch.failed, watch.failed.join('\n')).toEqual([]);
    expect(watch.serverErrors, watch.serverErrors.join('\n')).toEqual([]);
}

const num = (v) => Number(v ?? 0);
const money = (v) => (v === '' || v === null || v === undefined ? null : Number(v));

async function userFigures(page) {
    await page.goto(USER_PAGE);
    return {
        count: num(await page.locator('[data-my-ai-usage-month]').getAttribute('data-my-ai-usage-month-count')),
        generation: num(await page.locator('[data-my-ai-usage-nature="generation"]').getAttribute('data-my-ai-usage-nature-count')),
        search: num(await page.locator('[data-my-ai-usage-nature="embedding_query"]').getAttribute('data-my-ai-usage-nature-count')),
        unknown: num(await page.locator('[data-my-ai-usage-unknown]').getAttribute('data-my-ai-usage-unknown')),
        // CORRECTION M1 TASK-1257 : plus aucun montant en dollars cote membre (la tuile n'existe plus).
        knownCost: (await page.locator('[data-my-ai-usage-known-cost]').count()) ? 'TUILE-$-INATTENDUE' : 'aucun $ (membre)',
        searchLine: (await page.locator('[data-my-ai-usage-nature="embedding_query"]').innerText()).replace(/\s+/g, ' ').trim(),
        unknownCardTitle: (await page.locator('[data-my-ai-usage-unknown] > div').first().innerText()).trim(),
    };
}

async function orgFigures(page) {
    await page.goto(ORG_PAGE);
    return {
        searchLine: (await page.locator('[data-consumption-nature="embedding_query"]').innerText()).replace(/\s+/g, ' ').trim(),
        total: num(await page.locator('[data-consumption-breakdown]').getAttribute('data-consumption-total-count')),
        generation: num(await page.locator('[data-consumption-nature="generation"]').getAttribute('data-consumption-nature-count')),
        search: num(await page.locator('[data-consumption-nature="embedding_query"]').getAttribute('data-consumption-nature-count')),
        unknown: num(await page.locator('[data-consumption-economics-unknown]').getAttribute('data-consumption-economics-unknown')),
        consumed: (await page.locator('[data-consumption-budget-consumed]').innerText()).trim(),
    };
}

test.describe('TASK-1228 Economie visible', () => {
    test('parcours : invocation reelle -> releve User -> releve Organization -> agregat plateforme -> coherence -> inconnu -> autre Organization', async ({ page, browser }) => {
        test.setTimeout(180000);
        const watch = watchConsole(page);
        await login(page, ORG_SLUG, ADMIN_EMAIL);

        // Etat AVANT (pour prouver que l'invocation apparait bien).
        const userBefore = await userFigures(page);
        const orgBefore = await orgFigures(page);

        // 01 — une invocation REELLE : question aux Dossiers depuis une Boucle.
        await page.goto(LOOP);
        await page.locator('[data-knowledge-open]').first().click();
        await page.fill('#knowledge-question', QUESTION);
        await page.locator('[data-knowledge-dialog] form button[type="submit"]').click();
        await expect(page.locator('[data-knowledge-answer]')).not.toHaveText('', { timeout: 60000 });
        await page.waitForTimeout(500);
        await page.screenshot({ path: path.join(CAPTURES, '01-invocation-reelle-question-aux-dossiers.png'), fullPage: false });
        const answer = (await page.locator('[data-knowledge-answer]').innerText()).trim();
        expect(answer.length).toBeGreaterThan(0);

        // 02 — elle apparait dans SON releve utilisateur.
        const userAfter = await userFigures(page);
        expect(userAfter.count).toBeGreaterThan(userBefore.count);
        expect(userAfter.generation).toBe(userBefore.generation + 1);
        // La question a declenche une recherche documentaire (embedding) reelle, ledgeree.
        expect(userAfter.search).toBeGreaterThanOrEqual(userBefore.search + 1);
        await expect(page.locator('[data-my-ai-usage-row]').first()).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '02-releve-utilisateur.png'), fullPage: true });

        // 03 — dans le releve Organization, bonne categorie (generations +1, recherches +1).
        const orgAfter = await orgFigures(page);
        expect(orgAfter.generation).toBe(orgBefore.generation + 1);
        expect(orgAfter.search).toBeGreaterThanOrEqual(orgBefore.search + 1);
        await expect(page.locator('[data-consumption-budget-block]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '03-releve-organization-bonne-categorie.png'), fullPage: true });

        // 04 — dans l'agregat plateforme (compte SuperAdmin, second contexte).
        const platformContext = await browser.newContext();
        const platformPage = await platformContext.newPage();
        const platformWatch = watchConsole(platformPage);
        await login(platformPage, PLATFORM_ADMIN_ORG_SLUG, PLATFORM_ADMIN_EMAIL);
        const platformResponse = await platformPage.goto(PLATFORM_PAGE);
        expect(platformResponse?.status()).toBe(200);
        const orgRow = platformPage.locator('[data-platform-org]').filter({ hasText: 'ArtSciLab' }).first();
        await expect(orgRow).toBeVisible();
        await platformPage.screenshot({ path: path.join(CAPTURES, '04-agregat-plateforme.png'), fullPage: true });

        // 05 — les trois chiffres coherents entre eux.
        const platformKnownCost = money(await orgRow.getAttribute('data-platform-org-known-cost'));
        const platformUnknown = num(await orgRow.getAttribute('data-platform-org-unknown'));
        const orgConsumed = money(orgAfter.consumed.replace(/[$,]/g, ''));
        // Plateforme (ligne ArtSciLab) == releve Organization (consomme, inconnus).
        expect(platformKnownCost).not.toBeNull();
        expect(Math.abs(platformKnownCost - orgConsumed)).toBeLessThan(0.000001);
        expect(platformUnknown).toBe(orgAfter.unknown);
        // Utilisateur ⊆ Organization : Maya ne peut pas voir plus que l'Organization.
        expect(userAfter.count).toBeLessThanOrEqual(orgAfter.total);
        expect(userAfter.generation).toBeLessThanOrEqual(orgAfter.generation);
        // La carte generation plateforme >= generations de l'Organization.
        const platformGeneration = num(await platformPage.locator('[data-platform-card="generation"]').getAttribute('data-platform-card-value'));
        expect(platformGeneration).toBeGreaterThanOrEqual(orgAfter.generation);
        await orgRow.scrollIntoViewIfNeeded();
        await platformPage.screenshot({ path: path.join(CAPTURES, '05-coherence-ligne-organization-plateforme.png'), fullPage: false });
        // Chiffres bruts lus a l'ecran (pour index.md : il decrit l'ecran, pas l'inverse).
        fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify({ userBefore, userAfter, orgBefore, orgAfter, platformKnownCost, platformUnknown, platformGeneration }, null, 2));
        fs.writeFileSync(path.join(CAPTURES, '05-coherence-chiffres.txt'), [
            `Organization (releve admin) : consomme ${orgAfter.consumed}, generations ${orgAfter.generation}, recherches ${orgAfter.search}, inconnus ${orgAfter.unknown}, total ${orgAfter.total}`,
            `Plateforme (ligne ArtSciLab) : consomme $${platformKnownCost}, inconnus ${platformUnknown}, generations plateforme ${platformGeneration}`,
            `Utilisateur (Maya) : ${userAfter.count} utilisations, generations ${userAfter.generation}, recherches ${userAfter.search}, inconnus ${userAfter.unknown}, cout ${userAfter.knownCost}`,
            'Assertions : consomme(org) == consomme(plateforme) a 1e-6 ; inconnus(org) == inconnus(plateforme) ; user <= org ; plateforme >= org.',
        ].join('\n'));

        // 06 — un appel au cout non mesurable, visible COMME TEL (jamais 0).
        // Le banc en porte au moins un (recherche documentaire du 17/08 sans tarif).
        expect(userAfter.unknown).toBeGreaterThanOrEqual(1);
        expect(orgAfter.unknown).toBeGreaterThanOrEqual(1);
        await page.goto(USER_PAGE);
        const unknownCard = page.locator('[data-my-ai-usage-unknown]');
        await expect(unknownCard).toContainText(/non mesurable|unmeasurable/i);
        const unknownRow = page.locator('[data-my-ai-usage-row][data-my-ai-usage-cost-state="unknown"]').first();
        await expect(unknownRow).toBeVisible();
        // CORRECTION M1 TASK-1257 : la notion « non mesurable », jamais un montant.
        await expect(unknownRow).toContainText(/non mesurable|unmeasurable/i);
        await expect(unknownRow).not.toContainText('$0.0000000000');
        // Page entiere : la carte ambre « N appel(s) au cout non mesurable » ET la
        // ligne « — » (jamais $0) sur la meme capture.
        await page.screenshot({ path: path.join(CAPTURES, '06-appel-cout-non-mesurable-visible.png'), fullPage: true });
        await expect(platformPage.locator('[data-platform-card="unknown"]')).toHaveAttribute('data-platform-card-value', String(platformUnknown >= 1 ? platformUnknown : 0));

        assertClean(platformWatch);
        await platformContext.close();

        // 07 — depuis une autre Organization : rien de tout cela.
        const otherContext = await browser.newContext();
        const otherPage = await otherContext.newPage();
        await login(otherPage, OTHER_ORG_SLUG, OTHER_MEMBER_EMAIL);
        const own = await otherPage.goto(`/org/${OTHER_ORG_SLUG}/profile/ai-usage`);
        expect(own?.status()).toBe(200);
        const otherHtml = await otherPage.content();
        expect(otherHtml).not.toContain('ArtSciLab');
        expect(otherHtml).not.toContain('Maya');
        expect(otherHtml).not.toContain(orgAfter.consumed);
        // Releve Organization d'ArtSciLab : refuse.
        const forbidden = await otherPage.goto(ORG_PAGE);
        expect(forbidden?.status()).toBe(403);
        await otherPage.goto(`/org/${OTHER_ORG_SLUG}/profile/ai-usage`);
        await otherPage.screenshot({ path: path.join(CAPTURES, '07-autre-organization-rien-de-visible.png'), fullPage: true });
        // Agregat plateforme : refuse a un membre.
        const platformForbidden = await otherPage.goto(PLATFORM_PAGE);
        expect(platformForbidden?.status()).toBe(403);
        await otherContext.close();

        // Aucune cle, aucune donnee tenant etrangere sur les pages de Maya.
        await page.goto(ORG_PAGE);
        const html = await page.content();
        expect(html).not.toMatch(/sk-or-|sk-[a-z0-9]{10,}/i);
        expect(html).not.toMatch(/ai-validation-org|SENTINEL-(A|B)/i);

        assertClean(watch);
    });

    test('mobile 390x844 : releve utilisateur et releve Organization lisibles', async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
        const page = await context.newPage();
        const watch = watchConsole(page);
        await login(page, ORG_SLUG, ADMIN_EMAIL);

        const user = await page.goto(USER_PAGE);
        expect(user?.status()).toBe(200);
        await expect(page.locator('[data-my-ai-usage-month]')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)).toBe(false);
        await page.screenshot({ path: path.join(CAPTURES, '08-mobile-releve-utilisateur.png'), fullPage: true });

        const org = await page.goto(ORG_PAGE);
        expect(org?.status()).toBe(200);
        await expect(page.locator('[data-consumption-budget-block]')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)).toBe(false);
        await page.screenshot({ path: path.join(CAPTURES, '09-mobile-releve-organization.png'), fullPage: true });

        assertClean(watch);
        await context.close();
    });
});
