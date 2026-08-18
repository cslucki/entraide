// TASK-1231 — FAB « BouclePro IA » : point d'entree unique et contextuel.
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation, ArtSciLab Demo). Script d'acceptance IMPOSE, dans
// l'ordre, en FR, avec de VRAIES invocations (question aux Dossiers,
// clarification, resume) :
//
//   01 page Boucle (LaunchPals, Jonas), FAB ferme.
//   02 clic sur le FAB BouclePro IA.
//   03 le menu contextuel ouvert.
//   04 le credit restant visible.
//   05 clic sur « Consulter les Dossiers ».
//   06 le VRAI modal « Consulter les Dossiers » existant.
//   07 la reponse sourcee.
//   08 retour au FAB -> « Creer ou clarifier une demande d'aide » : la
//      clarification (08a) ; « Resumer la Boucle » : la Card resume (08b).
//   09 quota PROCHE DU SEUIL : le FAB en ambre, actions toujours proposees.
//   10 quota EPUISE : le FAB montre le refus + « Voir les offres », et RIEN
//      n'est appele — INVARIANCE mesuree (invocations provider N -> N,
//      ai_interactions A -> A, credit utilise X -> X).
//   11 mobile 390x844 : aucune collision avec le FAB « + » existant.
//   12 lot 0 : au quota epuise, le chemin herite « Demander a l'IA » est
//      refuse AVANT le provider — invariance mesuree, flash + « Voir les
//      offres » dans le bandeau existant.
//   Fin : override remis sur « reglage plateforme » (banc SAFE).
//
// LIVRABLE VISUEL : PNG 01..12 + video dans _local/captures/TASK-1231/ (ecran
// REEL, aucun montage). `test.use({ video, screenshot })` ICI seulement.
// AUCUN mot de passe en clair : credential de banc (env AI_VALIDATION_PASSWORD).
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/ai-fab-contextuel.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1231');
fs.mkdirSync(path.join(CAPTURES, 'annexes'), { recursive: true });

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const LOOP = `${ORG_ROOT}/loops/artscilab-launchpals`;
const LOOP_WITH_SUMMARY = `${ORG_ROOT}/loops/artscilab-emergence`;
const USER_PAGE = `${ORG_ROOT}/profile/ai-usage`;
const ORG_AI_PAGE = `${ORG_ROOT}/admin/ai`;

const MAYA = 'maya@artscilab-demo.test';
const JONAS = 'jonas@artscilab-demo.test';

const QUESTION = 'Que doit contenir une installation itinérante qui tient dans une valise ?';
const INTENTION = 'Je cherche quelqu\'un pour relire le dossier de financement européen de notre installation itinérante avant vendredi.';

const figures = {};

async function login(page, orgSlug, email) {
    await page.context().clearCookies();
    await page.goto(`/org/${orgSlug}/login`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.getByRole('button', { name: /sign in|se connecter/i }).click();
    await page.waitForURL((url) => url.pathname !== `/org/${orgSlug}/login`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(400);
    await switchToFrench(page);
}

async function switchToFrench(page) {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'load' }),
        page.evaluate(() => {
            const token = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/locale/fr';
            for (const [name, value] of [['_token', token], ['redirect_to', window.location.href]]) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }),
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
        // Un poll Livewire de la Boucle avorte par NOTRE navigation (page.goto
        // pendant un wire:poll en vol) est un ERR_ABORTED de navigation, pas
        // une erreur applicative : exclu du releve. Tout autre echec compte.
        const navigationAbortedPoll = /\/livewire[^/]*\/update$/.test(new URL(request.url()).pathname) && request.failure()?.errorText === 'net::ERR_ABORTED';
        if (!/_boost\//.test(request.url()) && !navigationAbortedPoll) failed.push(`${request.method()} ${request.url()} :: ${request.failure()?.errorText}`);
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

// Compteurs MESURES en base (banc bouclepro_ai_validation, APP_ENV=ai-validation),
// bornes a l'utilisateur, a l'Organization et au mois courant : invocations
// provider (ledger canonique), traces ai_interactions, credit utilise (autorite
// AiEconomicGuard). La preuve d'un refus est l'INVARIANCE de ces trois nombres.
function dbCounters(email) {
    const php = `
        $u = \\App\\Models\\User::where('email', '${email}')->firstOrFail();
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        $from = now()->startOfMonth();
        echo json_encode([
            'ledger' => \\App\\Models\\AiProviderInvocation::where('organization_id', $org->id)->where('user_id', $u->id)->where('created_at', '>=', $from)->count(),
            'interactions' => \\App\\Models\\AiInteraction::where('organization_id', $org->id)->where('user_id', $u->id)->where('created_at', '>=', $from)->count(),
            'credit_used' => app(\\App\\Support\\Ai\\AiEconomicGuard::class)->userCreditStatus($org, $u)->used,
        ]);
    `;
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
        env: { ...process.env, APP_ENV: 'ai-validation' },
        encoding: 'utf8',
    });
    const match = out.match(/\{.*\}/);
    if (!match) throw new Error(`dbCounters: sortie inattendue: ${out}`);
    return JSON.parse(match[0]);
}

