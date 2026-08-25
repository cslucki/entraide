import { test, expect } from '@playwright/test';
import { login } from '../../ai/playwright/helpers/auth.js';

const BASE_URL = 'http://127.0.0.1:8000';
const PASSWORD = 'password';

async function dossierBench(browser) {
    const ownerContext = await browser.newContext({ baseURL: BASE_URL });
    const ownerPage = await ownerContext.newPage();
    await login(ownerPage, 'admin@bouclepro.test', PASSWORD);
    await ownerPage.goto('/org/main/dossiers');

    const sharedHref = await ownerPage.getByRole('link', { name: /Dossier test 3/i }).first().getAttribute('href');
    const siblingHref = await ownerPage.getByRole('link', { name: /Test Cyril/i }).first().getAttribute('href');
    const driveState = await ownerPage.locator('[x-data^="dossierFilesCard"]').first().getAttribute('x-data');
    const personalRootId = driveState?.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i)?.[0];

    await ownerContext.close();

    expect(sharedHref).toBeTruthy();
    expect(siblingHref).toBeTruthy();
    expect(personalRootId).toBeTruthy();

    return {
        sharedHref,
        siblingHref,
        personalRootHref: `/org/main/dossiers/${personalRootId}`,
    };
}

for (const viewport of [
    { label: 'desktop', width: 1280, height: 800 },
    { label: 'mobile', width: 390, height: 844 },
]) {
    test(`TASK-1140 shared reader surface — ${viewport.label}`, async ({ browser }) => {
        const bench = await dossierBench(browser);
        const context = await browser.newContext({ baseURL: BASE_URL, viewport });
        const page = await context.newPage();
        const consoleErrors = [];
        page.on('console', message => {
            if (message.type() === 'error') consoleErrors.push(message.text());
        });

        await login(page, 'main.member1@bouclepro.test', PASSWORD);
        await page.goto('/org/main/dossiers?espace=partages');

        const sharedRow = page.getByRole('link', { name: /Dossier test 3/i }).first().locator('..');
        await expect(sharedRow).toContainText(/Admin/i);
        await expect(sharedRow).not.toContainText(/Utilisateur désactivé/i);

        // Deep link: no Referer and no `espace` query parameter.
        await page.goto(bench.sharedHref);
        await expect(page).toHaveURL(new RegExp(`${bench.sharedHref.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));

        const breadcrumb = page.getByRole('navigation', { name: 'Breadcrumb' });
        await expect(breadcrumb).toContainText(/Partagés\s*Dossier test 3/i);
        await expect(breadcrumb).not.toContainText(/Mes documents/i);
        await expect(page.locator('[aria-current="page"]:visible').filter({ hasText: /Partagés/i }).first()).toBeVisible();

        const breadcrumbLinks = breadcrumb.getByRole('link');
        for (let index = 0; index < await breadcrumbLinks.count(); index += 1) {
            const box = await breadcrumbLinks.nth(index).boundingBox();
            expect(box?.height).toBeGreaterThanOrEqual(44);
            const response = await context.request.get(await breadcrumbLinks.nth(index).getAttribute('href'));
            expect(response.status()).toBe(200);
        }

        expect((await page.evaluate(() => document.documentElement.scrollWidth)) <= viewport.width).toBe(true);
        expect(consoleErrors).toEqual([]);

        expect((await page.goto(bench.personalRootHref)).status()).toBe(403);
        expect((await page.goto(bench.siblingHref)).status()).toBe(403);
        expect((await page.goto(bench.sharedHref)).status()).toBe(200);
        expect(consoleErrors.every(message => message.includes('403 (Forbidden)'))).toBe(true);

        await context.close();
    });
}
