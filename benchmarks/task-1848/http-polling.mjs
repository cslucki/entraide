#!/usr/bin/env node
// TASK-1848 — trafic HTTP/Livewire REEL (`wire:poll`), par de VRAIS onglets
// Chromium headless (Playwright, deja utilise par le depot), connexion
// authentifiee par le FORMULAIRE reel (pas un raccourci de session) —
// meme methode de mesure que TASK-1840 (comptage sur le log d'acces
// Apache), etendue a N onglets simultanes au lieu d'un seul.
//
// Usage :
//   node http-polling.mjs --count=10 --duration=60
//
// Chaque onglet reste au PREMIER PLAN de son propre contexte navigateur
// (jamais d'onglets partageant une fenetre) : le throttling de visibilite
// documente par TASK-1840 ne s'applique qu'a un onglet EN ARRIERE-PLAN
// d'une fenetre a onglets multiples, pas a des contextes headless
// independants.

import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

function parseArgs() {
    const args = Object.fromEntries(
        process.argv.slice(2).map((a) => {
            const [k, v] = a.replace(/^--/, '').split('=');
            return [k, v ?? true];
        })
    );
    return {
        count: Number(args.count ?? 10),
        duration: Number(args.duration ?? 60),
        baseUrl: String(args.baseUrl ?? process.env.BENCH_BASE_URL ?? 'https://test.laravel'),
        fixtures: String(args.fixtures ?? process.env.TASK1848_FIXTURES_PATH ?? 'storage/app/benchmarks/task-1848-fixtures.json'),
    };
}

const opts = parseArgs();
const fixtures = JSON.parse(readFileSync(opts.fixtures, 'utf8'));

const ROLES = ['loop', 'direct_conversation', 'entraide_conversation', 'idle_badges_only'];
const roleFor = (i) => ROLES[i % ROLES.length];
const pairFor = (i) => fixtures.pairs.find((p) => p.buyer_index === i || p.seller_index === i);

function urlFor(role, userIndex) {
    const org = fixtures.organization_slug;
    if (role === 'loop') {
        return `${opts.baseUrl}/org/${org}/loops/${fixtures.loop_id}`;
    }
    const pair = pairFor(userIndex);
    const transactionId = role === 'direct_conversation' ? pair?.direct_transaction_id : pair?.entraide_transaction_id;
    return `${opts.baseUrl}/org/${org}/messages/${transactionId}`;
}

async function openClient(browser, userIndex) {
    const user = fixtures.users[userIndex];
    const role = roleFor(userIndex);

    const context = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await context.newPage();

    // `?org=<slug>` deverrouille les champs : la porte d'une Organization
    // les verrouille par defaut des que PLUSIEURS Organizations existent
    // sur l'instance (vrai ici, jumeau PROD) et que ce parametre ne
    // confirme pas explicitement laquelle.
    await page.goto(`${opts.baseUrl}/org/${fixtures.organization_slug}/login?org=${fixtures.organization_slug}`);
    await page.fill('input[name="email"]', user.email);
    await page.fill('input[name="password"]', fixtures.password);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

    // `dashboard` (SANS prefixe) resout vers l'Organization PROPRE du membre
    // via `resolveFromAuthenticatedUser()` — seule route personnelle
    // authentifiee reconnue par `ResolveUrlOrganization` (TASK-1847) ; aucun
    // equivalent org-prefixe n'existe.
    const targetUrl = role === 'idle_badges_only'
        ? `${opts.baseUrl}/dashboard`
        : urlFor(role, userIndex);

    await page.goto(targetUrl);
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});

    return { context, page, label: `u${userIndex}:${role}` };
}

console.log(`[http-polling] count=${opts.count} duration=${opts.duration}s`);
const browser = await chromium.launch({ headless: true });

const t0 = Date.now();
const clients = [];
// Ouverture CONCURRENTE (pas sequentielle) : chaque login/navigation est
// borne par le reseau/le serveur, pas par ce process Node — la parallelisation
// ne degrade rien ici et rend n=20+ praticable en un temps raisonnable.
const opened = await Promise.allSettled(
    Array.from({ length: opts.count }, (_, i) => openClient(browser, i))
);
for (const [i, result] of opened.entries()) {
    if (result.status === 'fulfilled') {
        clients.push(result.value);
    } else {
        console.error(`[http-polling] echec client u${i}: ${result.reason?.message ?? result.reason}`);
    }
}
console.log(`[http-polling] ${clients.length}/${opts.count} onglets ouverts et abonnes (${Date.now() - t0}ms).`);
console.log(`[http-polling] WINDOW_START ${new Date().toISOString()}`);

await new Promise((resolve) => setTimeout(resolve, opts.duration * 1000));

console.log(`[http-polling] WINDOW_END ${new Date().toISOString()}`);

for (const c of clients) {
    await c.context.close();
}
await browser.close();
console.log('[http-polling] termine, tous les contextes fermes.');
