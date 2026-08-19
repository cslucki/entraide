// TASK-1257 — Releve utilisateur IA V2 (« Mes usages IA »).
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation). Les comptes ArtSciLab (Maya/Jonas) n'existent plus
// sur le banc (pack retire T1243/T1245) : parcours sur org-a, en FR.
//
//   01 member1 (generations Blog Explorer reelles du 19/08) : la page V2 —
//      credit en utilisations, AUCUN montant en dollars sur la page
//      (correction M1 : le cout fournisseur en $ n'appartient qu'aux surfaces
//      Admin / SuperAdmin ; la colonne « Cout fournisseur » ne porte que la
//      notion mesure / non mesurable / non evalue), « Par categorie d'usage »
//      sous « Generations » (sommes egales), colonne Statut = « Reussi »
//      (empruntee au ledger de la meme correlation — ces lignes Blog IA n'ont
//      pas de metadata.status).
//   02 Une VRAIE generation de note Blog IA sur l'article de member1
//      (blog.explorer_note, cle plateforme T1248, ~0.0003 $) -> une DEUXIEME
//      categorie apparait, « Generations » et « Ce mois » +1, credit +1.
//      (« Demander a l'IA » ChatLoop refuse sur l'org A : aucun credential
//      d'Organization — refus sans trace, verifie.)
//   03 member2 (aucun usage) : pas de bloc categories, « — » jamais 0.
//   04 Mobile 390x844 (member1).
//   05 Isolation : member1 de l'org B ne voit rien de l'org A.
//   06 EN : libelles en anglais, aucun $.
//
// LIVRABLE VISUEL : PNG 01..06 + video dans _local/captures/TASK-1257/.
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/ai-user-statement-v2.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

test.use({ video: 'on', screenshot: 'on', serviceWorkers: 'block' });

const CAPTURES = path.resolve('_local/captures/TASK-1257');
fs.mkdirSync(CAPTURES, { recursive: true });

const PASSWORD = 'password';
const ORG_A = 'ai-validation-org-a';
const ORG_B = 'ai-validation-org-b';
const MEMBER1_A = 'member1@ai-validation-org-a.ai-validation.test';
const MEMBER2_A = 'member2@ai-validation-org-a.ai-validation.test';
const MEMBER1_B = 'member1@ai-validation-org-b.ai-validation.test';
const POST_A = `/org/${ORG_A}/blog/t1248-explorer-preuve-economique`;
const userPage = (slug) => `/org/${slug}/profile/ai-usage`;

async function login(page, orgSlug, email) {
    await page.context().clearCookies();
    await page.goto(`/org/${orgSlug}/login`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.getByRole('button', { name: /sign in|se connecter/i }).click();
    await page.waitForURL((url) => url.pathname !== `/org/${orgSlug}/login`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(400);
    await switchLocale(page, 'fr');
}

// La locale est de SESSION (POST /locale/{code}) : a refaire apres chaque connexion.
async function switchLocale(page, locale) {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'load' }),
        page.evaluate((code) => {
            const token = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `/locale/${code}`;
            for (const [name, value] of [['_token', token], ['redirect_to', window.location.href]]) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }, locale),
    ]);
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
    const relevantErrors = watch.errors.filter((e) => !/favicon|_boost|Failed to send logs|the server responded with a status of 4\d\d/i.test(e));
    expect(relevantErrors, relevantErrors.join('\n')).toEqual([]);
    expect(watch.failed, watch.failed.join('\n')).toEqual([]);
    expect(watch.serverErrors, watch.serverErrors.join('\n')).toEqual([]);
}

const num = (v) => Number(v ?? 0);
const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();
// Les titres de tuiles sont en CSS `uppercase` : comparer en minuscules.
const lower = (s) => clean(s).toLowerCase();

