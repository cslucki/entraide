// TASK-1256 — Human Feedback V1 sur l'Article Explorer (banc 8010, preuve
// REELLE) : sous chaque reponse IA, « Utile » / « A ameliorer », puis
// disclosure facultative (pourquoi / quoi ameliorer ; meilleure intervention).
//
// Ce que la preuve etablit, pour de vrai (provider reel du banc) :
//  - `chat()` renvoie `{text, ai_interaction_id}` ; le bloc de feedback est
//    rendu SOUS la bulle de la reponse (message html deep-chat) ;
//  - un clic « Utile » / « A ameliorer » ecrit une ligne
//    `ai_interaction_feedbacks` ancree sur la trace (verdict seul suffit) ;
//  - le formulaire optionnel complete la MEME ligne (upsert par
//    (interaction, acteur)) ; changer d'avis met a jour la meme ligne ;
//  - relecture SQL : feedback -> interaction (`metadata.method_code` = la
//    methode de la conversation, `null` en dialogue libre) -> ledger
//    (enveloppe economique identique, aucune semantique de methode) ;
//  - desktop clair + mobile sombre, aucune erreur console / 5xx.
//
// Prerequis : banc 8010 (`ai/scripts/ai-validation-serve.sh`), migration
// `2026_08_19_170000_create_ai_interaction_feedbacks_table` appliquee sur
// `bouclepro_ai_validation`, `npm run build` a jour.
//
// Lancement : npx playwright test --config=playwright.ai-validation.config.mjs \
//   tests/e2e-ai-validation/blog-explorer-human-feedback.spec.js
import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1256');
fs.mkdirSync(CAPTURES, { recursive: true });