async function creditFigures(page) {
    await page.goto(USER_PAGE);
    const card = page.locator('[data-my-ai-credit]');
    return {
        state: await card.getAttribute('data-my-ai-credit-state'),
        used: num(await card.getAttribute('data-my-ai-credit-used')),
        quota: (await card.getAttribute('data-my-ai-credit-quota')) || null,
        remaining: (await card.getAttribute('data-my-ai-credit-remaining')) || null,
        monthCount: num(await page.locator('[data-my-ai-usage-month]').getAttribute('data-my-ai-usage-month-count')),
    };
}

async function setOverride(page, mode, uses = null) {
    await page.goto(ORG_AI_PAGE);
    await page.locator(`[data-ai-user-credit-mode-input="${mode}"]`).check();
    if (mode === 'custom') {
        await page.fill('[data-ai-user-credit-uses-input]', String(uses));
    }
    await page.locator('[data-ai-user-credit-form] button[type="submit"]').click();
    await page.waitForURL((url) => url.pathname.endsWith('/admin/ai'));
    await expect(page.locator('[data-ai-settings-saved]')).toBeVisible();
}

async function fabContext(page) {
    return page.evaluate(() => {
        const el = document.querySelector('[data-ai-fab]');
        return {
            page: el?.getAttribute('data-ai-fab-page'),
            tone: el?.getAttribute('data-ai-fab-tone'),
            open: el?.getAttribute('data-ai-fab-open'),
            actions: Array.from(document.querySelectorAll('[data-ai-fab-action]')).map((b) => b.getAttribute('data-ai-fab-action')),
            creditLabel: document.querySelector('[data-ai-fab-credit-label]')?.textContent.trim(),
            hasRefusal: !!document.querySelector('[data-ai-fab-refusal]'),
            hasOffers: !!document.querySelector('[data-ai-fab-offers]'),
            usageHref: document.querySelector('[data-ai-fab-usage]')?.getAttribute('href'),
        };
    });
}

async function openFab(page) {
    await page.locator('[data-ai-fab-toggle]').click();
    await expect(page.locator('[data-ai-fab-panel]')).toBeVisible();
}