async function figures(page, slug) {
    await page.goto(userPage(slug));
    await page.waitForLoadState('load');
    const card = page.locator('[data-my-ai-credit]');
    const categories = page.locator('[data-my-ai-usage-category]');
    const cats = {};
    for (let i = 0; i < (await categories.count()); i++) {
        const c = categories.nth(i);
        cats[await c.getAttribute('data-my-ai-usage-category')] = num(await c.getAttribute('data-my-ai-usage-category-count'));
    }
    const statuses = [];
    const rows = page.locator('[data-my-ai-usage-row]');
    for (let i = 0; i < (await rows.count()); i++) {
        statuses.push(clean(await rows.nth(i).locator('td').last().innerText()));
    }
    return {
        creditUsed: num(await card.getAttribute('data-my-ai-credit-used')),
        creditCardText: clean(await card.innerText()),
        monthCount: num(await page.locator('[data-my-ai-usage-month]').getAttribute('data-my-ai-usage-month-count')),
        generation: num(await page.locator('[data-my-ai-usage-nature="generation"]').getAttribute('data-my-ai-usage-nature-count')),
        categories: cats,
        rowCount: await rows.count(),
        statuses,
        bodyText: clean(await page.locator('main').innerText().catch(() => page.evaluate(() => document.body.innerText))),
    };
}

const report = {};

test.describe.configure({ mode: 'serial' });

test('01 — member1 : V2 (categories, cout fournisseur, statut par ligne, credit en utilisations)', async ({ page }) => {
    const watch = watchConsole(page);
    await login(page, ORG_A, MEMBER1_A);
    const f = await figures(page, ORG_A);
    report.before = f;

    // Credit : en utilisations ; AUCUN montant en dollars sur toute la page
    // (correction M1 — inverse T1228) ; la notion de mesure reste.
    expect(f.creditCardText).not.toContain('$');
    expect(f.creditCardText).toMatch(/utilisations?/);
    expect(f.bodyText).not.toMatch(/\$\s?\d/);
    expect(lower(f.bodyText)).toContain('coût fournisseur');
    expect(f.bodyText).toContain('Mesuré');
    // Categories : sous « Generations », la somme EST la ligne.
    const sum = Object.values(f.categories).reduce((a, b) => a + b, 0);
    expect(Object.keys(f.categories).length).toBeGreaterThan(0);
    expect(sum).toBe(f.generation);
    expect(lower(f.bodyText)).toContain("par catégorie d'usage");
    // Statut : les lignes Blog IA (sans metadata.status) lisent « Reussi » depuis le ledger.
    expect(f.rowCount).toBeGreaterThan(0);
    expect(f.statuses.every((s) => s === 'Réussi')).toBeTruthy();

    await page.screenshot({ path: path.join(CAPTURES, '01-member1-v2-desktop.png'), fullPage: true });
    assertClean(watch);
});

test('02 — une VRAIE generation de note Blog IA (blog.explorer_note) -> deuxieme categorie, +1 partout', async ({ page }) => {
    const watch = watchConsole(page);
    await login(page, ORG_A, MEMBER1_A);
    const before = await figures(page, ORG_A);

    // Le chemin ChatLoop (« Demander a l'IA ») exige un credential d'Organization
    // que l'org A du banc n'a pas (refus « IA non configuree », aucune ligne) ;
    // l'Explorer d'article (T1248) passe par la cle plateforme : c'est lui qu'on
    // sollicite, sur l'article de member1, via l'endpoint REEL de generation de
    // note (meme contrat que le bouton « Generer une note » du deep-chat).
    await page.goto(POST_A);
    await page.waitForLoadState('load');
    const result = await page.evaluate(async (url) => {
        const token = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ messages: [
                { role: 'user', text: 'TASK-1257 : quelle est la difference entre le throttle et la garde economique dans cet article ?' },
                { role: 'assistant', text: 'Le throttle limite la frequence des requetes ; la garde economique controle le budget, le credit et refuse avant le fournisseur.' },
            ] }),
        });
        let body = null;
        try { body = await response.json(); } catch (e) { body = null; }
        return { status: response.status, body };
    }, `${POST_A}/explorer/note`);
    report.noteCall = { status: result.status, length: result.body?.length ?? null, error: result.body?.error ?? null, code: result.body?.code ?? null };
    // 200 (note) ou 422 « note trop courte/longue » : dans les deux cas l'appel
    // fournisseur a eu lieu et a ete trace ; un 429 (refus economique) n'aurait
    // rien trace — il ferait echouer les comptes ci-dessous, a dessein.
    expect([200, 422]).toContain(result.status);

    const after = await figures(page, ORG_A);
    report.after = after;
    expect(after.generation).toBe(before.generation + 1);
    expect(after.monthCount).toBe(before.monthCount + 1);
    expect(after.creditUsed).toBe(before.creditUsed + 1);
    // Rejouable : la categorie « Note Blog IA » apparait au premier passage (+1
    // categorie) et s'incremente ensuite — dans les deux cas la somme reste la ligne.
    expect(Object.keys(after.categories).length).toBeGreaterThanOrEqual(Object.keys(before.categories).length);
    expect(Object.keys(after.categories)).toContain('blog.explorer_note');
    expect(after.categories['blog.explorer_note']).toBe((before.categories['blog.explorer_note'] || 0) + 1);
    expect(after.bodyText).toContain('Note Blog IA');
    const sum = Object.values(after.categories).reduce((a, b) => a + b, 0);
    expect(sum).toBe(after.generation);
    // La nouvelle ligne (sans metadata.status, comme tout Blog IA) lit « Reussi » depuis le ledger.
    expect(after.statuses[0]).toBe('Réussi');

    await page.screenshot({ path: path.join(CAPTURES, '02-member1-two-categories.png'), fullPage: true });
    assertClean(watch);
});