const ORG_SLUG = 'ai-validation-org-a';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const MEMBER = 'member1@ai-validation-org-a.ai-validation.test';
const POST_SLUG = 't1248-explorer-preuve-economique';
const EDIT_URL = `${ORG_ROOT}/blog/rediger/${POST_SLUG}/modifier`;
const QUESTION = 'Par où me conseilles-tu de commencer pour questionner cet article ?';

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
// La chaine complete relue en base a partir de l'id renvoye au navigateur.
function chain(interactionId) {
    return tinker(`
        $i = \\App\\Models\\AiInteraction::findOrFail('${interactionId}');
        $post = \\App\\Models\\BlogPost::where('slug', '${POST_SLUG}')->firstOrFail();
        $u = \\App\\Models\\User::where('email', '${MEMBER}')->firstOrFail();
        $fb = \\App\\Models\\AiInteractionFeedback::where('ai_interaction_id', $i->id)->orderBy('created_at')->get()->map(fn ($f) => ['id'=>$f->id,'verdict'=>$f->verdict,'comment'=>$f->comment,'suggested_response'=>$f->suggested_response,'organization_id'=>$f->organization_id,'user_id'=>$f->user_id,'created_at'=>(string) $f->created_at,'updated_at'=>(string) $f->updated_at])->all();
        $led = \\App\\Models\\AiProviderInvocation::where('correlation_id', $i->correlation_id)->get()->map(fn ($r) => ['operation'=>$r->operation,'capability'=>$r->capability,'feature'=>$r->feature,'process'=>$r->process,'provider'=>$r->provider,'model'=>$r->model,'credential_source'=>$r->credential_source,'status'=>$r->status,'cost_status'=>$r->cost_status,'provider_cost'=>$r->provider_cost,'organization_id'=>$r->organization_id])->all();
        echo json_encode(['interaction'=>['id'=>$i->id,'feature'=>$i->feature,'process'=>$i->process,'organization_id'=>$i->organization_id,'user_id'=>$i->user_id,'metadata'=>$i->metadata,'correlation_id'=>$i->correlation_id,'response_len'=>mb_strlen((string) $i->response)],'post_organization_id'=>$post->organization_id,'member_id'=>$u->id,'feedbacks'=>$fb,'ledger'=>$led,'feedback_columns'=>\\Illuminate\\Support\\Facades\\Schema::getColumnListing('ai_interaction_feedbacks')]);`);
}
async function openExplorer(page) {
    await page.goto(EDIT_URL);
    await page.waitForLoadState('load');
    await page.waitForTimeout(800);
    const whole = page.getByRole('button', { name: /Questionner tout l.article|Questionner l.article/i }).first();
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
    const answered = page.waitForResponse((r) => r.url().endsWith('/explorer/chat') && r.request().method() === 'POST', { timeout: 120000 });
    await input.press('Enter');
    const response = await answered;
    expect(response.status()).toBe(200);
    const json = await response.json();
    expect(typeof json.text).toBe('string');
    expect(json.text.length).toBeGreaterThan(20);
    expect(json.ai_interaction_id).toMatch(/^[0-9a-f-]{36}$/);
    // Le bloc de feedback est la, relie a CET id, SOUS la bulle de la reponse.
    const fb = page.locator(`deep-chat .bp-fb[data-interaction-id="${json.ai_interaction_id}"]`);
    await expect(fb).toBeVisible({ timeout: 30000 });
    // La bulle TEXTE de la reponse (la bulle html du bloc porte aussi la classe de role).
    const bubble = page.locator('deep-chat .ai-message-text:not(.html-message)').last();
    const bubbleBox = await bubble.boundingBox();
    const fbBox = await fb.boundingBox();
    // Le bloc colle sous la bulle (marginTop -4px voulu) : tolerance 6px.
    expect(fbBox.y).toBeGreaterThanOrEqual(bubbleBox.y + bubbleBox.height - 6);
    await expect(fb.locator('.bp-fb-verdict[data-verdict="helpful"]')).toHaveText(/Utile/);
    await expect(fb.locator('.bp-fb-verdict[data-verdict="improve"]')).toHaveText(/À améliorer/);
    await expect(fb.locator('.bp-fb-form')).toBeHidden();
    return { json, fb, requestBody: response.request().postDataJSON() };
}
async function feedbackPost(page, fn) {
    const posted = page.waitForResponse((r) => r.url().endsWith('/explorer/feedback') && r.request().method() === 'POST', { timeout: 30000 });
    await fn();
    const response = await posted;
    return { status: response.status(), json: await response.json().catch(() => ({})), body: response.request().postDataJSON() };
}
function expectLedgerEnvelope(ch) {
    expect(ch.ledger.length).toBe(1);
    expect(ch.ledger[0]).toMatchObject({ operation: 'generation', capability: null, feature: 'blog_explorer', process: 'blog.explorer_dialogue', credential_source: 'platform', status: 'success', organization_id: ch.post_organization_id });
    expect(ch.interaction.feature).toBe('blog_explorer');
    expect(ch.interaction.process).toBe('blog.explorer_dialogue');
    expect(ch.interaction.organization_id).toBe(ch.post_organization_id);
    expect(ch.interaction.user_id).toBe(ch.member_id);
    expect(ch.feedback_columns.sort()).toEqual(['ai_interaction_id', 'comment', 'created_at', 'id', 'organization_id', 'suggested_response', 'updated_at', 'user_id', 'verdict']);
}

