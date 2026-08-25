// TASK-1233 — « Demander a l'IA » dans le systeme nerveux canonique : preuve de
// surface REELLE sur le banc (127.0.0.1:8010, ArtSciLab Demo, develop + TASK-1233).
//
//   01 Jonas, Boucle Emergence (doctrine ArtSciLab active v2) : « Demander a
//      l'IA » (ask) -> le message IA publie porte la phrase de cloture de la
//      doctrine — ce qui n'apparaissait PAS avant la migration.
//   02 La chaine, mesuree en base : ai_interactions capability=loop_ask,
//      ledger generation credential_source=organization, provenance loop.messages.
//   03 Quota epuise (override Organization pose par Maya) : le meme bouton ->
//      refus AVANT provider, bandeau existant + « Voir les offres », invariance
//      mesuree (ledger, interactions, credit), aucune question publiee.
//   04 « Comportement IA » (Maya) : la couverture — chatloop_direct_answer n'est
//      plus « herite », loop_answer / loop_ask sont couvertes.
//   Fin : override restaure. AUCUN mot de passe en clair (env AI_VALIDATION_PASSWORD).
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/ai-loop-direct-answer-canonical.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1233');
fs.mkdirSync(CAPTURES, { recursive: true });

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const MAYA = 'maya@artscilab-demo.test';
const JONAS = 'jonas@artscilab-demo.test';
const LOOP_URL = `${ORG_ROOT}/loops/artscilab-emergence`;
const ORG_AI_PAGE = `${ORG_ROOT}/admin/ai`;
const BEHAVIOR_PAGE = `${ORG_ROOT}/admin/ai-behavior`;
const QUESTION = 'Quelle est la prochaine étape concrète pour le prototype tactile ?';

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
        $doc = \\App\\Models\\OrganizationAiDoctrine::where('organization_id', $org->id)->where('status', 'active')->first();
        echo json_encode(['correlation_id'=>$i?->correlation_id,'feature'=>$i?->feature,'process'=>$i?->process,'model'=>$i?->model,'metadata'=>$i?->metadata,'ledger'=>$led,'doctrine_active'=>$doc ? ['version'=>$doc->version] : null,'constitution'=>\\App\\Ai\\Constitution::VERSION,'response'=>$i?->response]);`);
}
async function setOverride(page, mode, uses = null) {
    await page.goto(ORG_AI_PAGE);
    await page.locator(`[data-ai-user-credit-mode-input="${mode}"]`).check();
    if (mode === 'custom') await page.fill('[data-ai-user-credit-uses-input]', String(uses));
    await page.locator('[data-ai-user-credit-form] button[type="submit"]').click();
    await page.waitForURL((url) => url.pathname.endsWith('/admin/ai'));
    await expect(page.locator('[data-ai-settings-saved]')).toBeVisible();
}
async function askAiViaButton(page, question) {
    await page.goto(LOOP_URL);
    await page.getByRole('button', { name: /Demander à l'IA/ }).first().click();
    await page.fill('#ai-question', question);
    await Promise.all([
        page.waitForURL((url) => url.pathname.endsWith('/loops/artscilab-emergence'), { timeout: 120000 }),
        page.locator('form[action$="/ask-ai"] button[type="submit"]').click(),
    ]);
    await page.waitForLoadState('load');
}

test.describe('TASK-1233 Demander a l IA canonique', () => {
    test('doctrine appliquee, chaine mesuree, refus au plafond, couverture', async ({ page }) => {
        test.setTimeout(600000);
        const watch = watchConsole(page);

        // Etat de depart : reglage plateforme.
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'platform');

        // ── 01 Jonas : « Demander a l'IA » -> reponse portant la doctrine ──────
        await login(page, ORG_SLUG, JONAS);
        const before = counters(JONAS);
        const since = new Date().toISOString();
        await askAiViaButton(page, QUESTION);
        // Le message IA publie (dernier message de type ai) porte la cloture de la doctrine.
        const aiMessage = page.locator('[data-loop-message][data-message-type="ai"], .loop-message-ai, [data-message-ai]').last();
        await page.waitForTimeout(1500);
        const pageText = await page.locator('body').innerText();
        expect(pageText).toContain(QUESTION);
        expect(pageText).toMatch(/Doctrine ArtSciLab appliquée/);
        await page.screenshot({ path: path.join(CAPTURES, '01-demander-a-l-ia-reponse-avec-doctrine.png') });
        const after = counters(JONAS);
        const ch = chain(JONAS, since);
        note(`01 ASK — reponse publiee avec « Doctrine ArtSciLab appliquée » ; credit ${before.credit_used} -> ${after.credit_used} ; correlation ${ch.correlation_id}`);
        figures.ask = { question: QUESTION, counters_before: before, counters_after: after, chain: ch };

        // ── 02 Chaine mesuree ────────────────────────────────────────────────
        expect(after.credit_used).toBe(before.credit_used + 1);
        expect(after.interactions).toBe(before.interactions + 1);
        expect(after.ledger).toBe(before.ledger + 1);
        expect(ch.feature).toBe('chatloop_ai_ask');
        expect(ch.process).toBe('chatloop.ask');
        expect(ch.metadata.capability).toBe('loop_ask');
        expect(Array.isArray(ch.metadata.provenance['loop.messages'])).toBe(true);
        expect(ch.metadata.provenance['loop.messages'].length).toBeGreaterThan(0);
        expect(ch.ledger.some((l) => l.operation === 'generation' && l.capability === 'loop_ask' && l.credential_source === 'organization')).toBe(true);
        expect(ch.doctrine_active).toBeTruthy();
        expect(String(ch.response)).toMatch(/Doctrine ArtSciLab appliquée/);
        fs.writeFileSync(path.join(CAPTURES, '02-chaine-mesuree.txt'), [
            'TASK-1233 — chaine traversee par « Demander a l\'IA » (loop_ask), banc, doctrine ArtSciLab active',
            `correlation_id : ${ch.correlation_id}`,
            `trace : feature=${ch.feature} process=${ch.process} model=${ch.model} capability=${ch.metadata.capability} status=${ch.metadata.status}`,
            `provenance (ContextBuilder -> loop.messages) : ${JSON.stringify(ch.metadata.provenance)} sources_used=${JSON.stringify(ch.metadata.sources_used)}`,
            `ledger canonique : ${JSON.stringify(ch.ledger)}`,
            `Constitution ${ch.constitution} — doctrine active v${ch.doctrine_active.version} — la reponse se termine par « — Doctrine ArtSciLab appliquée »`,
            `credit AVANT ${before.credit_used} -> APRES ${after.credit_used} (+1, une seule consommation) ; ledger ${before.ledger} -> ${after.ledger} ; ai_interactions ${before.interactions} -> ${after.interactions}`,
        ].join('\n'));

        // ── 03 Plafond : refus avant provider ────────────────────────────────
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'custom', after.credit_used);
        await login(page, ORG_SLUG, JONAS);
        const before3 = counters(JONAS);
        const messagesBefore = tinker(`$l=\\App\\Models\\Loop::where('slug','artscilab-emergence')->firstOrFail(); echo json_encode(['messages'=>\\App\\Models\\LoopMessage::where('loop_id',$l->id)->count()]);`);
        await askAiViaButton(page, 'Une question de trop ?');
        const banner = page.locator('[data-ai-refusal-code]').first();
        await expect(banner).toBeVisible();
        await expect(banner).toContainText(/crédit IA du mois est épuisé/);
        await expect(banner.locator('[data-ai-offers-link]')).toBeVisible();
        await page.waitForTimeout(1500);
        await page.screenshot({ path: path.join(CAPTURES, '03-plafond-refus-voir-les-offres.png') });
        const after3 = counters(JONAS);
        const messagesAfter = tinker(`$l=\\App\\Models\\Loop::where('slug','artscilab-emergence')->firstOrFail(); echo json_encode(['messages'=>\\App\\Models\\LoopMessage::where('loop_id',$l->id)->count()]);`);
        expect(after3).toEqual(before3);
        expect(messagesAfter.messages).toBe(messagesBefore.messages);
        expect(await page.getByText('Une question de trop ?').count()).toBe(0);
        note(`03 PLAFOND — refus + Voir les offres ; invariance ledger ${before3.ledger}=${after3.ledger}, interactions ${before3.interactions}=${after3.interactions}, credit ${before3.credit_used}=${after3.credit_used}, messages ${messagesBefore.messages}=${messagesAfter.messages}`);
        fs.writeFileSync(path.join(CAPTURES, '03-preuve-invariance-plafond.txt'), [
            'TASK-1233 — « Demander a l\'IA » au quota epuise (override Organization = utilisations) : refus AVANT provider.',
            `AVANT : ledger ${before3.ledger}, ai_interactions ${before3.interactions}, credit ${before3.credit_used}, messages de la Boucle ${messagesBefore.messages}.`,
            'ACTION : bouton « Demander a l\'IA », question saisie, envoi -> bandeau « credit epuise » + « Voir les offres ».',
            `APRES : ledger ${after3.ledger}, ai_interactions ${after3.interactions}, credit ${after3.credit_used}, messages ${messagesAfter.messages}.`,
            'INVARIANCE : identiques, strictement ; aucune question publiee.',
        ].join('\n'));
        figures.cap = { counters_before: before3, counters_after: after3, messages_before: messagesBefore.messages, messages_after: messagesAfter.messages };

        // ── 04 Comportement IA : couverture ──────────────────────────────────
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'platform');
        await page.goto(BEHAVIOR_PAGE);
        const coverage = page.locator('[data-behavior-coverage]');
        await expect(coverage).toBeVisible();
        expect(await page.locator('[data-behavior-coverage-item="loop_answer"][data-behavior-coverage-kind="covered"]').count()).toBe(1);
        expect(await page.locator('[data-behavior-coverage-item="loop_ask"][data-behavior-coverage-kind="covered"]').count()).toBe(1);
        expect(await page.locator('[data-behavior-coverage-item="chatloop_direct_answer"]').count()).toBe(0);
        const covered = await coverage.getAttribute('data-behavior-coverage-covered');
        const total = await coverage.getAttribute('data-behavior-coverage-total');
        await coverage.scrollIntoViewIfNeeded();
        await page.waitForTimeout(1000);
        await page.screenshot({ path: path.join(CAPTURES, '04-comportement-ia-couverture.png'), fullPage: true });
        note(`04 COUVERTURE — ${covered} / ${total} ; loop_answer et loop_ask couvertes ; chatloop_direct_answer absent des heritees`);
        figures.coverage = { covered, total };

        fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(figures, null, 2));
        fs.writeFileSync(path.join(CAPTURES, 'journal.txt'), journal.join('\n'));
        assertClean(watch);
    });

    test.afterEach(async ({ page }) => {
        const video = page.video();
        if (!video) return;
        await page.close();
        try { await video.saveAs(path.join(CAPTURES, 'demander-a-l-ia-canonique.webm')); } catch (e) { /* pas de video */ }
    });
});
