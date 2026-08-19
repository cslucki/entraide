// TASK-1258 — Releve Organization IA V2 (console Admin Organization « Consommation IA »).
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation). Comptes ArtSciLab absents du banc (pack retire
// T1243/T1245) : parcours sur org-a / org-b (admins de banc = admins plateforme,
// acceptes par OrgAdminMiddleware — l'isolation tenant est prouvee par les tests
// Feature TASK1219/TASK1258, pas par ce compte), en FR.
//
//   01 admin org-a, desktop : « Fonctions les plus consommatrices » en langage
//      produit (somme = ligne Generations), filtre « Fonction » en langage
//      produit (valeur = cle), « Par fonction » sans cle visible, colonne
//      « Echecs » par utilisateur, vocabulaire « Cout fournisseur mesure »
//      (montant $ conserve), note de limites corrigee (tracee, non ventilee).
//   02 Filtre ?process=<cle> : le detail se restreint, le bloc d'autorite reste
//      org-wide (fonctions inchangees), l'option selectionnee affiche le libelle.
//   03 Mobile 390x844.
//   04 admin org-b : rien de l'org A (aucune fonction, aucun montant de A).
//   05 EN : « Measured provider cost », « By function », « Failures », phrase
//      « traced ».
//
// LIVRABLE VISUEL : PNG 01..05 + videos dans _local/captures/TASK-1258/.
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/ai-organization-statement-v2.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

test.use({ video: 'on', screenshot: 'on', serviceWorkers: 'block' });

const CAPTURES = path.resolve('_local/captures/TASK-1258');
fs.mkdirSync(CAPTURES, { recursive: true });

const PASSWORD = 'password';
const ORG_A = 'ai-validation-org-a';
const ORG_B = 'ai-validation-org-b';
const ADMIN_A = 'admin@ai-validation-org-a.ai-validation.test';
const ADMIN_B = 'admin@ai-validation-org-b.ai-validation.test';
const consolePage = (slug) => `/org/${slug}/admin/ai-consumption`;

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
const lower = (s) => clean(s).toLowerCase();

async function figures(page, slug, query = '') {
    await page.goto(consolePage(slug) + query);
    await page.waitForLoadState('load');
    const functions = {};
    const rows = page.locator('[data-consumption-top-process]');
    for (let i = 0; i < (await rows.count()); i++) {
        const r = rows.nth(i);
        functions[await r.getAttribute('data-consumption-top-process')] = {
            count: num(await r.getAttribute('data-consumption-top-process-count')),
            label: clean(await r.locator('td').first().innerText()),
        };
    }
    const natureFailed = {};
    const natures = page.locator('[data-consumption-nature]');
    for (let i = 0; i < (await natures.count()); i++) {
        const n = natures.nth(i);
        natureFailed[await n.getAttribute('data-consumption-nature')] = await n.getAttribute('data-consumption-nature-failed');
    }
    const processOptions = await page.locator('select#process option').evaluateAll((opts) => opts.map((o) => ({ value: o.value, text: o.textContent.trim(), selected: o.selected })));
    return {
        generation: num(await page.locator('[data-consumption-nature="generation"]').getAttribute('data-consumption-nature-count')),
        functions,
        natureFailed,
        processOptions,
        topProcessesText: clean(await page.locator('[data-consumption-top-processes]').innerText()),
        detailTraces: num(clean(await page.locator('[data-consumption-traces]').innerText()).replace(/\D/g, '')),
        bodyText: clean(await page.locator('main').innerText().catch(() => page.evaluate(() => document.body.innerText))),
    };
}

const report = {};

test.describe.configure({ mode: 'serial' });

test('01 — admin org-a : fonctions produit, echecs, vocabulaire fournisseur, note corrigee', async ({ page }) => {
    const watch = watchConsole(page);
    await login(page, ORG_A, ADMIN_A);
    const f = await figures(page, ORG_A);
    report.orgA = f;

    // Fonctions en langage produit, somme = ligne Generations, aucune cle dans le texte.
    expect(Object.keys(f.functions).length).toBeGreaterThan(0);
    const sum = Object.values(f.functions).reduce((a, b) => a + b.count, 0);
    expect(sum).toBe(f.generation);
    for (const [key, row] of Object.entries(f.functions)) {
        expect(row.label).not.toBe(key);
        expect(f.topProcessesText).not.toContain(key);
    }
    expect(lower(f.bodyText)).toContain('fonctions les plus consommatrices');
    // Filtre « Fonction » : options en langage produit, valeur = cle.
    for (const opt of f.processOptions.filter((o) => o.value !== '')) {
        expect(opt.text).not.toBe(opt.value);
    }
    // Vocabulaire fournisseur, montant conserve.
    expect(lower(f.bodyText)).toContain('coût fournisseur mesuré');
    expect(lower(f.bodyText)).toContain('budget fournisseur du mois');
    expect(f.bodyText).toMatch(/\$\d/);
    // Echecs : colonne presente ; attribut par nature documentaire.
    expect(lower(f.bodyText)).toContain('échecs');
    expect(f.natureFailed['embedding_query']).not.toBeNull();
    expect(f.natureFailed['generation']).toBeNull();
    // Note de limites corrigee.
    expect(f.bodyText).not.toContain("n'est pas traçable");
    expect(f.bodyText).toContain('est tracée dans le registre canonique');
    expect(f.bodyText).toContain('ne la ventile pas encore');

    await page.screenshot({ path: path.join(CAPTURES, '01-admin-a-console-v2-desktop.png'), fullPage: true });
    assertClean(watch);
});

test('02 — filtre ?process=<cle> : detail restreint, bloc d autorite org-wide, libelle selectionne', async ({ page }) => {
    const watch = watchConsole(page);
    await login(page, ORG_A, ADMIN_A);
    const all = await figures(page, ORG_A);
    const keys = Object.keys(all.functions);
    expect(keys.length).toBeGreaterThan(1);
    const key = keys[keys.length - 1];
    const filtered = await figures(page, ORG_A, `?process=${encodeURIComponent(key)}`);
    report.filtered = { key, detailTraces: filtered.detailTraces, functions: filtered.functions };
    // Le detail 1219 se restreint a la fonction ; le bloc d'autorite reste toute l'Organization.
    expect(filtered.detailTraces).toBe(all.functions[key].count);
    expect(filtered.functions).toEqual(all.functions);
    expect(filtered.generation).toBe(all.generation);
    expect(lower(filtered.bodyText)).toContain('budget et ventilation : toute l');
    const selected = filtered.processOptions.find((o) => o.selected);
    expect(selected.value).toBe(key);
    expect(selected.text).toBe(all.functions[key].label);
    await page.screenshot({ path: path.join(CAPTURES, '02-admin-a-filtre-fonction.png'), fullPage: true });
    assertClean(watch);
});

test('03 — mobile 390x844', async ({ browser }) => {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', baseURL: 'http://127.0.0.1:8010' });
    const page = await context.newPage();
    const watch = watchConsole(page);
    await login(page, ORG_A, ADMIN_A);
    await page.goto(consolePage(ORG_A));
    await page.waitForLoadState('load');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    await expect(page.locator('[data-consumption-top-processes]')).toBeVisible();
    await page.screenshot({ path: path.join(CAPTURES, '03-admin-a-mobile.png'), fullPage: true });
    assertClean(watch);
    await context.close();
});

test('04 — admin org-b : rien de l org A', async ({ page }) => {
    const watch = watchConsole(page);
    await login(page, ORG_B, ADMIN_B);
    const f = await figures(page, ORG_B);
    report.orgB = f;
    for (const key of Object.keys(report.orgA?.functions || {})) {
        expect(f.functions[key]).toBeUndefined();
    }
    expect(f.bodyText).not.toContain('SENTINEL-A Member One');
    await page.screenshot({ path: path.join(CAPTURES, '04-admin-b-isolation.png'), fullPage: true });
    assertClean(watch);
});

test('05 — EN : libelles alignes', async ({ page }) => {
    const watch = watchConsole(page);
    await login(page, ORG_A, ADMIN_A);
    await switchLocale(page, 'en');
    await page.goto(consolePage(ORG_A));
    const body = clean(await page.locator('main').innerText().catch(() => page.evaluate(() => document.body.innerText)));
    expect(lower(body)).toContain('measured provider cost');
    expect(lower(body)).toContain('by function');
    expect(lower(body)).toContain('failures');
    expect(body).toContain('is traced in the canonical invocation ledger');
    expect(body).not.toContain('is not traceable');
    await page.screenshot({ path: path.join(CAPTURES, '05-admin-a-en.png'), fullPage: true });
    fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(report, null, 2));
    assertClean(watch);
});