test.describe('TASK-1256 Human Feedback V1 — Article Explorer', () => {
    test('desktop clair : Clarifier -> Utile -> disclosure -> changement d avis, tout relie en base', async ({ page }) => {
        test.setTimeout(420000);
        const watch = watchConsole(page);
        await page.setViewportSize({ width: 1280, height: 720 });
        await page.addInitScript(() => { localStorage.theme = 'light'; });
        await login(page, ORG_SLUG, MEMBER);
        await openExplorer(page);

        // Methode Clarifier pour la conversation.
        await page.locator('[data-explorer-method="clarifier"]').click();
        await expect(page.locator('[data-explorer-method="clarifier"]')).toHaveAttribute('aria-pressed', 'true');

        const { json, fb, requestBody } = await ask(page, QUESTION);
        expect(requestBody.method_code).toBe('clarifier');
        await page.waitForTimeout(500);
        await page.screenshot({ path: path.join(CAPTURES, '01-desktop-reponse-reelle-bloc-feedback-sous-la-bulle.png') });
        note(`01 desktop : reponse reelle (${json.text.length} car.), JSON {text, ai_interaction_id=${json.ai_interaction_id}}, bloc « Utile / A ameliorer » SOUS la bulle, formulaire cache`);

        let ch = chain(json.ai_interaction_id);
        expect(ch.feedbacks).toEqual([]);
        expect(ch.interaction.metadata.method_code).toBe('clarifier');
        expectLedgerEnvelope(ch);
        note(`trace ${ch.interaction.id} : metadata.method_code = clarifier ; ledger 1 ligne ${ch.ledger[0].provider}/${ch.ledger[0].model} (${ch.ledger[0].cost_status}) ; AUCUN feedback avant le clic`);

        // 02 — clic « Utile » : verdict seul, ecrit tout de suite.
        const r1 = await feedbackPost(page, () => fb.locator('.bp-fb-verdict[data-verdict="helpful"]').click());
        expect(r1.status).toBe(200);
        expect(r1.body).toMatchObject({ ai_interaction_id: json.ai_interaction_id, verdict: 'helpful', comment: null, suggested_response: null });
        expect(r1.json.verdict).toBe('helpful');
        await expect(fb.locator('.bp-fb-status')).toHaveText(/Merci, c’est noté/);
        await expect(fb.locator('.bp-fb-verdict[data-verdict="helpful"]')).toHaveAttribute('aria-pressed', 'true');
        await expect(fb.locator('.bp-fb-form')).toBeVisible();
        await fb.locator('.bp-fb-form').scrollIntoViewIfNeeded();
        await page.waitForTimeout(300);
        await page.screenshot({ path: path.join(CAPTURES, '02-desktop-utile-clique-formulaire-optionnel.png') });
        ch = chain(json.ai_interaction_id);
        expect(ch.feedbacks.length).toBe(1);
        expect(ch.feedbacks[0]).toMatchObject({ verdict: 'helpful', comment: null, suggested_response: null, organization_id: ch.post_organization_id, user_id: ch.member_id });
        const feedbackId = ch.feedbacks[0].id;
        note(`02 desktop : clic Utile -> POST 200, ligne ${feedbackId} (helpful, commentaire NULL, suggestion NULL, organization_id = Organization de l'article, user_id = acteur) ; formulaire optionnel affiche`);

        // 03 — disclosure : la MEME ligne se complete.
        await fb.locator('.bp-fb-comment').fill('La question m’a fait relire le paragraphe sur la garde.');
        await fb.locator('.bp-fb-suggest').fill('Quel fait du paragraphe 2 prouve que la garde passe AVANT l’appel provider ?');
        const r2 = await feedbackPost(page, () => fb.locator('.bp-fb-send').click());
        expect(r2.status).toBe(200);
        expect(r2.json.id).toBe(feedbackId);
        await expect(fb.locator('.bp-fb-status')).toHaveText(/précisions sont enregistrées/);
        await page.screenshot({ path: path.join(CAPTURES, '03-desktop-disclosure-enregistree.png') });
        ch = chain(json.ai_interaction_id);
        expect(ch.feedbacks.length).toBe(1);
        expect(ch.feedbacks[0]).toMatchObject({ id: feedbackId, verdict: 'helpful', comment: 'La question m’a fait relire le paragraphe sur la garde.', suggested_response: 'Quel fait du paragraphe 2 prouve que la garde passe AVANT l’appel provider ?' });
        note(`03 desktop : Envoyer -> POST 200, MEME ligne ${feedbackId} completee (commentaire + meilleure intervention), toujours 1 feedback`);

        // 04 — changement d'avis : meme ligne, dernier envoi complet.
        const r3 = await feedbackPost(page, () => fb.locator('.bp-fb-verdict[data-verdict="improve"]').click());
        expect(r3.status).toBe(200);
        expect(r3.json.id).toBe(feedbackId);
        expect(r3.body.verdict).toBe('improve');
        await expect(fb.locator('.bp-fb-verdict[data-verdict="improve"]')).toHaveAttribute('aria-pressed', 'true');
        await expect(fb.locator('.bp-fb-verdict[data-verdict="helpful"]')).toHaveAttribute('aria-pressed', 'false');
        await page.screenshot({ path: path.join(CAPTURES, '04-desktop-changement-d-avis-a-ameliorer.png') });
        ch = chain(json.ai_interaction_id);
        expect(ch.feedbacks.length).toBe(1);
        expect(ch.feedbacks[0]).toMatchObject({ id: feedbackId, verdict: 'improve' });
        expect(ch.feedbacks[0].comment).toBe('La question m’a fait relire le paragraphe sur la garde.');
        expectLedgerEnvelope(ch);
        expect(ch.interaction.metadata.method_code).toBe('clarifier');
        note(`04 desktop : A ameliorer -> MEME ligne ${feedbackId} (improve), commentaire conserve (le client renvoie l'etat complet) ; ledger inchange (1 ligne, meme enveloppe) ; method_code toujours clarifier`);

        figures.desktop = { ai_interaction_id: json.ai_interaction_id, feedback_id: feedbackId, method_code: ch.interaction.metadata.method_code, final: ch.feedbacks[0], ledger: ch.ledger[0] };
        assertClean(watch);
    });

    test('mobile sombre : dialogue libre -> A ameliorer -> commentaire seul', async ({ page }) => {
        test.setTimeout(300000);
        const watch = watchConsole(page);
        await page.setViewportSize({ width: 390, height: 844 });
        await page.addInitScript(() => { localStorage.theme = 'dark'; });
        await login(page, ORG_SLUG, MEMBER);
        await openExplorer(page);

        const { json, fb, requestBody } = await ask(page, QUESTION);
        expect(requestBody.method_code).toBeNull();
        // Le bloc tient dans la largeur mobile.
        const box = await fb.boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(0);
        expect(box.x + box.width).toBeLessThanOrEqual(390);
        await page.screenshot({ path: path.join(CAPTURES, '05-mobile-sombre-reponse-bloc-feedback.png') });

        let ch = chain(json.ai_interaction_id);
        expect(ch.interaction.metadata).toHaveProperty('method_code');
        expect(ch.interaction.metadata.method_code).toBeNull();
        expectLedgerEnvelope(ch);
        note(`05 mobile 390x844 sombre : dialogue libre -> metadata.method_code = null (cle presente), bloc de feedback sous la bulle, dans la largeur`);

        const r1 = await feedbackPost(page, () => fb.locator('.bp-fb-verdict[data-verdict="improve"]').click());
        expect(r1.status).toBe(200);
        await expect(fb.locator('.bp-fb-form')).toBeVisible();
        await fb.locator('.bp-fb-comment').fill('Trop général : rien sur le ledger écrit même en cas d’échec.');
        const r2 = await feedbackPost(page, () => fb.locator('.bp-fb-send').click());
        expect(r2.status).toBe(200);
        await expect(fb.locator('.bp-fb-status')).toHaveText(/précisions sont enregistrées/);
        await page.screenshot({ path: path.join(CAPTURES, '06-mobile-sombre-a-ameliorer-commentaire.png') });
        ch = chain(json.ai_interaction_id);
        expect(ch.feedbacks.length).toBe(1);
        expect(ch.feedbacks[0]).toMatchObject({ verdict: 'improve', comment: 'Trop général : rien sur le ledger écrit même en cas d’échec.', suggested_response: null, organization_id: ch.post_organization_id, user_id: ch.member_id });
        note(`06 mobile : A ameliorer + commentaire seul -> 1 ligne (improve, suggestion NULL), meme tenant / acteur`);

        figures.mobile = { ai_interaction_id: json.ai_interaction_id, method_code: ch.interaction.metadata.method_code, final: ch.feedbacks[0], ledger: ch.ledger[0] };
        fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(figures, null, 2));
        fs.writeFileSync(path.join(CAPTURES, 'journal.txt'), journal.join('\n'));
        assertClean(watch);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const video = page.video();
        if (!video) return;
        await page.close();
        const name = testInfo.title.startsWith('mobile') ? 'explorer-feedback-mobile.webm' : 'explorer-feedback-desktop.webm';
        try { await video.saveAs(path.join(CAPTURES, name)); } catch (e) { /* pas de video */ }
    });
});
