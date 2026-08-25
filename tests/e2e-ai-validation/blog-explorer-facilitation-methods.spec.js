// TASK-1249 — les quatre methodes de facilitation de Roger dans le chat
// Explorer d'article (Explorer / Ralentir / Clarifier / Inventer). Preuve de
// surface REELLE sur le banc (127.0.0.1:8010, Organization ai-validation-org-a,
// article T1248 sauvegarde, cle plateforme) :
//
//   Desktop (1280x720)
//   01 Explorer ouvert : la barre des 4 boutons est visible AU-DESSUS du chat,
//      aucun actif (aria-pressed=false), indice « choisissez la posture ».
//   02-05 pour CHAQUE methode : nouvelle conversation (reset du choix a
//      l'ouverture, verifie), clic sur le bouton (aria-pressed=true, indice
//      « Methode active »), la MEME question envoyee -> la requete porte
//      `method_code` = identifiant canonique -> reponse REELLE distincte
//      capturee ; ledger/trace inchanges (process blog.explorer_dialogue,
//      feature blog_explorer, aucun champ method_code : callProvider aveugle).
//   Mobile (390x844)
//   06 les 4 boutons visibles (grille 2x2) ; 07 une reponse reelle (Inventer).
//   Fin : 5 appels reels, 4 reponses desktop distinctes entre elles.
//   AUCUN mot de passe en clair (env AI_VALIDATION_PASSWORD).
//
// Usage (depuis le worktree) :
//   npx playwright test --config=playwright.ai-validation.config.mjs blog-explorer-facilitation-methods

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1249');
fs.mkdirSync(CAPTURES, { recursive: true });

const ORG_SLUG = 'ai-validation-org-a';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const MEMBER = 'member1@ai-validation-org-a.ai-validation.test';
const POST_SLUG = 't1248-explorer-preuve-economique';
const EDIT_URL = `${ORG_ROOT}/blog/rediger/${POST_SLUG}/modifier`;
const QUESTION = 'Par où me conseilles-tu de commencer pour questionner cet article ?';

// Identifiants CANONIQUES (BlogAiService::METHOD_SELECTION_METHODS) et libelles FR.
const METHODS = [
    { key: 'explorer', label: 'Explorer' },
    { key: 'slow_down', label: 'Ralentir' },
    { key: 'clarifier', label: 'Clarifier' },
    { key: 'invent', label: 'Inventer' },
];

