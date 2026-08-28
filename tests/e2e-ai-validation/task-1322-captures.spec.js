// TASK-1322 (Core-2) — Degradation propre du ChatLoop quand l'IA n'est pas
// disponible. Captures + verifications sur le banc ai-validation (8010).
//
// Etats exerces :
//   - gate 1 OFF (AiConfig::clarification_enabled=0) : le modal « Qui peut
//     m'aider ? » degrade — message explicite + CTA « Preparer ma demande »
//     vers le formulaire canonique. Plus d'impasse.
//   - gate 1 ON + gate 2 OFF (AI_CLARIFY_ENABLED absent du banc) : le repli
//     deterministe est MARQUE « genere sans IA » — jamais presente comme une
//     reponse IA reelle.
//
// AUCUNE activation IA : le banc garde ai.clarify.enabled=false, aucun appel
// provider n'est possible. gate 1 est restaure a sa valeur initiale en fin de
// run (afterAll).
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/task-1322-captures.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const CAPTURES = path.resolve('_local/captures/TASK-1322');
fs.mkdirSync(path.join(CAPTURES, 'annexes'), { recursive: true });

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const LOOP = `${ORG_ROOT}/loops/artscilab-launchpals`;

const MAYA = 'maya@artscilab-demo.test';

const INTENTION = 'Je cherche des retours pour structurer une demande de mentorat autour de notre installation itinerante.';

function tinker(php) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', php], {
        env: { ...process.env, APP_ENV: 'ai-validation' },
        encoding: 'utf8',
    });
}

function setGate1(value) {
    tinker(`\\App\\Models\\AiConfig::set('clarification_enabled', '${value}'); echo 'gate1='.\\App\\Models\\AiConfig::get('clarification_enabled');`);
}

function readGate1() {
    const out = tinker(`echo 'GATE1[' . (string) \\App\\Models\\AiConfig::get('clarification_enabled', '') . ']';`);
    const match = out.match(/GATE1\[(.*)\]/);
    if (!match) throw new Error(`readGate1: sortie inattendue: ${out}`);
    return match[1];
}

function setMayaLocale(locale) {
    tinker(`$u = \\App\\Models\\User::where('email', '${MAYA}')->firstOrFail(); $u->preferred_locale = '${locale}'; $u->save(); echo 'locale='.$u->preferred_locale;`);
}

function readMayaLocale() {
    const out = tinker(`echo 'LOCALE[' . (string) (\\App\\Models\\User::where('email', '${MAYA}')->firstOrFail()->preferred_locale ?? '') . ']';`);
    const match = out.match(/LOCALE\[(.*)\]/);
    if (!match) throw new Error(`readMayaLocale: sortie inattendue: ${out}`);
    return match[1];
}

let initialGate1;
let initialLocale;

test.beforeAll(() => {
    initialGate1 = readGate1();
    // Captures en francais : la langue produit de reference.
    initialLocale = readMayaLocale();
    setMayaLocale('fr');
});

test.afterAll(() => {
    // Restauration systematique de l'etat initial du banc.
    setGate1(initialGate1);
    setMayaLocale(initialLocale);
});

async function login(page, email) {
    await page.context().clearCookies();
    await page.goto(`${ORG_ROOT}/login`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    // TASK-1247 : premier submit generique = bouton langue — cibler le form login.
    await page.locator('form[action*="/login"] button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
}

async function openHelpModal(page) {
    await page.locator('button', { hasText: "Qui peut m'aider ?" }).first().click();
}

test.describe('gate 1 OFF — degradation propre, poursuite manuelle', () => {
    test.beforeAll(() => setGate1('0'));

    test('desktop : le modal degrade et mene au formulaire canonique', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await login(page, MAYA);
        await page.goto(LOOP);

        // L'entree du parcours reste visible malgre le gate OFF.
        const trigger = page.locator('button', { hasText: "Qui peut m'aider ?" }).first();
        await expect(trigger).toBeVisible();

        await trigger.click();
        const notice = page.locator('[data-ai-unavailable]');
        await expect(notice).toBeVisible();
        const cta = page.locator('[data-prepare-manually]');
        await expect(cta).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '01-desktop-gate1-off-modal-degrade.png'), fullPage: false });

        // Poursuite manuelle : CTA -> formulaire canonique, aucune donnee inventee.
        await cta.click();
        await page.waitForURL((url) => url.pathname.endsWith('/requests/create'));
        await expect(page.locator('form[data-marketplace-validation] input[name="title"]')).toHaveValue('');
        await page.screenshot({ path: path.join(CAPTURES, '02-desktop-formulaire-canonique.png'), fullPage: false });
    });

    test('mobile : meme degradation via le menu du composeur', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await login(page, MAYA);
        await page.goto(LOOP);

        // Menu « + » du composeur mobile.
        await page.locator('button[aria-haspopup="true"]').first().click();
        const sheetItem = page.locator('button', { hasText: "Qui peut m'aider ?" }).last();
        await expect(sheetItem).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '03-mobile-sheet-qui-peut-maider.png'), fullPage: false });

        await sheetItem.click();
        await expect(page.locator('[data-ai-unavailable]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '04-mobile-modal-degrade.png'), fullPage: false });

        await page.locator('[data-prepare-manually]').click();
        await page.waitForURL((url) => url.pathname.endsWith('/requests/create'));
        await page.screenshot({ path: path.join(CAPTURES, '05-mobile-formulaire-canonique.png'), fullPage: false });
    });
});

test.describe('gate 1 ON + gate 2 OFF — le repli deterministe ne ment pas', () => {
    test.beforeAll(() => setGate1('1'));

    test('desktop : le resultat du repli est marque « genere sans IA »', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await login(page, MAYA);
        await page.goto(LOOP);

        await openHelpModal(page);
        const textarea = page.locator('textarea[name="intention"]');
        await expect(textarea).toBeVisible();
        await textarea.fill(INTENTION);
        await page.screenshot({ path: path.join(CAPTURES, '06-desktop-gate1-on-formulaire-intention.png'), fullPage: false });

        await page.locator('form[action*="help-request/analyze"] button[type="submit"]').click();

        // Ecran « Demande clarifiee » : contenu editable, parcours praticable,
        // et bandeau honnete — prepare sans IA.
        const banner = page.locator('[data-prepared-without-ai]');
        await expect(banner).toBeVisible();
        await expect(page.locator('form[action*="help-request/continue"]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '07-desktop-fallback-marque-sans-ia.png'), fullPage: false });
    });

    test('mobile : le bandeau « genere sans IA » reste lisible', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await login(page, MAYA);
        await page.goto(LOOP);

        await page.locator('button[aria-haspopup="true"]').first().click();
        await page.locator('button', { hasText: "Qui peut m'aider ?" }).last().click();
        const textarea = page.locator('textarea[name="intention"]');
        await expect(textarea).toBeVisible();
        await textarea.fill(INTENTION);
        await page.locator('form[action*="help-request/analyze"] button[type="submit"]').click();

        await expect(page.locator('[data-prepared-without-ai]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '08-mobile-fallback-marque-sans-ia.png'), fullPage: false });
    });
});
