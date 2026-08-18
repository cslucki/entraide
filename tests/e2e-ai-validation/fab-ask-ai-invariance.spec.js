// TASK-1237 — le FAB « BouclePro IA » expose « Demander a l'IA » (loop_ask),
// migree canonique par TASK-1233. Preuve de surface REELLE sur le banc
// (127.0.0.1:8010, ArtSciLab Demo, develop + TASK-1237) que le FAB est un
// ROUTEUR : il ouvre le MEME formulaire que le bouton historique de
// loop-chat.blade.php, jamais une variante.
//
//   01 Jonas, Boucle Emergence : FAB ouvert -> « Demander a l'IA » proposee.
//   02 clic dessus -> le MEME modal que le bouton historique (#ai-question,
//      meme route loops.ai) s'ouvre, focus pose.
//   03 question soumise -> reponse publiee avec la doctrine ArtSciLab
//      appliquee, chaine mesuree identique a TASK-1233 (capability loop_ask,
//      process chatloop.ask, credential_source organization) : le FAB n'a
//      change ni le controle economique ni le contenu.
//   04 quota epuise (override Organization par Maya) : le FAB REMPLACE
//      l'action par le refus (comme les trois autres actions depuis
//      TASK-1231) — invariance mesuree, aucun appel.
//   Fin : override restaure. AUCUN mot de passe en clair (env AI_VALIDATION_PASSWORD).
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/fab-ask-ai-invariance.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1237');
fs.mkdirSync(CAPTURES, { recursive: true });

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const MAYA = 'maya@artscilab-demo.test';
const JONAS = 'jonas@artscilab-demo.test';
const LOOP_URL = `${ORG_ROOT}/loops/artscilab-emergence`;
const ORG_AI_PAGE = `${ORG_ROOT}/admin/ai`;
const QUESTION = 'Quel est le prochain jalon a documenter pour Emergence ?';

const figures = {};
const journal = [];
const note = (m) => { journal.push(`[${new Date().toISOString().slice(11, 19)}] ${m}`); console.log(journal.at(-1)); };

async function login(page, orgSlug, email) {
    await page.context().clearCookies();
    await page.goto(`/org/${orgSlug}/login`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.getByRole('button', { name: /sign in|se connecter/i }).click();
    await page.waitForURL((url) => url.pathname !== `/org/${orgSlug}/login`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(400);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'load' }),
        page.evaluate(() => {
            const token = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
            const form = document.createElement('form');
            form.method = 'POST'; form.action = '/locale/fr';
            for (const [name, value] of [['_token', token], ['redirect_to', window.location.href]]) {
                const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input);
            }
            document.body.appendChild(form); form.submit();
        }),
    ]);
}
function watchConsole(page) {
    const errors = []; const failed = []; const serverErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('requestfailed', (r) => {
        const navigationAbortedPoll = /\/livewire[^/]*\/update$/.test(new URL(r.url()).pathname) && r.failure()?.errorText === 'net::ERR_ABORTED';
        if (!/_boost\//.test(r.url()) && !navigationAbortedPoll) failed.push(`${r.method()} ${r.url()} :: ${r.failure()?.errorText}`);
    });
    page.on('response', (r) => { if (r.status() >= 500) serverErrors.push(`${r.status()} ${r.url()}`); });
    return { errors, failed, serverErrors };
}
function assertClean(w) {
    const rel = w.errors.filter((e) => !/favicon|_boost|Failed to send logs|the server responded with a status of 4\d\d/i.test(e));
    expect(rel, rel.join('\n')).toEqual([]);
    expect(w.failed, w.failed.join('\n')).toEqual([]);
    expect(w.serverErrors, w.serverErrors.join('\n')).toEqual([]);
}
function tinker(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: { ...process.env, APP_ENV: 'ai-validation' }, encoding: 'utf8' });
    const m = out.match(/\{[\s\S]*\}/);
    if (!m) throw new Error(`tinker: sortie inattendue: ${out}`);
    return JSON.parse(m[0]);
}
function counters(email) {
    return tinker(`
        $u = \\App\\Models\\User::where('email', '${email}')->firstOrFail();
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        $from = now()->startOfMonth();
        echo json_encode([
            'ledger' => \\App\\Models\\AiProviderInvocation::where('organization_id', $org->id)->where('user_id', $u->id)->where('created_at', '>=', $from)->count(),
            'interactions' => \\App\\Models\\AiInteraction::where('organization_id', $org->id)->where('user_id', $u->id)->where('created_at', '>=', $from)->count(),
            'credit_used' => app(\\App\\Support\\Ai\\AiEconomicGuard::class)->userCreditStatus($org, $u)->used,
        ]);`);
}
function chain(email, sinceIso) {
    return tinker(`
        $u = \\App\\Models\\User::where('email', '${email}')->firstOrFail();
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        $i = \\App\\Models\\AiInteraction::where('organization_id', $org->id)->where('user_id', $u->id)->where('created_at', '>=', '${sinceIso}')->orderByDesc('created_at')->first();
        $led = $i ? \\App\\Models\\AiProviderInvocation::where('correlation_id', $i->correlation_id)->get()->map(fn ($r) => ['operation'=>$r->operation,'capability'=>$r->capability,'provider'=>$r->provider,'model'=>$r->model,'credential_source'=>$r->credential_source,'status'=>$r->status])->all() : [];
        echo json_encode(['correlation_id'=>$i?->correlation_id,'feature'=>$i?->feature,'process'=>$i?->process,'metadata'=>$i?->metadata,'ledger'=>$led,'response'=>$i?->response]);`);
}
async function setOverride(page, mode, uses = null) {
    await page.goto(ORG_AI_PAGE);
    await page.locator(`[data-ai-user-credit-mode-input="${mode}"]`).check();
    if (mode === 'custom') await page.fill('[data-ai-user-credit-uses-input]', String(uses));
    await page.locator('[data-ai-user-credit-form] button[type="submit"]').click();
    await page.waitForURL((url) => url.pathname.endsWith('/admin/ai'));
    await expect(page.locator('[data-ai-settings-saved]')).toBeVisible();
}
async function openFab(page) {
    await page.locator('[data-ai-fab-toggle]').click();
    await expect(page.locator('[data-ai-fab-panel]')).toBeVisible();
}