const figures = { desktop: {}, mobile: {} };
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
        echo json_encode([
            'ledger_explorer' => \\App\\Models\\AiProviderInvocation::where('organization_id', $org->id)->where('user_id', $u->id)->where('process', 'blog.explorer_dialogue')->count(),
            'interactions_explorer' => \\App\\Models\\AiInteraction::where('organization_id', $org->id)->where('user_id', $u->id)->where('feature', 'blog_explorer')->count(),
            'method_prompts_in_db' => \\App\\Models\\AdminAiPrompt::where('scenario_id', 'like', 'blog_explorer_method_%')->count(),
        ]);`);
}
function lastChain(email, sinceIso) {
    return tinker(`
        $u = \\App\\Models\\User::where('email', '${email}')->firstOrFail();
        $post = \\App\\Models\\BlogPost::where('slug', '${POST_SLUG}')->firstOrFail();
        $i = \\App\\Models\\AiInteraction::where('user_id', $u->id)->where('created_at', '>=', '${sinceIso}')->orderByDesc('created_at')->first();
        $led = $i ? \\App\\Models\\AiProviderInvocation::where('correlation_id', $i->correlation_id)->get()->map(fn ($r) => ['operation'=>$r->operation,'capability'=>$r->capability,'feature'=>$r->feature,'process'=>$r->process,'provider'=>$r->provider,'model'=>$r->model,'credential_source'=>$r->credential_source,'status'=>$r->status,'cost_status'=>$r->cost_status,'provider_cost'=>$r->provider_cost,'organization_id'=>$r->organization_id])->all() : [];
        echo json_encode(['correlation_id'=>$i?->correlation_id,'feature'=>$i?->feature,'process'=>$i?->process,'organization_id'=>$i?->organization_id,'post_organization_id'=>$post->organization_id,'metadata'=>$i?->metadata,'ledger'=>$led,'response'=>mb_substr((string) $i?->response, 0, 600)]);`);
}
async function openExplorer(page) {
    await page.goto(EDIT_URL);
    await page.waitForLoadState('load');
    await page.waitForTimeout(800);
    const whole = page.getByRole('button', { name: /Questionner tout l.article/i }).first();
    if (!(await whole.isVisible().catch(() => false))) {
        await page.locator('button:has(> span:has-text("Questionner un passage"))').first().click();
        await page.waitForTimeout(400);
    }
    await expect(whole).toBeVisible();
    await whole.click();
    await expect(page.locator('deep-chat')).toBeVisible();
    await expect(page.locator('deep-chat #text-input')).toBeVisible({ timeout: 15000 });
    await page.waitForTimeout(600);
}
async function ask(page, question) {
    const input = page.locator('deep-chat #text-input');
    await input.click();
    await input.fill(question);
    await input.press('Enter');
}
async function expectBarVisibleAndIdle(page) {
    const bar = page.locator('[data-explorer-method-bar]');
    await expect(bar).toBeVisible();
    for (const m of METHODS) {
        const btn = page.locator(`[data-explorer-method="${m.key}"]`);
        await expect(btn).toBeVisible();
        await expect(btn).toHaveText(new RegExp(m.label));
        await expect(btn).toHaveAttribute('aria-pressed', 'false');
    }
    await expect(page.locator('[data-explorer-method-hint]')).toContainText(/Choisissez la posture/i);
    // La barre est AU-DESSUS du chat.
    const barBox = await bar.boundingBox();
    const chatBox = await page.locator('deep-chat').boundingBox();
    expect(barBox.y + barBox.height).toBeLessThanOrEqual(chatBox.y + 1);
}
async function askWithMethod(page, method, screenshotName, bucket) {
    const before = counters(MEMBER);
    const since = new Date().toISOString();
    await page.locator(`[data-explorer-method="${method.key}"]`).click();
    await expect(page.locator(`[data-explorer-method="${method.key}"]`)).toHaveAttribute('aria-pressed', 'true');
    for (const other of METHODS.filter((m) => m.key !== method.key)) {
        await expect(page.locator(`[data-explorer-method="${other.key}"]`)).toHaveAttribute('aria-pressed', 'false');
    }
    await expect(page.locator('[data-explorer-method-hint]')).toContainText(new RegExp(`Méthode active : ${method.label}`));

    const answered = page.waitForResponse((r) => r.url().endsWith('/explorer/chat') && r.request().method() === 'POST', { timeout: 120000 });
    await ask(page, QUESTION);
    const response = await answered;
    const requestBody = response.request().postDataJSON();
    expect(requestBody.method_code).toBe(method.key);
    expect(requestBody.message).toBe(QUESTION);
    expect(response.status()).toBe(200);
    const json = await response.json();
    expect(typeof json.text).toBe('string');
    expect(json.text.length).toBeGreaterThan(20);
    await expect(page.locator('deep-chat .ai-message-text').nth(1)).toBeVisible({ timeout: 30000 });
    await page.waitForTimeout(800);
    await page.screenshot({ path: path.join(CAPTURES, screenshotName) });

    const after = counters(MEMBER);
    const ch = lastChain(MEMBER, since);
    expect(after.ledger_explorer).toBe(before.ledger_explorer + 1);
    expect(after.interactions_explorer).toBe(before.interactions_explorer + 1);
    expect(ch.feature).toBe('blog_explorer');
    expect(ch.process).toBe('blog.explorer_dialogue');
    expect(ch.organization_id).toBe(ch.post_organization_id);
    // TASK-1256 : la trace porte desormais `metadata.method_code` (revision
    // volontaire de T1249) ; le ledger, lui, reste vierge de toute methode.
    expect(ch.metadata.method_code).toBe(method.key);
    expect(ch.ledger.length).toBe(1);
    expect(ch.ledger[0]).toMatchObject({ operation: 'generation', capability: null, feature: 'blog_explorer', process: 'blog.explorer_dialogue', credential_source: 'platform', status: 'success' });
    bucket[method.key] = {
        method_code_sent: requestBody.method_code, http_status: response.status(), provider: ch.ledger[0].provider, model: ch.ledger[0].model,
        cost_status: ch.ledger[0].cost_status, provider_cost: ch.ledger[0].provider_cost, correlation_id: ch.correlation_id, answer: json.text,
        method_prompts_in_db: after.method_prompts_in_db,
    };
    note(`${method.label} (${method.key}) -> reponse REELLE ${ch.ledger[0].provider}/${ch.ledger[0].model} (${json.text.length} car., cout ${ch.ledger[0].cost_status} ${ch.ledger[0].provider_cost ?? 'NULL'}) ; ledger +1 blog.explorer_dialogue, trace +1, metadata.method_code = ${ch.metadata.method_code} (T1256) ; prompts admin en base = ${after.method_prompts_in_db} (fallback code)`);
    return json.text;
}

test.describe('TASK-1249 Roger — 4 methodes de facilitation dans le chat Explorer', () => {
    test('desktop : barre visible, une reponse reelle distincte par methode, reset a chaque conversation', async ({ page }) => {
        test.setTimeout(600000);
        const watch = watchConsole(page);
        await page.setViewportSize({ width: 1280, height: 720 });
        await login(page, ORG_SLUG, MEMBER);

        // 01 — barre visible au-dessus du chat, rien d'actif.
        await openExplorer(page);
        await expectBarVisibleAndIdle(page);
        await page.screenshot({ path: path.join(CAPTURES, '01-desktop-explorer-4-boutons-au-dessus-du-chat.png') });
        note('01 desktop : Explorer ouvert, 4 boutons (Explorer / Ralentir / Clarifier / Inventer) visibles AU-DESSUS du deep-chat, aucun actif');

        // 02-05 — une conversation par methode (reset du choix a l'ouverture verifie).
        const answers = {};
        let i = 2;
        for (const method of METHODS) {
            if (method.key !== 'explorer') {
                await page.getByRole('button', { name: /^Fermer$/ }).first().click();
                await page.waitForTimeout(300);
                await openExplorer(page);
                await expectBarVisibleAndIdle(page); // le choix ne survit PAS a la conversation precedente
            }
            answers[method.key] = await askWithMethod(page, method, `0${i}-desktop-${method.key}-reponse-reelle.png`, figures.desktop);
            i += 1;
        }

        // Quatre reponses distinctes entre elles.
        const distinct = new Set(Object.values(answers).map((a) => a.trim()));
        expect(distinct.size).toBe(4);
        note(`02-05 desktop : 4 reponses reelles, 4 textes distincts (${Object.values(answers).map((a) => a.length).join(' / ')} car.)`);

        assertClean(watch);
    });

    test('mobile : les 4 boutons visibles, une reponse reelle (Inventer)', async ({ page }) => {
        test.setTimeout(300000);
        const watch = watchConsole(page);
        await page.setViewportSize({ width: 390, height: 844 });
        await login(page, ORG_SLUG, MEMBER);

        await openExplorer(page);
        await expectBarVisibleAndIdle(page);
        // Les 4 boutons tiennent dans la largeur mobile (grille 2x2).
        for (const m of METHODS) {
            const box = await page.locator(`[data-explorer-method="${m.key}"]`).boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(390);
        }
        await page.screenshot({ path: path.join(CAPTURES, '06-mobile-explorer-4-boutons.png') });
        note('06 mobile 390x844 : 4 boutons visibles en grille 2x2 au-dessus du chat, saisie visible');

        await askWithMethod(page, METHODS[3], '07-mobile-invent-reponse-reelle.png', figures.mobile);

        fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(figures, null, 2));
        fs.writeFileSync(path.join(CAPTURES, 'journal.txt'), journal.join('\n'));
        assertClean(watch);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const video = page.video();
        if (!video) return;
        await page.close();
        const name = testInfo.title.startsWith('mobile') ? 'explorer-methodes-mobile.webm' : 'explorer-methodes-desktop.webm';
        try { await video.saveAs(path.join(CAPTURES, name)); } catch (e) { /* pas de video */ }
    });
});