test.describe('TASK-1231 FAB BouclePro IA', () => {
    test('parcours : Boucle -> FAB -> menu -> credit -> Consulter les Dossiers -> reponse sourcee -> clarification -> resume -> ambre -> epuise sans appel -> lot 0 herite refuse', async ({ page }) => {
        test.setTimeout(600000);
        const watch = watchConsole(page);

        // Etat de depart : Jonas sur le reglage plateforme (aucun override).
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'platform');

        // ── 01 page Boucle, FAB ferme ────────────────────────────────────────
        await login(page, ORG_SLUG, JONAS);
        await page.goto(LOOP);
        await expect(page.locator('[data-ai-fab-toggle]')).toBeVisible();
        await expect(page.locator('[data-ai-fab-panel]')).toBeHidden();
        const start = await fabContext(page);
        expect(start.page).toBe('loop');
        expect(start.open).toBe('false');
        await page.waitForTimeout(2500);
        await page.screenshot({ path: path.join(CAPTURES, '01-page-boucle-fab-ferme.png'), fullPage: false });
        figures.start = { fab: start, jonas: dbCounters(JONAS) };

        // ── 02 clic sur le FAB ───────────────────────────────────────────────
        await page.locator('[data-ai-fab-toggle]').hover();
        await page.screenshot({ path: path.join(CAPTURES, '02-clic-sur-le-fab.png'), fullPage: false });
        await openFab(page);

        // ── 03 menu contextuel ouvert ────────────────────────────────────────
        const menu = await fabContext(page);
        expect(menu.open).toBe('true');
        expect(menu.actions).toContain('loop_knowledge');
        expect(menu.actions).toContain('help_request');
        expect(menu.actions).not.toContain('ask');
        // « Demander a l'IA » n'est jamais dans le FAB.
        expect(await page.locator('[data-ai-fab-panel]').innerText()).not.toMatch(/Demander à l'IA/);
        await page.waitForTimeout(3000);
        await page.screenshot({ path: path.join(CAPTURES, '03-menu-contextuel-ouvert.png'), fullPage: false });
        figures.menu = menu;

        // ── 04 credit restant visible ────────────────────────────────────────
        await expect(page.locator('[data-ai-fab-credit-label]')).toBeVisible();
        expect(menu.creditLabel).toMatch(/restante|Inclus/);
        expect(menu.tone).toBe('ok');
        await page.locator('[data-ai-fab-credit]').screenshot({ path: path.join(CAPTURES, '04-credit-restant-visible.png') });
        figures.credit = { label: menu.creditLabel, tone: menu.tone, usage_href: menu.usageHref };

        // ── 05 clic sur « Consulter les Dossiers » ───────────────────────────
        await page.locator('[data-ai-fab-action="loop_knowledge"]').hover();
        await page.screenshot({ path: path.join(CAPTURES, '05-clic-consulter-les-dossiers.png'), fullPage: false });
        await page.locator('[data-ai-fab-action="loop_knowledge"]').click();

        // ── 06 le VRAI modal existant ────────────────────────────────────────
        await expect(page.locator('[data-knowledge-dialog]')).toBeVisible();
        await expect(page.locator('[data-ai-fab-panel]')).toBeHidden();
        await expect(page.locator('#knowledge-question')).toBeFocused();
        await page.waitForTimeout(2000);
        await page.screenshot({ path: path.join(CAPTURES, '06-vrai-modal-consulter-les-dossiers.png'), fullPage: false });

        // ── 07 la reponse sourcee (appel REEL) ───────────────────────────────
        const before07 = dbCounters(JONAS);
        await page.fill('#knowledge-question', QUESTION);
        const responsePromise = page.waitForResponse((r) => /\/knowledge$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 90000 });
        await page.locator('[data-knowledge-dialog] form button[type="submit"]').click();
        const response = await responsePromise;
        const payload = await response.json();
        expect(response.status(), JSON.stringify(payload)).toBe(200);
        await expect(page.locator('[data-knowledge-answer]')).not.toHaveText('');
        await expect(page.locator('[data-knowledge-sources]')).toBeVisible();
        await page.waitForTimeout(4000);
        await page.screenshot({ path: path.join(CAPTURES, '07-reponse-sourcee.png'), fullPage: false });
        const after07 = dbCounters(JONAS);
        figures.knowledge = { grounded: payload.grounded, sources: (payload.sources || []).length, credit: payload.credit, counters_before: before07, counters_after: after07 };
        // La reponse est passee par la capability canonique : elle est comptee (1 recherche + 1 generation).
        expect(after07.credit_used).toBeGreaterThan(before07.credit_used);
        await page.keyboard.press('Escape');

        // ── 08 retour au FAB, 09 « Resumer la Boucle » : la Card existante ───
        // Le FAB suit la COMPOSITION de la Boucle : LaunchPals ne porte pas la
        // Card resume (le FAB ne la propose donc pas) ; Emergence la porte.
        await page.goto(LOOP);
        await openFab(page);
        const menuAfterKnowledge = await fabContext(page);
        expect(menuAfterKnowledge.actions).not.toContain('loop_summary');
        await page.waitForTimeout(2000);
        await page.screenshot({ path: path.join(CAPTURES, '08-retour-au-fab.png'), fullPage: false });
        await page.goto(LOOP_WITH_SUMMARY);
        await openFab(page);
        const menuEmergence = await fabContext(page);
        expect(menuEmergence.actions).toContain('loop_summary');
        await page.waitForTimeout(2000);
        await page.screenshot({ path: path.join(CAPTURES, 'annexes/resumer-la-boucle-menu-emergence.png'), fullPage: false });
        await page.locator('[data-ai-fab-action="loop_summary"]').click();
        await expect(page.locator('[data-ai-fab-panel]')).toBeHidden();
        // La Card resume est ouverte dans le panneau du workspace : generation REELLE.
        const generate = page.locator('button[wire\\:click="generate"]').first();
        await expect(generate).toBeVisible({ timeout: 15000 });
        const beforeSum = dbCounters(JONAS);
        const summaryUpdate = page.waitForResponse((r) => /\/livewire[^/]*\/update$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 90000 });
        await generate.click();
        await summaryUpdate;
        await page.waitForTimeout(6000);
        await expect(page.locator('[data-ai-summary-error]')).toHaveCount(0);
        await page.screenshot({ path: path.join(CAPTURES, '09-resumer-la-boucle.png'), fullPage: false });
        const afterSum = dbCounters(JONAS);
        expect(afterSum.credit_used).toBe(beforeSum.credit_used + 1);
        figures.summary = { loop: 'artscilab-emergence', actions: menuEmergence.actions, counters_before: beforeSum, counters_after: afterSum };
        await page.goto(LOOP);

        // ── 10 clarification d'une demande d'aide (REELLE) ───────────────────
        await page.goto(LOOP);
        await openFab(page);
        await page.locator('[data-ai-fab-action="help_request"]').click();
        await expect(page.locator('#intention')).toBeVisible();
        await page.fill('#intention', INTENTION);
        const before08 = dbCounters(JONAS);
        await Promise.all([
            page.waitForURL((url) => url.pathname.endsWith('/loops/artscilab-launchpals'), { timeout: 90000 }),
            page.locator('form[action$="/help-request/analyze"] button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('load');
        await expect(page.getByText(/Votre demande clarifiée/i).first()).toBeVisible({ timeout: 30000 });
        await page.waitForTimeout(4000);
        await page.screenshot({ path: path.join(CAPTURES, '10-clarification-demande-aide.png'), fullPage: false });
        const after08 = dbCounters(JONAS);
        figures.clarify = { counters_before: before08, counters_after: after08 };
        await page.keyboard.press('Escape');

        // ── 11 quota PROCHE DU SEUIL : ambre, actions toujours la ─────────────
        const jonasNow = dbCounters(JONAS);
        // Quota de demonstration : utilisations actuelles + 4 -> ratio >= 80 %,
        // pas encore epuise.
        const alertQuota = jonasNow.credit_used + 4;
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'custom', alertQuota);
        await login(page, ORG_SLUG, JONAS);
        await page.goto(LOOP);
        await openFab(page);
        const amber = await fabContext(page);
        expect(amber.tone).toBe('alert');
        expect(amber.hasRefusal).toBe(false);
        expect(amber.actions).toContain('loop_knowledge');
        await expect(page.locator('[data-ai-fab-alert]')).toBeVisible();
        await expect(page.locator('[data-ai-fab-badge]')).toBeVisible();
        await page.waitForTimeout(3000);
        await page.screenshot({ path: path.join(CAPTURES, '11-seuil-ambre-avant-epuisement.png'), fullPage: false });
        figures.alert = { quota: alertQuota, fab: amber };

        // ── 12 quota EPUISE : refus + « Voir les offres », RIEN d'appele ─────
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'custom', jonasNow.credit_used);
        await login(page, ORG_SLUG, JONAS);
        const before10 = dbCounters(JONAS);
        const ui10 = await creditFigures(page);
        await page.goto(LOOP);
        await openFab(page);
        const capped = await fabContext(page);
        expect(capped.tone).toBe('exhausted');
        expect(capped.hasRefusal).toBe(true);
        expect(capped.hasOffers).toBe(true);
        expect(capped.actions).toEqual([]);
        await expect(page.locator('[data-ai-fab-refusal]')).toContainText(/crédit IA du mois est épuisé/);
        await page.waitForTimeout(3500);
        await page.screenshot({ path: path.join(CAPTURES, '12-quota-epuise-voir-les-offres-aucune-invocation.png'), fullPage: false });
        await page.locator('[data-ai-fab-offers]').click();
        await page.waitForURL((url) => url.pathname.endsWith('/profile/ai-usage/offers'));
        await expect(page.locator('[data-ai-offers]')).toBeVisible();
        const after10 = dbCounters(JONAS);
        const ui10b = await creditFigures(page);
        // INVARIANCE : rien n'a ete appele ni ecrit.
        expect(after10).toEqual(before10);
        expect(ui10b.monthCount).toBe(ui10.monthCount);
        expect(ui10b.used).toBe(ui10.used);
        figures.cap = { quota: jonasNow.credit_used, fab: capped, counters_before: before10, counters_after: after10, ui_before: ui10, ui_after: ui10b };
        fs.writeFileSync(path.join(CAPTURES, '12-preuve-invariance-fab-au-plafond.txt'), [
            'TASK-1231 — preuve 12 : FAB au plafond, « Voir les offres », AUCUN appel.',
            `Utilisateur : ${JONAS} — Organization : ${ORG_SLUG} — mois courant.`,
            `Quota de demonstration (override Organization) : ${jonasNow.credit_used}.`,
            `AVANT : invocations provider (ledger) = ${before10.ledger}, ai_interactions = ${before10.interactions}, credit utilise = ${before10.credit_used}, releve utilisateur (UI) = ${ui10.monthCount}.`,
            'ACTION : ouverture du FAB (refus + « Voir les offres »), clic sur « Voir les offres ».',
            `APRES : invocations provider (ledger) = ${after10.ledger}, ai_interactions = ${after10.interactions}, credit utilise = ${after10.credit_used}, releve utilisateur (UI) = ${ui10b.monthCount}.`,
            'INVARIANCE : identiques, strictement.',
        ].join('\n'));

        // ── Annexe lot 0 : chemin herite « Demander a l'IA » refuse AVANT provider ─
        // Le POST est emis depuis une page SANS poll Livewire (Mes usages IA),
        // puis la Boucle est rechargee : le bandeau existant porte le refus.
        await page.goto(USER_PAGE);
        const before12 = dbCounters(JONAS);
        const csrf = await page.evaluate(() => document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '');
        const legacy = await page.request.post(`${LOOP}/ask-ai`, {
            form: { _token: csrf, action: 'ask', question: 'Quel budget prévoir pour la valise ?' },
            maxRedirects: 0,
        });
        expect(legacy.status()).toBe(302);
        await page.goto(LOOP);
        const banner = page.locator('[data-ai-refusal-code]').first();
        await expect(banner).toBeVisible();
        await expect(banner).toContainText(/crédit IA du mois est épuisé/);
        await expect(banner.locator('[data-ai-offers-link]')).toBeVisible();
        await page.waitForTimeout(3000);
        await page.screenshot({ path: path.join(CAPTURES, 'annexes/lot0-demander-a-l-ia-refuse-avant-provider.png'), fullPage: false });
        const after12 = dbCounters(JONAS);
        expect(after12).toEqual(before12);
        // Aucune question orpheline publiee dans la Boucle par le chemin refuse.
        expect(await page.getByText('Quel budget prévoir pour la valise ?').count()).toBe(0);
        figures.legacy = { status: legacy.status(), counters_before: before12, counters_after: after12 };
        fs.writeFileSync(path.join(CAPTURES, 'annexes/lot0-preuve-invariance-chemin-herite.txt'), [
            'TASK-1231 — annexe lot 0 : « Demander a l\'IA » (chemin herite ask/answer) au quota epuise.',
            `Utilisateur : ${JONAS} — Organization : ${ORG_SLUG} — mois courant.`,
            `AVANT : invocations provider (ledger) = ${before12.ledger}, ai_interactions = ${before12.interactions}, credit utilise = ${before12.credit_used}.`,
            `ACTION : POST ${LOOP}/ask-ai (action=ask) -> HTTP ${legacy.status()} (redirection vers la Boucle, bandeau « credit epuise » + « Voir les offres »).`,
            `APRES : invocations provider (ledger) = ${after12.ledger}, ai_interactions = ${after12.interactions}, credit utilise = ${after12.credit_used}.`,
            'INVARIANCE : identiques, strictement — zero invocation provider supplementaire, zero ligne de ledger, aucun credit decompte, aucune question publiee.',
        ].join('\n'));

        // ── Fin : restauration (banc SAFE) ────────────────────────────────────
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'platform');
        figures.restored = { override: 'platform' };

        fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(figures, null, 2));
        assertClean(watch);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const video = page.video();
        if (!video || testInfo.title.startsWith('mobile')) return;
        await page.close();
        const target = path.join(CAPTURES, 'parcours-fab-contextuel.webm');
        try { await video.saveAs(target); } catch (e) { /* video absente : rien a copier */ }
    });

    test('mobile 390x844 : le FAB BouclePro IA coexiste avec le FAB « + » et le composeur', async ({ browser }) => {
        test.setTimeout(180000);
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
        const page = await context.newPage();
        const watch = watchConsole(page);
        const overlap = (a, b) => !!(a && b && a.x < b.x + b.width && a.x + a.width > b.x && a.y < b.y + b.height && a.y + a.height > b.y);
        await login(page, ORG_SLUG, JONAS);

        // (a) Une page ou le FAB « + » existe : la liste des Boucles.
        await page.goto(`${ORG_ROOT}/loops`);
        const ai = page.locator('[data-ai-fab-toggle]');
        const plus = page.locator('button[aria-label="Ajouter"]');
        await expect(ai).toBeVisible();
        await expect(plus).toBeVisible();
        const aiBox = await ai.boundingBox();
        const plusBox = await plus.boundingBox();
        expect(overlap(aiBox, plusBox), `FAB IA ${JSON.stringify(aiBox)} vs + ${JSON.stringify(plusBox)}`).toBe(false);
        expect(aiBox.y + aiBox.height).toBeLessThan(844 - 56);
        await page.screenshot({ path: path.join(CAPTURES, 'annexes/mobile-liste-boucles-fab-ia-et-fab-plus-fermes.png'), fullPage: false });
        await ai.tap();
        await expect(page.locator('[data-ai-fab-panel]')).toBeVisible();
        const panelBox = await page.locator('[data-ai-fab-panel]').boundingBox();
        expect(panelBox.x).toBeGreaterThanOrEqual(0);
        expect(panelBox.x + panelBox.width).toBeLessThanOrEqual(390.5);
        expect(overlap(panelBox, plusBox), 'le panneau ne couvre pas le FAB « + »').toBe(false);
        await page.screenshot({ path: path.join(CAPTURES, '13-mobile-390x844-sans-collision.png'), fullPage: false });

        // (b) La Boucle : le FAB « + » y est masque par la page ; le FAB IA ne
        //     couvre ni la rangee des actions IA ni le composeur.
        await page.goto(LOOP);
        await expect(ai).toBeVisible();
        const aiLoopBox = await ai.boundingBox();
        const knowledgeChip = await page.locator('[data-knowledge-open]').first().boundingBox();
        const composerSend = await page.locator('form button[type="submit"]:has(svg)').last().boundingBox();
        expect(overlap(aiLoopBox, knowledgeChip), `FAB IA ${JSON.stringify(aiLoopBox)} vs chip ${JSON.stringify(knowledgeChip)}`).toBe(false);
        expect(overlap(aiLoopBox, composerSend), `FAB IA ${JSON.stringify(aiLoopBox)} vs envoi ${JSON.stringify(composerSend)}`).toBe(false);
        await page.screenshot({ path: path.join(CAPTURES, 'annexes/mobile-boucle-fab-ia-ferme.png'), fullPage: false });
        await ai.tap();
        await expect(page.locator('[data-ai-fab-panel]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, 'annexes/mobile-boucle-menu-ouvert.png'), fullPage: false });

        const mobileFigures = JSON.parse(fs.readFileSync(path.join(CAPTURES, 'figures.json'), 'utf8'));
        mobileFigures.mobile = { viewport: '390x844', loops_index: { ai_fab: aiBox, plus_fab: plusBox, panel: panelBox, overlap: false }, loop_page: { ai_fab: aiLoopBox, knowledge_chip: knowledgeChip, composer_send: composerSend, overlap: false } };
        fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(mobileFigures, null, 2));
        assertClean(watch);
        await context.close();
    });
});
