#!/usr/bin/env node
// TASK-1850 — reproduit le protocole de reconnexion demande par MASTER,
// sur un VRAI navigateur (Playwright), avec `window.Echo` REEL (pas le
// client Node de TASK-1848 — lui n'exerce jamais la reconnexion propre a
// pusher-js dans un navigateur). Utilise les comptes de benchmark deja
// semes par TASK-1848 (Task1848BenchmarkSeeder).
//
// Usage : node reconnection-test.mjs --feature=loop|direct|entraide|badge

import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const REVERB_SCRIPT = `${process.cwd()}/ai/scripts/reverb-local.sh`;

function parseArgs() {
    const args = Object.fromEntries(process.argv.slice(2).map((a) => {
        const [k, v] = a.replace(/^--/, '').split('=');
        return [k, v ?? true];
    }));
    return { feature: String(args.feature ?? 'direct'), baseUrl: String(args.baseUrl ?? 'https://test.laravel') };
}

const opts = parseArgs();
const fixtures = JSON.parse(readFileSync('storage/app/benchmarks/task-1848-fixtures.json', 'utf8'));

function reverbStop() { try { execSync(`${REVERB_SCRIPT} stop`, { stdio: 'pipe' }); } catch {} }
function reverbStart() {
    execSync(`nohup ${REVERB_SCRIPT} start > /tmp/reverb-1850-reconnect.log 2>&1 &`, { shell: '/bin/bash' });
}
function fireMessage(role, pair) {
    execSync(`php benchmarks/task-1848/fire-messages.php --role=${role} --pairs=${pair} --count=1 --intervalMs=100 --out=/tmp/fire-1850.jsonl`, { stdio: 'pipe' });
}

async function openPage(browser, userIndex, url) {
    const user = fixtures.users[userIndex];
    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();
    const log = [];
    page.on('console', (msg) => {
        const text = msg.text();
        if (text.startsWith('[recon]')) log.push({ t: Date.now(), text });
    });

    await page.goto(`${opts.baseUrl}/org/${fixtures.organization_slug}/login?org=${fixtures.organization_slug}`);
    await page.fill('input[name="email"]', user.email);
    await page.fill('input[name="password"]', fixtures.password);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

    await page.goto(url);
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

    // Instrumente window.Echo DES QUE PRET — logge chaque changement
    // d'etat de connexion pusher-js, horodate cote navigateur.
    await page.evaluate(() => {
        function hook() {
            if (!window.Echo) {
                window.addEventListener('bouclepro:echo-ready', hook, { once: true });
                return;
            }
            window.Echo.connector.pusher.connection.bind('state_change', (states) => {
                console.log(`[recon] ${states.previous} -> ${states.current}`);
            });
            console.log(`[recon] hooked, current=${window.Echo.connector.pusher.connection.state}`);
        }
        hook();
    });

    return { context, page, log, user };
}

const pairIndex = opts.feature === 'loop' ? null : (opts.feature === 'entraide' ? 1 : 0);
const pair = pairIndex !== null ? fixtures.pairs[pairIndex] : null;

function urlFor(feature, userIndex) {
    const org = fixtures.organization_slug;
    if (feature === 'loop') return `${opts.baseUrl}/org/${org}/loops/${fixtures.loop_id}`;
    if (feature === 'badge') return `${opts.baseUrl}/dashboard`;
    const txId = feature === 'direct' ? pair.direct_transaction_id : pair.entraide_transaction_id;
    return `${opts.baseUrl}/org/${org}/messages/${txId}`;
}

const observerIndex = opts.feature === 'loop' ? 4 : (opts.feature === 'entraide' ? pair.seller_index : pair.seller_index);
const url = urlFor(opts.feature, observerIndex);

console.log(`[reconnection-test] feature=${opts.feature} observer=u${observerIndex} url=${url}`);

const browser = await chromium.launch({ headless: true });
const observer = await openPage(browser, observerIndex, url);

async function waitForState(target, timeoutMs) {
    const start = Date.now();
    while (Date.now() - start < timeoutMs) {
        const state = await observer.page.evaluate(() => window.Echo?.connector?.pusher?.connection?.state ?? 'absent');
        if (state === target) return { ok: true, elapsed: Date.now() - start };
        await new Promise((r) => setTimeout(r, 300));
    }
    return { ok: false, elapsed: timeoutMs };
}

console.log('[reconnection-test] etape 1 : connexion normale');
let r = await waitForState('connected', 10000);
console.log(`  -> ${r.ok ? 'OK' : 'ECHEC'} en ${r.elapsed}ms`);

console.log('[reconnection-test] etape 2 : coupure Reverb');
reverbStop();
r = await waitForState('unavailable', 15000).catch(() => ({ ok: false }));
if (!r.ok) {
    // pusher-js passe parfois par 'connecting' en boucle plutot que
    // 'unavailable' selon le type d'echec (refus immediat vs timeout) —
    // verifie l'etat REEL plutot que de supposer un seul chemin.
    const state = await observer.page.evaluate(() => window.Echo?.connector?.pusher?.connection?.state);
    console.log(`  -> etat observe pendant la coupure : ${state}`);
} else {
    console.log(`  -> etat 'unavailable' atteint en ${r.elapsed}ms`);
}

if (opts.feature !== 'badge') {
    console.log('[reconnection-test] etape 3 : message envoye PENDANT la coupure');
    if (opts.feature === 'loop') {
        fireMessage('loop', 0);
    } else {
        fireMessage(opts.feature, pairIndex);
    }
    console.log('  -> envoye (chemin reel, catch(BroadcastException) attendu cote serveur)');
}

console.log('[reconnection-test] etape 4 : redemarrage Reverb');
reverbStart();

console.log('[reconnection-test] etape 5 : reconnexion automatique attendue');
r = await waitForState('connected', 20000);
console.log(`  -> reconnexion ${r.ok ? 'REUSSIE' : 'ECHOUEE (necessiterait un rechargement)'} en ${r.elapsed}ms`);

console.log('[reconnection-test] journal des transitions observees :');
for (const entry of observer.log) {
    console.log(`  ${entry.t} ${entry.text}`);
}

await observer.context.close();
await browser.close();
process.exit(r.ok ? 0 : 1);
