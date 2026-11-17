#!/usr/bin/env node
// TASK-1850 — verifie EMPIRIQUEMENT si `chatloop-adaptive-polling.js`
// reduit reellement 3s -> 20s une fois abonne, en mesurant l'ecart REEL
// entre deux requetes `/livewire-*/update` consecutives sur la page
// Boucle, flags ON, Reverb reel.

import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const fixtures = JSON.parse(readFileSync('storage/app/benchmarks/task-1848-fixtures.json', 'utf8'));
const org = fixtures.organization_slug;

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await context.newPage();

const pollTimes = [];
page.on('request', (req) => {
    if (req.method() === 'POST' && /\/livewire-.*\/update/.test(req.url())) {
        pollTimes.push(Date.now());
    }
});

const user = fixtures.users[0];
await page.goto(`https://test.laravel/org/${org}/login?org=${org}`);
await page.fill('input[name="email"]', user.email);
await page.fill('input[name="password"]', fixtures.password);
await page.click('button[type="submit"], input[type="submit"]');
await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

await page.goto(`https://test.laravel/org/${org}/loops/${fixtures.loop_id}`);
await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

console.log('page chargee, surveillance 50s...');
const t0 = Date.now();
await new Promise((r) => setTimeout(r, 50000));

console.log(`\n${pollTimes.length} requetes livewire/update observees sur 50s :`);
let prev = t0;
for (const t of pollTimes) {
    console.log(`  +${((t - t0) / 1000).toFixed(1)}s (ecart depuis la precedente: ${((t - prev) / 1000).toFixed(1)}s)`);
    prev = t;
}

await context.close();
await browser.close();