test.describe('TASK-1237 FAB Demander a l IA invariance', () => {
    test('le FAB ouvre le meme formulaire canonique que le bouton historique, memes controles', async ({ page }) => {
        test.setTimeout(600000);
        const watch = watchConsole(page);

        // Etat de depart : reglage plateforme.
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'platform');

        // ── 01/02 Jonas : FAB -> « Demander a l'IA » -> le MEME modal ──────────
        await login(page, ORG_SLUG, JONAS);
        await page.goto(LOOP_URL);
        await openFab(page);
        await expect(page.locator('[data-ai-fab-action="loop_ask"]')).toBeVisible();
        await expect(page.locator('[data-ai-fab-action="loop_ask"]')).toContainText('Demander à l\'IA');
        await page.screenshot({ path: path.join(CAPTURES, '01-fab-panel-demander-a-l-ia.png') });

        await page.locator('[data-ai-fab-action="loop_ask"]').click();
        await expect(page.locator('#ai-question')).toBeVisible();
        await expect(page.locator('#ai-question')).toBeFocused();
        // Le formulaire ouvert est bien celui, unique, de la route canonique.
        expect(await page.locator('form[action$="/ask-ai"]').count()).toBe(1);
        await page.screenshot({ path: path.join(CAPTURES, '02-fab-ouvre-le-meme-modal.png') });
        note('01/02 FAB -> panneau contextuel propose « Demander à l\'IA » -> clic ouvre #ai-question (meme id, meme formulaire que le bouton historique loop-chat.blade.php)');

        // ── 03 Soumission : meme chaine canonique que TASK-1233 ─────────────────
        const before = counters(JONAS);
        const since = new Date().toISOString();
        await page.fill('#ai-question', QUESTION);
        await Promise.all([
            page.waitForURL((url) => url.pathname.endsWith('/loops/artscilab-emergence'), { timeout: 120000 }),
            page.locator('form[action$="/ask-ai"] button[type="submit"]').click(),
        ]);
        await page.waitForLoadState('load');
        await page.waitForTimeout(1500);
        const pageText = await page.locator('body').innerText();
        expect(pageText).toContain(QUESTION);
        await page.screenshot({ path: path.join(CAPTURES, '03-reponse-publiee-via-fab.png') });
        const after = counters(JONAS);
        const ch = chain(JONAS, since);
        expect(after.credit_used).toBe(before.credit_used + 1);
        expect(after.interactions).toBe(before.interactions + 1);
        expect(after.ledger).toBe(before.ledger + 1);
        expect(ch.feature).toBe('chatloop_ai_ask');
        expect(ch.process).toBe('chatloop.ask');
        expect(ch.metadata.capability).toBe('loop_ask');
        expect(ch.ledger.some((l) => l.operation === 'generation' && l.capability === 'loop_ask' && l.credential_source === 'organization')).toBe(true);
        note(`03 REPONSE via FAB — chaine identique a TASK-1233 (loop_ask, chatloop.ask, credential_source=organization) ; credit ${before.credit_used} -> ${after.credit_used} (+1)`);
        figures.ask_via_fab = { question: QUESTION, counters_before: before, counters_after: after, chain: ch };

        // ── 04 Plafond : le FAB remplace l'action par le refus, aucun appel ────
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'custom', after.credit_used);
        await login(page, ORG_SLUG, JONAS);
        const before4 = counters(JONAS);
        await page.goto(LOOP_URL);
        await openFab(page);
        await expect(page.locator('[data-ai-fab-action="loop_ask"]')).toHaveCount(0);
        await expect(page.locator('[data-ai-fab-refusal]')).toBeVisible();
        await expect(page.locator('[data-ai-fab-offers]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '04-fab-au-plafond-action-remplacee.png') });
        const after4 = counters(JONAS);
        expect(after4).toEqual(before4);
        note(`04 PLAFOND — FAB : loop_ask remplacee par le refus + « Voir les offres » (comme les trois autres actions depuis TASK-1231) ; invariance ledger ${before4.ledger}=${after4.ledger}, interactions ${before4.interactions}=${after4.interactions}, credit ${before4.credit_used}=${after4.credit_used}`);
        figures.cap = { counters_before: before4, counters_after: after4 };

        // ── Restauration ─────────────────────────────────────────────────────
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'platform');

        fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(figures, null, 2));
        fs.writeFileSync(path.join(CAPTURES, 'journal.txt'), journal.join('\n'));
        assertClean(watch);
    });

    test.afterEach(async ({ page }) => {
        const video = page.video();
        if (!video) return;
        await page.close();
        try { await video.saveAs(path.join(CAPTURES, 'fab-demander-a-l-ia.webm')); } catch (e) { /* pas de video */ }
    });
});