test('03 — member2 (aucun usage) : pas de bloc categories, « — » jamais 0', async ({ page }) => {
    const watch = watchConsole(page);
    await login(page, ORG_A, MEMBER2_A);
    await page.goto(userPage(ORG_A));
    await expect(page.locator('[data-my-ai-usage-categories]')).toHaveCount(0);
    await expect(page.locator('[data-my-ai-usage-known-cost]')).toHaveCount(0);
    expect(clean(await page.locator('main').innerText())).not.toMatch(/\$\s?\d/);
    await expect(page.locator('[data-my-ai-usage-empty]')).toBeVisible();
    await page.screenshot({ path: path.join(CAPTURES, '03-member2-empty.png'), fullPage: true });
    assertClean(watch);
});

test('04 — mobile 390x844 (member1)', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', baseURL: 'http://127.0.0.1:8010' });
    const page = await context.newPage();
    const watch = watchConsole(page);
    await login(page, ORG_A, MEMBER1_A);
    await page.goto(userPage(ORG_A));
    await page.waitForLoadState('load');
    // Aucun debordement horizontal du document (le tableau defile dans son conteneur).
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    await expect(page.locator('[data-my-ai-usage-categories]')).toBeVisible();
    await page.screenshot({ path: path.join(CAPTURES, '04-member1-mobile.png'), fullPage: true });
    assertClean(watch);
    await context.close();
});

test('05 — isolation : un membre de l org B ne voit rien de l org A', async ({ page }) => {
    const watch = watchConsole(page);
    await login(page, ORG_B, MEMBER1_B);
    const f = await figures(page, ORG_B);
    expect(f.rowCount).toBe(0);
    expect(Object.keys(f.categories)).toEqual([]);
    expect(f.bodyText).not.toContain('Dialogue Blog IA');
    expect(f.bodyText).not.toContain("Question à l'IA dans ChatLoop");
    // L'URL de l'org A avec la session de l'org B : le releve reste le SIEN (son Organization).
    const g = await figures(page, ORG_A).catch(() => null);
    if (g) {
        expect(g.rowCount).toBe(0);
        expect(Object.keys(g.categories)).toEqual([]);
    }
    await page.screenshot({ path: path.join(CAPTURES, '05-orgb-isolation.png'), fullPage: true });
    assertClean(watch);
});

test('06 — EN : libelles alignes', async ({ page }) => {
    const watch = watchConsole(page);
    await login(page, ORG_A, MEMBER1_A);
    await switchLocale(page, 'en');
    await page.goto(userPage(ORG_A));
    const body = clean(await page.locator('main').innerText().catch(() => page.evaluate(() => document.body.innerText)));
    expect(lower(body)).toContain('provider cost');
    expect(body).toContain('Measured');
    expect(body).not.toMatch(/\$\s?\d/);
    expect(lower(body)).toContain('by usage category');
    await page.screenshot({ path: path.join(CAPTURES, '06-member1-en.png'), fullPage: true });
    fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(report, null, 2));
    assertClean(watch);
});
