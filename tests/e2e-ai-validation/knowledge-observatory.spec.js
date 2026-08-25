// TASK-1226 — Observatoire vivant des connaissances (Admin Organization).
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation, ArtSciLab Demo). Auto-contenu : login Maya
// (admin), Observatoire ouvert, cartes de perimetre, sources, auto-refresh
// SANS reload, ouverture d'une source autorisee, smoke 390x844, console
// propre. Le second test verifie qu'un admin d'une AUTRE Organization ne
// voit rien de l'ArtSciLab (tenant strict).
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/knowledge-observatory.spec.js

import { test, expect } from '@playwright/test';

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const ADMIN_EMAIL = 'maya@artscilab-demo.test';
const PASSWORD = 'password';
const OBSERVATORY = `${ORG_ROOT}/admin/ai-knowledge`;
const LIVE = `${ORG_ROOT}/admin/ai-knowledge/live`;

// Sur le banc, `admin@ai-validation-org-*` sont des admins PLATEFORME
// (`is_admin`), autorises par OrgAdminMiddleware sur toute Organization : ce
// n'est pas un cas cross-tenant. Le membre ordinaire de l'Organization B, lui,
// n'a aucun droit sur l'ArtSciLab.
const OTHER_ORG_SLUG = 'ai-validation-org-b';
const OTHER_MEMBER_EMAIL = 'member1@ai-validation-org-b.ai-validation.test';
const PLATFORM_ADMIN_ORG_SLUG = 'ai-validation-org-a';
const PLATFORM_ADMIN_EMAIL = 'admin@ai-validation-org-a.ai-validation.test';

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
        failed.push(`${request.method()} ${request.url()} :: ${request.failure()?.errorText}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });
    return { errors, failed, serverErrors };
}

test.describe('TASK-1226 Observatoire des connaissances', () => {
    test('desktop : cartes, perimetres, sources, auto-refresh sans reload, source ouvrable', async ({ page }) => {
        const watch = watchConsole(page);
        await login(page, ORG_SLUG, ADMIN_EMAIL);

        const response = await page.goto(OBSERVATORY);
        expect(response?.status()).toBe(200);

        // En-tete lisible par un non-technicien.
        await expect(page.locator('h1[data-knowledge-title]')).toBeVisible();
        const badge = page.locator('[data-knowledge-refresh-badge]');
        await expect(badge).toBeVisible();
        await expect(page.locator('[data-knowledge-last-checked]')).toContainText(/Dernière vérification|Last check/);
        await expect(page.locator('[data-knowledge-refresh-button]')).toBeVisible();

        // Cartes de perimetre : uniquement les espaces determinables.
        await expect(page.locator('[data-knowledge-perimeter="organization"]')).toBeVisible();
        await expect(page.locator('[data-knowledge-perimeter="loops"]')).toBeVisible();
        await expect(page.locator('[data-knowledge-perimeter="private"]')).toBeVisible();
        await expect(page.locator('[data-knowledge-perimeter="external"]')).toContainText(/Aucune source externe|No external source/);
        // ArtSciLab : les Dossiers appartiennent aux 5 Boucles de demo.
        await expect(page.locator('[data-knowledge-loop]')).toHaveCount(5);
        await expect(page.locator('[data-knowledge-perimeter="loops"]')).toContainText('LaunchPals');
        await expect(page.locator('[data-knowledge-perimeter="loops"]')).toContainText('Emergence');

        // Sources : etat reel, perimetre reel, aucun statut invente.
        const rows = page.locator('tr[data-source-key]');
        expect(await rows.count()).toBeGreaterThan(10);
        await expect(page.locator('[data-source-scope="loop"]').first()).toBeVisible();
        await expect(page.locator('[data-source-scope="loop_shared"]').first()).toBeVisible();
        const bodyText = await page.locator('[data-knowledge-live]').innerText();
        expect(bodyText).not.toMatch(/En attente|En cours|Échec|obsolète|pending|failed|stale/);

        // Auto-refresh : au moins deux polls du fragment, la page ne recharge pas.
        const initialGeneratedAt = await page.locator('[data-knowledge-generated-at]').getAttribute('data-knowledge-generated-at');
        await page.evaluate(() => { window.__task1226NoReload = true; });
        const firstPoll = await page.waitForResponse((r) => r.url().includes('/admin/ai-knowledge/live') && r.status() === 200, { timeout: 8000 });
        expect(firstPoll.headers()['cache-control']).toContain('no-store');
        await page.waitForResponse((r) => r.url().includes('/admin/ai-knowledge/live') && r.status() === 200, { timeout: 8000 });
        await expect.poll(async () => page.locator('[data-knowledge-generated-at]').getAttribute('data-knowledge-generated-at'), { timeout: 8000 })
            .not.toBe(initialGeneratedAt);
        expect(await page.evaluate(() => window.__task1226NoReload === true)).toBe(true);
        await expect(badge).toHaveAttribute('data-status', 'live');

        // Le fragment ne contient ni cle, ni chemin disque, ni contenu RAG.
        const liveHtml = await firstPoll.text();
        expect(liveHtml).not.toMatch(/sk-or-|sk-[a-z0-9]{10,}/i);
        expect(liveHtml).not.toContain('dossier-files/');

        // Bouton manuel.
        await page.locator('[data-knowledge-refresh-button]').click();
        await page.waitForResponse((r) => r.url().includes('/admin/ai-knowledge/live') && r.status() === 200, { timeout: 8000 });

        // Navigation vers une source AUTORISEE (Maya est membre des Boucles de demo).
        const openLink = page.locator('tr[data-source-key] a').first();
        await expect(openLink).toBeVisible();
        const href = await openLink.getAttribute('href');
        expect(href).toBeTruthy();
        const sourceResponse = await page.request.get(href);
        expect(sourceResponse.status()).toBeLessThan(400);

        // Aucune donnee tenant etrangere.
        expect(bodyText).not.toMatch(/ai-validation-org|SENTINEL-(A|B)/i);

        // Console / reseau propres.
        const relevantErrors = watch.errors.filter((e) => !/favicon/i.test(e));
        expect(relevantErrors, relevantErrors.join('\n')).toEqual([]);
        expect(watch.failed, watch.failed.join('\n')).toEqual([]);
        expect(watch.serverErrors, watch.serverErrors.join('\n')).toEqual([]);
    });

    test('responsive 390x844 : la page reste lisible et vivante', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        const watch = watchConsole(page);
        await login(page, ORG_SLUG, ADMIN_EMAIL);
        const response = await page.goto(OBSERVATORY);
        expect(response?.status()).toBe(200);
        await expect(page.locator('h1[data-knowledge-title]')).toBeVisible();
        await expect(page.locator('[data-knowledge-refresh-badge]')).toBeVisible();
        await expect(page.locator('[data-knowledge-perimeter="organization"]')).toBeVisible();
        // Pas de debordement horizontal de la page elle-meme.
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(1);
        await page.waitForResponse((r) => r.url().includes('/admin/ai-knowledge/live') && r.status() === 200, { timeout: 8000 });
        expect(watch.serverErrors, watch.serverErrors.join('\n')).toEqual([]);
        expect(watch.errors.filter((e) => !/favicon/i.test(e))).toEqual([]);
    });

    test('tenant : rien de l’ArtSciLab hors de l’ArtSciLab', async ({ page }) => {
        // Un membre ordinaire d'une autre Organization : 403 sur la console
        // ET sur le fragment de l'ArtSciLab.
        await login(page, OTHER_ORG_SLUG, OTHER_MEMBER_EMAIL);
        expect((await page.request.get(OBSERVATORY)).status()).toBe(403);
        expect((await page.request.get(LIVE)).status()).toBe(403);
        await page.context().clearCookies();

        // Un admin d'une autre Organization, sur SA console : aucune source,
        // aucun Dossier, aucune Boucle de l'ArtSciLab.
        await login(page, PLATFORM_ADMIN_ORG_SLUG, PLATFORM_ADMIN_EMAIL);
        const own = await page.goto(`/org/${PLATFORM_ADMIN_ORG_SLUG}/admin/ai-knowledge`);
        expect(own?.status()).toBe(200);
        const text = await page.locator('[data-knowledge-live]').innerText();
        expect(text).not.toMatch(/LaunchPals|Emergence|ArtSciLab|Smart Village|ROGER-SMART-VILLAGE/i);
        const ownLive = await page.request.get(`/org/${PLATFORM_ADMIN_ORG_SLUG}/admin/ai-knowledge/live`);
        expect(ownLive.status()).toBe(200);
        expect(await ownLive.text()).not.toMatch(/LaunchPals|Emergence|ArtSciLab|ROGER-SMART-VILLAGE/i);
    });
});
