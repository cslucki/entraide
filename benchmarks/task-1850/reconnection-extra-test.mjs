#!/usr/bin/env node
// TASK-1850 — deux scenarios supplementaires du mandat, non couverts par
// reconnection-test.mjs : navigation pendant la coupure, et retour d'un
// onglet mis en arriere-plan (Page Visibility API) pendant la coupure.

import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const REVERB_SCRIPT = `${process.cwd()}/ai/scripts/reverb-local.sh`;
function reverbStop() { try { execSync(`${REVERB_SCRIPT} stop`, { stdio: 'pipe' }); } catch {} }
function reverbStart() { execSync(`nohup ${REVERB_SCRIPT} start > /tmp/reverb-1850-extra.log 2>&1 &`, { shell: '/bin/bash' }); }

const fixtures = JSON.parse(readFileSync('storage/app/benchmarks/task-1848-fixtures.json', 'utf8'));
const pair = fixtures.pairs[0];
const org = fixtures.organization_slug;

async function login(page, userIndex) {
    const user = fixtures.users[userIndex];
    await page.goto(`https://test.laravel/org/${org}/login?org=${org}`);
    await page.fill('input[name="email"]', user.email);
    await page.fill('input[name="password"]', fixtures.password);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
}

async function waitForState(page, target, timeoutMs) {
    const start = Date.now();
    while (Date.now() - start < timeoutMs) {
        const state = await page.evaluate(() => window.Echo?.connector?.pusher?.connection?.state ?? 'absent');
        if (state === target) return { ok: true, elapsed: Date.now() - start };
        await new Promise((r) => setTimeout(r, 300));
    }
    return { ok: false };
}

const browser = await chromium.launch({ headless: true });

console.log('=== Scenario A : navigation pendant la coupure ===');
{
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

    await login(page, pair.seller_index);
    await page.goto(`https://test.laravel/org/${org}/messages/${pair.direct_transaction_id}`);
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    console.log('connecte sur la conversation, etat initial:', await page.evaluate(() => window.Echo?.connector?.pusher?.connection?.state));

    reverbStop();
    await new Promise((r) => setTimeout(r, 2000));

    console.log('navigation vers le tableau de bord PENDANT la coupure...');
    await page.goto('https://test.laravel/dashboard');
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    console.log('page chargee sans exception :', errors.length === 0 ? 'OK' : `ERREURS: ${JSON.stringify(errors)}`);

    reverbStart();
    const r = await waitForState(page, 'connected', 20000);
    console.log(`reconnexion apres navigation : ${r.ok ? 'OK' : 'ECHEC'}`);
    console.log('erreurs JS accumulees sur tout le scenario :', errors.length);

    await context.close();
}

console.log('\n=== Scenario B : retour d\'un onglet mis en arriere-plan pendant la coupure ===');
{
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await login(page, pair.buyer_index);
    await page.goto(`https://test.laravel/org/${org}/messages/${pair.direct_transaction_id}`);
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

    reverbStop();
    await new Promise((r) => setTimeout(r, 1000));

    console.log('mise en arriere-plan (Page Visibility API)...');
    await page.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await new Promise((r) => setTimeout(r, 2000));

    reverbStart();
    await new Promise((r) => setTimeout(r, 1000));

    console.log('retour au premier plan...');
    await page.evaluate(() => {
        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true });
        document.dispatchEvent(new Event('visibilitychange'));
    });

    const r = await waitForState(page, 'connected', 20000);
    console.log(`reconnexion apres retour au premier plan : ${r.ok ? 'OK' : 'ECHEC'}`);
    console.log('erreurs JS :', errors.length);

    await context.close();
}

await browser.close();
