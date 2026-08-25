// TASK-1234 — Recette FINALE de Definition of Done du systeme nerveux IA V1 :
// UN SEUL parcours, date, sur le banc, sur develop e8727d2 (VERSION 1.176), le
// meme jour que les six merges 1228-1233. Elle a le droit d'echouer : un trou
// revele n'est PAS bouche ici (STOP + rapport). Aucun code applicatif.
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation, ArtSciLab Demo). Toutes les invocations sont REELLES.
//
//   01..07  Les sept points de la recette 1232, contenu inedit NOUVEAU
//           (SELENITE-4 / 27 minutes -> 52 minutes) : depot par Maya (drop UI),
//           indexation reelle, question de Jonas via le FAB, reponse sourcee
//           [S1] ouvrable, isolation (permissions + P4 + isolation RAG MESUREE
//           avec org-b configuree, son credential, Dossiers de A designes ->
//           0 ligne ; sous A -> le chunk inedit), mise a jour sans contenu
//           perime, suppression propre.
//   08      « Demander a l'IA » canonique (1233) : question de Jonas par le
//           bouton historique -> reponse publiee portant la cloture de la
//           doctrine ; chaine mesuree (capability loop_ask, Constitution +
//           doctrine composees par PromptRepository, provenance loop.messages
//           du ContextBuilder bornee au tenant, provider/credential de
//           l'Organization, ledger + trace, credit +1) ; la seconde face
//           (action=answer, capability loop_answer) par la meme route.
//   09      UX historique : quota epuise (override Organization pose par Maya)
//           -> refus AVANT provider, bandeau + « Voir les offres », invariance
//           mesuree, aucune question publiee ; override restaure.
//   10      « Comportement IA » (Maya) : couverture — chatloop_direct_answer
//           n'est plus herite, loop_answer / loop_ask couvertes.
//
// LIVRABLE : PNG 01..10 + video + figures.json + journal.txt + chaine/*.txt
// dans _local/captures/TASK-1234/. AUCUN mot de passe en clair (credential de
// banc via env AI_VALIDATION_PASSWORD).
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/ai-dod-finale-systeme-nerveux.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1234');
fs.mkdirSync(path.join(CAPTURES, 'chaine'), { recursive: true });

const DEVELOP = 'e8727d2';
const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const MAYA = 'maya@artscilab-demo.test';
const JONAS = 'jonas@artscilab-demo.test';
const OTHER_ORG_SLUG = 'ai-validation-org-b';
const OTHER_MEMBER = 'member1@ai-validation-org-b.ai-validation.test';
const OTHER_LOOP = `/org/${OTHER_ORG_SLUG}/loops/ai-validation-org-b-loop-principale`;

const DOSSIER_ID = '019ffb69-cb3f-720e-b192-659b1fe5c64b'; // Emergence — Session 01 (Dossier de la Boucle Emergence)
const DOSSIER_URL = `${ORG_ROOT}/dossiers/${DOSSIER_ID}`;
const LOOP_SLUG = 'artscilab-emergence';
const LOOP_URL = `${ORG_ROOT}/loops/${LOOP_SLUG}`;
const ORG_AI_PAGE = `${ORG_ROOT}/admin/ai`;
const BEHAVIOR_PAGE = `${ORG_ROOT}/admin/ai-behavior`;

// Fait INVENTE, NOUVEAU (jamais depose sur ce banc) : n'existe ni dans le corpus
// ArtSciLab, ni dans le modele, ni dans l'ancien fichier de la 1232.
const FILE_NAME = 'TEST-dod-1234-selenite.md';
const SENTINEL_V1 = '27 minutes';
const SENTINEL_V2 = '52 minutes';
const content = (delai) => [
    '# Protocole SÉLÉNITE-4 — consigne d\'atelier (TEST DoD 1234)',
    '',
    `Le protocole SÉLÉNITE-4 impose une pause de **${delai}** avant la remise en marche de la centrifugeuse portable`,
    'après un arrêt d\'urgence. La pause est comptée à partir de l\'extinction du voyant orange.',
    '',
    'Référence interne : SELENITE-4-DOD-1234.',
].join('\n');
const QUESTION = 'Quelle pause impose le protocole SÉLÉNITE-4 avant la remise en marche de la centrifugeuse portable ?';
// Question « Demander a l'IA » (loop_ask) — nouvelle, differente de celle de la 1233.
const ASK_QUESTION = 'Quels risques faut-il anticiper avant le test de terrain du prototype tactile ?';
const DOCTRINE_MARK = /Doctrine ArtSciLab appliquée/;

const figures = { develop: DEVELOP, started_at: new Date().toISOString() };
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

// Lecture DB (APP_ENV=ai-validation, tinker) — read-only sauf mention explicite.
function tinker(php, extraEnv = {}) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', php], { env: { ...process.env, APP_ENV: 'ai-validation', ...extraEnv }, encoding: 'utf8' });
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
function loopMessages() {
    return tinker(`$l=\\App\\Models\\Loop::where('slug','${LOOP_SLUG}')->firstOrFail(); echo json_encode(['messages'=>\\App\\Models\\LoopMessage::where('loop_id',$l->id)->count()]);`);
}
function fileChunks(fileId) {
    return tinker(`
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        $rows = \\Illuminate\\Support\\Facades\\DB::table('dossier_chunks')->where('organization_id', $org->id)->where('dossier_file_id', '${fileId}')->get();
        echo json_encode(['count' => $rows->count(), 'has_v1' => $rows->contains(fn ($r) => str_contains((string) $r->content, '${SENTINEL_V1}')), 'has_v2' => $rows->contains(fn ($r) => str_contains((string) $r->content, '${SENTINEL_V2}'))]);`);
}
// La chaine traversee par la derniere generation de l'utilisateur (capability donnee).
function chain(email, sinceIso, capabilityConst) {
    return tinker(`
        $u = \\App\\Models\\User::where('email', '${email}')->firstOrFail();
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        $i = \\App\\Models\\AiInteraction::where('organization_id', $org->id)->where('user_id', $u->id)->where('created_at', '>=', '${sinceIso}')->orderByDesc('created_at')->orderByDesc('id')->first();
        $led = $i ? \\App\\Models\\AiProviderInvocation::where('correlation_id', $i->correlation_id)->orderBy('created_at')->get()->map(fn ($r) => ['operation' => $r->operation, 'embedding_operation' => $r->embedding_operation, 'capability' => $r->capability, 'process' => $r->process, 'provider' => $r->provider, 'model' => $r->model, 'credential_source' => $r->credential_source, 'status' => $r->status, 'cost_status' => $r->cost_status, 'sdk_invocation_id' => $r->sdk_invocation_id])->all() : [];
        $reg = app(\\App\\Ai\\CapabilityRegistry::class);
        $def = $reg->get(\\App\\Ai\\CapabilityRegistry::${capabilityConst});
        $doc = \\App\\Models\\OrganizationAiDoctrine::where('organization_id', $org->id)->where('status', 'active')->first();
        echo json_encode([
            'correlation_id' => $i?->correlation_id,
            'feature' => $i?->feature, 'process' => $i?->process, 'model' => $i?->model,
            'metadata' => $i?->metadata,
            'response' => $i?->response,
            'ledger' => $led,
            'capability' => $def?->id, 'allowed_sources' => $def?->allowedSources, 'can_write' => $def?->canWrite ?? null, 'process_def' => $def?->process, 'prompt_key' => $def?->promptKey,
            'constitution' => \\App\\Ai\\Constitution::VERSION,
            'doctrine_active' => $doc ? ['id' => $doc->id, 'version' => $doc->version, 'status' => $doc->status] : null,
        ]);`);
}
// La composition PromptRepository (Constitution -> doctrine -> prompt administrable)
// telle que la calcule le banc pour cette capability et cette Organization.
function composition(capabilityConst, scenario) {
    return tinker(`
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        $p = \\App\\Models\\AdminAiPrompt::where('scenario_id', '${scenario}_fr')->where('is_active', true)->orderByDesc('version')->first()
            ?? \\App\\Models\\AdminAiPrompt::where('scenario_id', '${scenario}')->where('is_active', true)->orderByDesc('version')->first();
        $c = app(\\App\\Ai\\PromptRepository::class)->compose(\\App\\Ai\\CapabilityRegistry::${capabilityConst}, (string) $p?->prompt_text, (string) $org->id);
        $constitution = (new \\App\\Ai\\Constitution)->text();
        $doc = \\App\\Models\\OrganizationAiDoctrine::activeFor((string) $org->id);
        $posC = strpos($c, $constitution); $posD = strpos($c, "Doctrine de l'Organization — v"); $posI = strpos($c, 'Instructions capability (');
        echo json_encode([
            'prompt_scenario' => $p?->scenario_id, 'prompt_version' => $p?->version, 'prompt_active' => (bool) $p?->is_active,
            'starts_with_constitution' => str_starts_with($c, $constitution),
            'constitution_version' => \\App\\Ai\\Constitution::VERSION,
            'doctrine_block' => $doc ? str_contains($c, "Doctrine de l'Organization — v{$doc->version}") && str_contains($c, \\App\\Ai\\PromptRepository::DOCTRINE_OPEN) && str_contains($c, (string) $doc->body) : null,
            'doctrine_version' => $doc?->version,
            'capability_line' => str_contains($c, 'Capability: '.\\App\\Ai\\CapabilityRegistry::${capabilityConst}),
            'prompt_key_line' => str_contains($c, 'Instructions capability (${scenario}):'),
            'order_constitution_doctrine_instructions' => $posC !== false && $posD !== false && $posI !== false && $posC < $posD && $posD < $posI,
            'length' => mb_strlen($c),
        ]);`);
}
// La provenance (ids des messages du ContextBuilder) appartient-elle TOUTE a la Boucle du tenant ?
function provenanceScope(ids) {
    const list = ids.map((id) => `'${id}'`).join(',');
    return tinker(`
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        $l = \\App\\Models\\Loop::where('slug','${LOOP_SLUG}')->firstOrFail();
        $ids = [${list}];
        echo json_encode([
            'ids' => count($ids),
            'in_loop_and_org' => \\App\\Models\\LoopMessage::whereIn('id', $ids)->where('loop_id', $l->id)->where('organization_id', $org->id)->count(),
            'outside_org' => \\App\\Models\\LoopMessage::whereIn('id', $ids)->where('organization_id', '!=', $org->id)->count(),
            'loop_org' => $l->organization_id === $org->id,
        ]);`);
}

async function askKnowledge(page, question) {
    await page.goto(LOOP_URL);
    await page.locator('[data-ai-fab-toggle]').click();
    await expect(page.locator('[data-ai-fab-panel]')).toBeVisible();
    await page.locator('[data-ai-fab-action="loop_knowledge"]').click();
    await expect(page.locator('[data-knowledge-dialog]')).toBeVisible();
    await page.fill('#knowledge-question', question);
    const rp = page.waitForResponse((r) => /\/knowledge$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 120000 });
    await page.locator('[data-knowledge-dialog] form button[type="submit"]').click();
    const r = await rp;
    let payload = null; try { payload = await r.json(); } catch (e) { payload = null; }
    await page.waitForTimeout(800);
    return { status: r.status(), payload };
}
async function askAiViaButton(page, question) {
    await page.goto(LOOP_URL);
    await page.getByRole('button', { name: /Demander à l'IA/ }).first().click();
    await page.fill('#ai-question', question);
    const rp = page.waitForResponse((r) => /\/ask-ai$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 120000 });
    await page.locator('form[action$="/ask-ai"] button[type="submit"]').click();
    const r = await rp;
    await page.waitForLoadState('load');
    await page.waitForTimeout(1500);
    return r.status();
}
async function setOverride(page, mode, uses = null) {
    await page.goto(ORG_AI_PAGE);
    await page.locator(`[data-ai-user-credit-mode-input="${mode}"]`).check();
    if (mode === 'custom') await page.fill('[data-ai-user-credit-uses-input]', String(uses));
    await page.locator('[data-ai-user-credit-form] button[type="submit"]').click();
    await page.waitForURL((url) => url.pathname.endsWith('/admin/ai'));
    await expect(page.locator('[data-ai-settings-saved]')).toBeVisible();
}

async function waitIndexed(fileId, expectContains, timeoutMs = 120000) {
    const start = Date.now();
    let last = null;
    while (Date.now() - start < timeoutMs) {
        last = fileChunks(fileId);
        if (last.count > 0 && last[expectContains]) return { ...last, after_ms: Date.now() - start };
        await new Promise((res) => setTimeout(res, 3000));
    }
    return { ...last, timeout: true, after_ms: Date.now() - start };
}
async function waitNoChunks(fileId, timeoutMs = 120000) {
    const start = Date.now();
    let last = null;
    while (Date.now() - start < timeoutMs) {
        last = fileChunks(fileId);
        if (last.count === 0) return { ...last, after_ms: Date.now() - start };
        await new Promise((res) => setTimeout(res, 3000));
    }
    return { ...last, timeout: true, after_ms: Date.now() - start };
}

function writeChain(name, data, extra = {}) {
    const lines = [
        `TASK-1234 — chaine traversee — ${name} — develop ${DEVELOP}`,
        `correlation_id : ${data.correlation_id}`,
        `capability : ${data.capability} (process ${data.process_def}, can_write=${JSON.stringify(data.can_write)}, prompt_key=${data.prompt_key})`,
        `sources autorisees (CapabilityRegistry::allowedSources) : ${JSON.stringify(data.allowed_sources)}`,
        `Constitution : ${data.constitution} — doctrine Organization active : ${JSON.stringify(data.doctrine_active)} (composees par PromptRepository::compose au call-site)`,
        `trace ai_interactions : feature=${data.feature} process=${data.process} model=${data.model}`,
        `  metadata.capability=${data.metadata?.capability} provider=${data.metadata?.provider} status=${data.metadata?.status} sdk_invocation_id=${data.metadata?.sdk_invocation_id}`,
        `  provenance (ContextBuilder -> dossier.retrieval) : consulted=${JSON.stringify(data.metadata?.retrieval?.consulted)} cited=${JSON.stringify(data.metadata?.retrieval?.cited)}`,
        `  provenance (ContextBuilder -> loop.messages) : ${JSON.stringify(data.metadata?.provenance)} sources_used=${JSON.stringify(data.metadata?.sources_used)} sources_denied=${JSON.stringify(data.metadata?.sources_denied)}`,
        `ledger canonique (meme correlation) : ${JSON.stringify(data.ledger)}`,
        ...Object.entries(extra).map(([k, v]) => `${k} : ${typeof v === 'string' ? v : JSON.stringify(v)}`),
    ];
    fs.writeFileSync(path.join(CAPTURES, 'chaine', `${name}.txt`), lines.join('\n'));
}

test.describe('TASK-1234 Recette FINALE DoD systeme nerveux IA V1', () => {
    test('parcours unique : 7 points 1232 (contenu inedit nouveau) + Demander a l IA canonique (1233) + isolation RAG mesuree', async ({ browser }) => {
        test.setTimeout(1200000);
        const context = await browser.newContext({ viewport: { width: 1280, height: 720 }, recordVideo: { dir: path.join(CAPTURES, '.video') } });
        const page = await context.newPage();
        const watch = watchConsole(page);
        let createdFileId = null;
        let overrideTouched = false;

        try {
            // ── 01 Contenu inedit NOUVEAU depose par Maya (UI reelle : drop) ─────
            await login(page, ORG_SLUG, MAYA);
            await page.goto(DOSSIER_URL);
            await page.waitForTimeout(1500);
            const sinceUpload = new Date().toISOString();
            const uploadResponse = page.waitForResponse((r) => r.url().includes(`/dossiers/${DOSSIER_ID}/files`) && r.request().method() === 'POST', { timeout: 30000 });
            const dropped = await page.evaluate(async ({ name, body }) => {
                const zone = Array.from(document.querySelectorAll('[\\@drop\\.prevent], [x-on\\:drop\\.prevent]')).find((el) => el.getAttribute('@drop.prevent')?.includes('handleMediaFiles') || el.getAttribute('x-on:drop.prevent')?.includes('handleMediaFiles'));
                if (!zone) return 'no-zone';
                const file = new File([body], name, { type: 'text/markdown' });
                const dt = new DataTransfer(); dt.items.add(file);
                zone.dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer: dt }));
                zone.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
                return 'dropped';
            }, { name: FILE_NAME, body: content(SENTINEL_V1) });
            expect(dropped).toBe('dropped');
            const upload = await uploadResponse;
            expect(upload.status(), await upload.text()).toBeLessThan(300);
            const uploadJson = await upload.json();
            const created = (uploadJson.files || []).find((f) => (f.display_name || f.original_name || '').includes('TEST-dod-1234'));
            expect(created?.id, 'le fichier cree doit avoir un id').toBeTruthy();
            createdFileId = created.id;
            note(`01 UPLOAD — Maya, Dossier Emergence — Session 01, ${FILE_NAME} -> HTTP ${upload.status()}, id ${createdFileId}`);
            await page.waitForTimeout(1500);
            await page.screenshot({ path: path.join(CAPTURES, '01-contenu-inedit-nouveau-depose-par-maya.png') });
            figures.upload = { file_id: createdFileId, file_name: FILE_NAME, http: upload.status(), sentinel: SENTINEL_V1 };

            // ── 02 Indexation reelle (worker relance sur e8727d2) ─────────────────
            const indexed = await waitIndexed(createdFileId, 'has_v1');
            expect(indexed.timeout, `indexation non observee: ${JSON.stringify(indexed)}`).toBeFalsy();
            expect(indexed.count).toBeGreaterThan(0);
            expect(indexed.has_v1).toBe(true);
            const ledgerAfterIndex = tinker(`$org=\\App\\Models\\Organization::where('slug','${ORG_SLUG}')->firstOrFail(); $rows=\\App\\Models\\AiProviderInvocation::where('organization_id',$org->id)->where('operation','embedding')->where('created_at','>=','${sinceUpload}')->orderBy('created_at')->get(); echo json_encode(['since' => '${sinceUpload}', 'count' => $rows->count(), 'rows' => $rows->map(fn($r)=>['embedding_operation'=>$r->embedding_operation,'capability'=>$r->capability,'provider'=>$r->provider,'model'=>$r->model,'credential_source'=>$r->credential_source,'status'=>$r->status])->all()]);`);
            note(`02 INDEXATION — ${indexed.count} chunk(s) apres ${indexed.after_ms} ms ; ledger embeddings depuis le drop : ${ledgerAfterIndex.count} ${JSON.stringify(ledgerAfterIndex.rows)}`);
            expect(ledgerAfterIndex.count).toBeGreaterThanOrEqual(1);
            expect(ledgerAfterIndex.rows.every((r) => r.credential_source === 'organization' && r.status === 'success')).toBe(true);
            await page.goto(`${ORG_ROOT}/admin/ai-knowledge`);
            const row = page.locator('tr[data-source-key]', { hasText: 'TEST-dod-1234' });
            await expect(row).toHaveCount(1, { timeout: 20000 });
            await expect(row).toHaveAttribute('data-source-indexed', '1', { timeout: 60000 });
            await page.locator('tr[data-source-key]', { hasText: 'TEST-dod-1234' }).first().scrollIntoViewIfNeeded().catch(() => {});
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(CAPTURES, '02-indexation-reelle-observatoire.png') });
            figures.indexation = { chunks: indexed.count, after_ms: indexed.after_ms, ledger_embeddings_since_drop: ledgerAfterIndex };

            // ── 03 Question atteignable seulement par ce contenu (Jonas, FAB) ────
            await login(page, ORG_SLUG, JONAS);
            const before03 = counters(JONAS);
            const since03 = new Date().toISOString();
            const q3 = await askKnowledge(page, QUESTION);
            expect(q3.status, JSON.stringify(q3.payload)).toBe(200);
            note(`03 QUESTION — Jonas via FAB, Boucle Emergence : « ${QUESTION} » -> grounded=${q3.payload.grounded}, sources=${(q3.payload.sources || []).length}`);
            await page.screenshot({ path: path.join(CAPTURES, '03-question-jonas-via-fab.png') });

            // ── 04 Reponse + source citee OUVRABLE ──────────────────────────────
            expect(String(q3.payload.answer)).toContain(SENTINEL_V1);
            expect(q3.payload.grounded).toBe(true);
            const s1 = (q3.payload.sources || []).find((s) => (s.title || '').includes('TEST-dod-1234'));
            expect(s1, 'la source citee est le fichier inedit').toBeTruthy();
            expect(String(q3.payload.answer)).toMatch(new RegExp(`\\[${s1.ref}\\]`));
            const open = await page.request.get(s1.url);
            expect(open.status()).toBeLessThan(400);
            const after03 = counters(JONAS);
            const chain03 = chain(JONAS, since03, 'LOOP_KNOWLEDGE_ANSWER');
            writeChain('03-question-inedite', chain03, { question: QUESTION, reponse: String(q3.payload.answer).slice(0, 400), source_ouverte: `${s1.url} -> HTTP ${open.status()}`, credit_avant: before03, credit_apres: after03 });
            expect(after03.credit_used).toBe(before03.credit_used + 2);
            expect(chain03.metadata?.capability).toBe('loop_knowledge_answer');
            expect(chain03.allowed_sources).toContain('dossier.retrieval');
            expect(chain03.doctrine_active, 'doctrine Organization active').toBeTruthy();
            expect(chain03.ledger.some((l) => l.operation === 'generation' && l.credential_source === 'organization')).toBe(true);
            expect(chain03.ledger.some((l) => l.operation === 'embedding' && l.credential_source === 'organization')).toBe(true);
            note(`04 REPONSE — « ${String(q3.payload.answer).replace(/\s+/g, ' ').slice(0, 160)} » ; source [${s1.ref}] ${s1.title} -> GET ${open.status()} ; credit ${before03.credit_used} -> ${after03.credit_used} ; chaine ${chain03.correlation_id}`);
            await expect(page.locator('[data-knowledge-answer]')).toContainText(SENTINEL_V1);
            await page.locator('[data-knowledge-source]').first().scrollIntoViewIfNeeded();
            await page.waitForTimeout(1500);
            await page.screenshot({ path: path.join(CAPTURES, '04-reponse-27-minutes-source-ouvrable.png') });
            figures.q03 = { status: q3.status, grounded: q3.payload.grounded, answer: q3.payload.answer, sources: q3.payload.sources, source_get: open.status(), credit: q3.payload.credit, counters_before: before03, counters_after: after03, chain: chain03 };
            await page.keyboard.press('Escape');

            // ── 05 Isolation tenant + P4 (§24) — forme etablie en 1232, non degradee ──
            const foreign = await browser.newContext({ viewport: { width: 1280, height: 720 } });
            const fp = await foreign.newPage();
            const fwatch = watchConsole(fp);
            await login(fp, OTHER_ORG_SLUG, OTHER_MEMBER);
            const foreignSourceGet = (await fp.request.get(s1.url)).status();
            expect(foreignSourceGet).toBeGreaterThanOrEqual(400);
            async function askInB(question) {
                await fp.goto(OTHER_LOOP);
                await fp.locator('[data-knowledge-open]').first().click();
                await expect(fp.locator('[data-knowledge-dialog]')).toBeVisible();
                await fp.fill('#knowledge-question', question);
                const rp = fp.waitForResponse((r) => /\/knowledge$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 90000 });
                await fp.locator('[data-knowledge-dialog] form button[type="submit"]').click();
                const r = await rp; let p = null; try { p = await r.json(); } catch (e) { p = null; }
                await fp.waitForTimeout(1200);
                return { status: r.status(), payload: p };
            }
            // 05a — PERMISSIONS (pas RAG) + P4 : B sans configuration -> refus explicite AVANT recherche.
            const q5a = await askInB(QUESTION);
            expect(q5a.status).toBe(422);
            expect(q5a.payload?.code).toBe('ai_not_configured');
            await fp.screenshot({ path: path.join(CAPTURES, '05a-p4-org-b-sans-credential-refus-explicite.png') });
            note(`05a P4/PERMISSIONS — member1(B) GET ${s1.url} -> ${foreignSourceGet} (permissions, pas RAG) ; question sans P4 -> HTTP ${q5a.status} code=${q5a.payload?.code} (refus AVANT recherche : pas une preuve d'isolation RAG)`);

            // 05b — ISOLATION RAG MESUREE : B REELLEMENT configuree (sa propre ligne
            //       organization_ai_settings, copie de la configuration chiffree
            //       d'ArtSciLab le temps du parcours), son credential resolu par
            //       ProviderResolver (instance org:{B}), les Dossiers de A passes
            //       EXPLICITEMENT a B -> 0 ligne ; la meme recherche sous A -> le chunk inedit.
            const bConfigured = tinker(`
                $a=\\App\\Models\\Organization::where('slug','${ORG_SLUG}')->firstOrFail();
                $b=\\App\\Models\\Organization::where('slug','${OTHER_ORG_SLUG}')->firstOrFail();
                $src=\\Illuminate\\Support\\Facades\\DB::table('organization_ai_settings')->where('organization_id',$a->id)->first();
                $existing=\\Illuminate\\Support\\Facades\\DB::table('organization_ai_settings')->where('organization_id',$b->id)->first();
                if (! $existing) {
                    $row=(array)$src; unset($row['id']); $row['organization_id']=$b->id; $row['created_at']=now(); $row['updated_at']=now();
                    $row['id']=(string)\\Illuminate\\Support\\Str::uuid7();
                    \\Illuminate\\Support\\Facades\\DB::table('organization_ai_settings')->insert($row);
                }
                $b->unsetRelation('aiSetting');
                echo json_encode(['inserted' => ! $existing, 'b_org_id' => $b->id, 'a_org_id' => $a->id]);`);
            note(`05b CONFIG B — organization_ai_settings copie sur org-b (inserted=${bConfigured.inserted}) : provider/modele/cle identiques a ArtSciLab, le temps du parcours`);
            let q5b = null; let ledgerB = null; let sqlScope = null; let ledgerBSvc = null;
            try {
                const since5b = new Date().toISOString();
                q5b = await askInB(QUESTION);
                await fp.screenshot({ path: path.join(CAPTURES, '05b-isolation-rag-org-b-configuree-ne-trouve-rien.png') });
                ledgerB = tinker(`
                    $b=\\App\\Models\\Organization::where('slug','${OTHER_ORG_SLUG}')->firstOrFail();
                    $rows=\\App\\Models\\AiProviderInvocation::where('organization_id',$b->id)->where('created_at','>=','${since5b}')->orderBy('created_at')->get();
                    echo json_encode(['count'=>$rows->count(),'rows'=>$rows->map(fn($r)=>['operation'=>$r->operation,'embedding_operation'=>$r->embedding_operation,'capability'=>$r->capability,'credential_source'=>$r->credential_source,'status'=>$r->status])->all()]);`);
                sqlScope = tinker(`
                    $a=\\App\\Models\\Organization::where('slug','${ORG_SLUG}')->firstOrFail();
                    $b=\\App\\Models\\Organization::where('slug','${OTHER_ORG_SLUG}')->firstOrFail();
                    $svc=app(\\App\\Services\\Dossiers\\DossierSemanticSearchService::class);
                    $dossiersA=\\App\\Models\\Dossier::where('organization_id',$a->id)->pluck('id')->map(fn($v)=>(string)$v)->all();
                    $dossiersB=\\App\\Models\\Dossier::where('organization_id',$b->id)->pluck('id')->map(fn($v)=>(string)$v)->all();
                    $q='${QUESTION.replace(/'/g, "\\'")}';
                    $pr=app(\\App\\Ai\\ProviderResolver::class);
                    $instB=$pr->resolveEmbeddingInstance((string)$b->id);
                    $instA=$pr->resolveEmbeddingInstance((string)$a->id);
                    $rowsB=$svc->searchAcrossDossiers((string)$b->id, array_merge($dossiersA,$dossiersB), $q, 5, $instB, ['task' => 'TASK-1234 isolation measure']);
                    $rowsA=$svc->searchAcrossDossiers((string)$a->id, ['${DOSSIER_ID}'], $q, 5, $instA, ['task' => 'TASK-1234 isolation measure']);
                    $db=\\Illuminate\\Support\\Facades\\DB::table('dossier_chunks');
                    echo json_encode([
                        'gate_b' => app(\\App\\Services\\Dossiers\\DossierSemanticSearchGate::class)->isEnabledFor((string)$b->id),
                        'embedding_instance_b' => $instB, 'embedding_instance_a' => $instA,
                        'dossiers_a_passed_to_b' => count($dossiersA), 'dossiers_b' => count($dossiersB),
                        'search_under_B_with_A_dossiers' => ['rows' => count($rowsB), 'contains_test' => str_contains(json_encode($rowsB, JSON_UNESCAPED_UNICODE), 'SÉLÉNITE-4')],
                        'search_under_A' => ['rows' => count($rowsA), 'contains_test' => str_contains(json_encode($rowsA, JSON_UNESCAPED_UNICODE), 'SÉLÉNITE-4')],
                        'a_test_file_chunks' => (clone $db)->where('organization_id',$a->id)->where('dossier_file_id','${createdFileId}')->count(),
                        'test_chunk_visible_from_b_scope' => (clone $db)->where('organization_id',$b->id)->where('dossier_file_id','${createdFileId}')->count(),
                        'b_chunks_total' => (clone $db)->where('organization_id',$b->id)->count(),
                    ]);`, { DOSSIER_SEMANTIC_SEARCH_ORGANIZATION_SLUGS: `${ORG_SLUG},${OTHER_ORG_SLUG}`, DOSSIER_SEMANTIC_SEARCH_ENABLED: 'true' });
                ledgerBSvc = tinker(`
                    $b=\\App\\Models\\Organization::where('slug','${OTHER_ORG_SLUG}')->firstOrFail();
                    $rows=\\App\\Models\\AiProviderInvocation::where('organization_id',$b->id)->where('created_at','>=','${since5b}')->orderBy('created_at')->get();
                    echo json_encode(['count'=>$rows->count(),'rows'=>$rows->map(fn($r)=>['operation'=>$r->operation,'embedding_operation'=>$r->embedding_operation,'capability'=>$r->capability,'credential_source'=>$r->credential_source,'status'=>$r->status])->all()]);`);
            } finally {
                const restored = tinker(`
                    $b=\\App\\Models\\Organization::where('slug','${OTHER_ORG_SLUG}')->firstOrFail();
                    $n=${bConfigured.inserted ? 1 : 0} ? \\Illuminate\\Support\\Facades\\DB::table('organization_ai_settings')->where('organization_id',$b->id)->delete() : 0;
                    echo json_encode(['deleted'=>$n, 'remaining'=>\\Illuminate\\Support\\Facades\\DB::table('organization_ai_settings')->where('organization_id',$b->id)->count()]);`);
                note(`05b RESTAURATION — configuration org-b retiree (deleted=${restored.deleted}, remaining=${restored.remaining})`);
                figures.tenant_restore = restored;
            }
            expect(q5b.status, JSON.stringify(q5b.payload)).toBe(200);
            expect(JSON.stringify(q5b.payload)).not.toContain(SENTINEL_V1);
            expect(JSON.stringify(q5b.payload)).not.toContain('TEST-dod-1234');
            expect((q5b.payload.sources || []).length).toBe(0);
            expect(sqlScope.gate_b, 'pilote ouvert a B pour ce processus de mesure').toBe(true);
            expect(String(sqlScope.embedding_instance_b)).toContain(`org:${bConfigured.b_org_id}:`);
            expect(String(sqlScope.embedding_instance_a)).toContain(`org:${bConfigured.a_org_id}:`);
            expect(sqlScope.dossiers_a_passed_to_b).toBeGreaterThanOrEqual(1);
            expect(sqlScope.search_under_A.rows).toBeGreaterThanOrEqual(1);
            expect(sqlScope.search_under_A.contains_test).toBe(true);
            expect(sqlScope.search_under_B_with_A_dossiers.rows).toBe(0);
            expect(sqlScope.a_test_file_chunks).toBe(1);
            expect(sqlScope.test_chunk_visible_from_b_scope).toBe(0);
            expect(ledgerBSvc.rows.some((r) => r.operation === 'embedding' && r.embedding_operation === 'query' && r.credential_source === 'organization'), 'embedding de requete emis pour B avec le credential de B').toBe(true);
            note(`05b ISOLATION RAG — UI B configuree : HTTP ${q5b.status}, grounded=${q5b.payload.grounded}, sources=${(q5b.payload.sources || []).length} ; ledger UI B: ${JSON.stringify(ledgerB.rows)} ; SERVICE (pilote ouvert a B, meme requete, ${sqlScope.dossiers_a_passed_to_b} Dossiers de A passes a B) : ${JSON.stringify(sqlScope)} ; ledger B apres service : ${JSON.stringify(ledgerBSvc.rows)}`);
            assertClean(fwatch);
            await foreign.close();

            // 05c — P4 : la page reglages IA de Maya montre le credential Organization (masque).
            await login(page, ORG_SLUG, MAYA);
            await page.goto(ORG_AI_PAGE);
            await expect(page.locator('form[action$="/admin/ai"]').first()).toBeVisible();
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(CAPTURES, '05c-p4-credential-organization-maya.png'), fullPage: true });
            const p4 = tinker(`$org=\\App\\Models\\Organization::where('slug','${ORG_SLUG}')->firstOrFail(); $s=\\App\\Models\\OrganizationAiSetting::where('organization_id',$org->id)->first(); $ob=\\App\\Models\\Organization::where('slug','${OTHER_ORG_SLUG}')->firstOrFail(); $sb=\\App\\Models\\OrganizationAiSetting::where('organization_id',$ob->id)->first(); echo json_encode(['artscilab'=>['provider'=>$s->provider,'model'=>$s->model,'has_key'=>filled($s->api_key),'enabled'=>(bool)$s->is_enabled], 'org_b_after_restore'=>$sb ? ['provider'=>$sb->provider,'has_key'=>filled($sb->api_key)] : null]);`);
            expect(p4.org_b_after_restore).toBeNull();
            figures.tenant = {
                permissions: { foreign_source_get: foreignSourceGet, route: s1.url, requested_by: OTHER_MEMBER },
                p4_without_credential: q5a,
                rag_isolation_b_configured: { ui_question: q5b, ledger_b_ui: ledgerB, service_measure: sqlScope, ledger_b_after_service: ledgerBSvc },
                p4,
            };

            // ── 06 Mise a jour (52 minutes) -> reindexation -> question ─────────
            const csrf = await page.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content || '');
            const patch = await page.request.fetch(`${DOSSIER_URL}/files/${createdFileId}/markdown`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, form: { content: content(SENTINEL_V2) } });
            expect(patch.status(), await patch.text()).toBeLessThan(300);
            const reindexed = await waitIndexed(createdFileId, 'has_v2');
            expect(reindexed.timeout, `reindexation non observee: ${JSON.stringify(reindexed)}`).toBeFalsy();
            expect(reindexed.has_v2).toBe(true);
            expect(reindexed.has_v1, 'les anciens chunks sont invalides').toBe(false);
            note(`06 MISE A JOUR — PATCH markdown -> HTTP ${patch.status()} ; chunks: ${reindexed.count}, has_52=${reindexed.has_v2}, has_27=${reindexed.has_v1} (${reindexed.after_ms} ms)`);
            await login(page, ORG_SLUG, JONAS);
            const before06 = counters(JONAS);
            const since06 = new Date().toISOString();
            const q6 = await askKnowledge(page, QUESTION);
            expect(q6.status).toBe(200);
            expect(String(q6.payload.answer)).toContain(SENTINEL_V2);
            expect(String(q6.payload.answer)).not.toContain(SENTINEL_V1);
            expect(q6.payload.grounded).toBe(true);
            const after06 = counters(JONAS);
            const chain06 = chain(JONAS, since06, 'LOOP_KNOWLEDGE_ANSWER');
            writeChain('06-apres-mise-a-jour', chain06, { question: QUESTION, reponse: String(q6.payload.answer).slice(0, 400), credit_avant: before06, credit_apres: after06 });
            expect(after06.credit_used).toBe(before06.credit_used + 2);
            expect(chain06.ledger.some((l) => l.operation === 'generation' && l.credential_source === 'organization')).toBe(true);
            await expect(page.locator('[data-knowledge-answer]')).toContainText(SENTINEL_V2);
            await page.waitForTimeout(1500);
            await page.screenshot({ path: path.join(CAPTURES, '06-mise-a-jour-52-minutes-jamais-27.png') });
            note(`06 QUESTION — « ${String(q6.payload.answer).replace(/\s+/g, ' ').slice(0, 160)} » ; credit ${before06.credit_used} -> ${after06.credit_used}`);
            figures.q06 = { patch: patch.status(), reindex: reindexed, answer: q6.payload.answer, sources: q6.payload.sources, counters_before: before06, counters_after: after06, chain: chain06 };
            await page.keyboard.press('Escape');

            // ── 07 Suppression -> question -> aucune source, aucune erreur ───────
            await login(page, ORG_SLUG, MAYA);
            await page.goto(DOSSIER_URL);
            const csrf2 = await page.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content || '');
            const del = await page.request.delete(`${DOSSIER_URL}/files/${createdFileId}`, { headers: { 'X-CSRF-TOKEN': csrf2, Accept: 'application/json' } });
            expect(del.status(), await del.text()).toBeLessThan(300);
            const purged = await waitNoChunks(createdFileId);
            expect(purged.timeout, `chunks non purges: ${JSON.stringify(purged)}`).toBeFalsy();
            expect(purged.count).toBe(0);
            const deletedFileId = createdFileId;
            createdFileId = null;
            note(`07 SUPPRESSION — DELETE -> HTTP ${del.status()} ; chunks = ${purged.count} (${purged.after_ms} ms)`);
            await login(page, ORG_SLUG, JONAS);
            const before07 = counters(JONAS);
            const since07 = new Date().toISOString();
            const q7 = await askKnowledge(page, QUESTION);
            expect(q7.status).toBe(200);
            expect(JSON.stringify(q7.payload)).not.toContain(SENTINEL_V1);
            expect(JSON.stringify(q7.payload)).not.toContain(SENTINEL_V2);
            expect(JSON.stringify(q7.payload)).not.toContain('TEST-dod-1234');
            expect((q7.payload.sources || []).length).toBe(0);
            const after07 = counters(JONAS);
            const chain07 = chain(JONAS, since07, 'LOOP_KNOWLEDGE_ANSWER');
            writeChain('07-apres-suppression', chain07, { question: QUESTION, reponse: String(q7.payload.answer || '').slice(0, 400), grounded: q7.payload.grounded, sources: (q7.payload.sources || []).length, credit_avant: before07, credit_apres: after07 });
            await page.waitForTimeout(1500);
            await page.screenshot({ path: path.join(CAPTURES, '07-suppression-aucune-trace-aucune-erreur.png') });
            note(`07 QUESTION — grounded=${q7.payload.grounded}, sources=${(q7.payload.sources || []).length}, « ${String(q7.payload.answer || '').replace(/\s+/g, ' ').slice(0, 160)} » ; credit ${before07.credit_used} -> ${after07.credit_used}`);
            figures.q07 = { delete: del.status(), deleted_file_id: deletedFileId, purge: purged, status: q7.status, payload: q7.payload, counters_before: before07, counters_after: after07, chain: chain07 };
            await page.keyboard.press('Escape');

            // ── 08 « Demander a l'IA » canonique (1233) — Jonas, bouton historique ──
            await login(page, ORG_SLUG, MAYA);
            await setOverride(page, 'platform'); // etat de depart connu (aucun override)
            await login(page, ORG_SLUG, JONAS);
            const before08 = counters(JONAS);
            const msgBefore08 = loopMessages();
            const since08 = new Date().toISOString();
            const askStatus = await askAiViaButton(page, ASK_QUESTION);
            await expect(page.locator('body')).toContainText(ASK_QUESTION, { timeout: 30000 });
            const pageText08 = await page.locator('body').innerText();
            expect(pageText08).toMatch(DOCTRINE_MARK);
            await expect(page.locator('[data-ai-refusal-code]')).toHaveCount(0);
            await page.screenshot({ path: path.join(CAPTURES, '08-demander-a-l-ia-reponse-avec-doctrine.png') });
            const after08 = counters(JONAS);
            const msgAfter08 = loopMessages();
            const chain08 = chain(JONAS, since08, 'LOOP_ASK');
            const compAsk = composition('LOOP_ASK', 'chatloop_ai_ask');
            const provAsk = provenanceScope(chain08.metadata?.provenance?.['loop.messages'] || []);
            // Chaine : capability canonique -> Constitution -> doctrine -> prompt administrable ->
            // ContextBuilder (provenance) -> provider Organization -> garde -> SDK -> ledger + trace.
            expect(askStatus, 'POST ask-ai -> redirection (surface historique)').toBe(302);
            expect(after08.credit_used).toBe(before08.credit_used + 1);
            expect(after08.interactions).toBe(before08.interactions + 1);
            expect(after08.ledger).toBe(before08.ledger + 1);
            expect(msgAfter08.messages).toBe(msgBefore08.messages + 2); // question (user) + reponse (ai)
            expect(chain08.feature).toBe('chatloop_ai_ask');
            expect(chain08.process).toBe('chatloop.ask');
            expect(chain08.capability).toBe('loop_ask');
            expect(chain08.can_write).toBe(true);
            expect(chain08.allowed_sources).toEqual(['loop.messages']);
            expect(chain08.metadata.capability).toBe('loop_ask');
            expect(chain08.metadata.status).toBe('success');
            expect(chain08.metadata.sdk_invocation_id, 'invocation SDK (aucun appel HTTP direct)').toBeTruthy();
            expect(chain08.metadata.question).toBe(ASK_QUESTION);
            expect(Array.isArray(chain08.metadata.provenance['loop.messages'])).toBe(true);
            expect(chain08.metadata.provenance['loop.messages'].length).toBeGreaterThan(0);
            expect(chain08.metadata.sources_used).toEqual(['loop.messages']);
            expect(chain08.metadata.sources_denied).toEqual([]);
            expect(provAsk.in_loop_and_org).toBe(provAsk.ids);
            expect(provAsk.outside_org).toBe(0);
            expect(chain08.ledger.length).toBe(1);
            expect(chain08.ledger[0]).toMatchObject({ operation: 'generation', capability: 'loop_ask', process: 'chatloop.ask', credential_source: 'organization', status: 'success' });
            expect(chain08.ledger[0].sdk_invocation_id).toBe(chain08.metadata.sdk_invocation_id);
            expect(chain08.doctrine_active?.version).toBeTruthy();
            expect(String(chain08.response)).toMatch(DOCTRINE_MARK);
            expect(compAsk.starts_with_constitution).toBe(true);
            expect(compAsk.doctrine_block).toBe(true);
            expect(compAsk.capability_line).toBe(true);
            expect(compAsk.prompt_key_line).toBe(true);
            expect(compAsk.order_constitution_doctrine_instructions).toBe(true);
            expect(compAsk.prompt_active).toBe(true);
            // Le message IA publie porte la trace (surface historique + lien vers la trace canonique).
            const published08 = tinker(`$l=\\App\\Models\\Loop::where('slug','${LOOP_SLUG}')->firstOrFail(); $m=\\App\\Models\\LoopMessage::where('loop_id',$l->id)->where('type','ai')->orderByDesc('created_at')->orderByDesc('id')->first(); $q=$m?->reply_to_id ? \\App\\Models\\LoopMessage::find($m->reply_to_id) : null; echo json_encode(['type'=>$m?->type,'action'=>$m?->metadata['action'] ?? null,'ai_interaction_id'=>$m?->metadata['ai_interaction_id'] ?? null,'provider'=>$m?->metadata['provider'] ?? null,'model'=>$m?->metadata['model'] ?? null,'context_message_ids'=>count($m?->metadata['context_message_ids'] ?? []),'reply_to_type'=>$q?->type,'reply_to_body'=>$q?->body,'organization_id'=>$m?->organization_id,'body_has_doctrine_mark'=>(bool) preg_match('/Doctrine ArtSciLab appliquée/u', (string) $m?->body)]);`);
            expect(published08.type).toBe('ai');
            expect(published08.action).toBe('ask');
            expect(published08.reply_to_type).toBe('user');
            expect(published08.reply_to_body).toBe(ASK_QUESTION);
            expect(published08.body_has_doctrine_mark).toBe(true);
            expect(published08.context_message_ids).toBe(chain08.metadata.provenance['loop.messages'].length);
            expect(published08.provider).toBe(chain08.metadata.provider);
            writeChain('08-demander-a-l-ia-loop-ask', chain08, { question: ASK_QUESTION, reponse: String(chain08.response).slice(0, 500), composition_prompt_repository: compAsk, provenance_bornee_au_tenant: provAsk, message_publie: published08, credit_avant: before08, credit_apres: after08, messages_boucle: `${msgBefore08.messages} -> ${msgAfter08.messages}` });
            note(`08 ASK — « ${ASK_QUESTION} » -> reponse publiee avec « Doctrine ArtSciLab appliquée » ; capability loop_ask ; provenance ${provAsk.ids} messages, tous dans la Boucle du tenant ; ledger generation credential organization ; credit ${before08.credit_used} -> ${after08.credit_used} ; messages ${msgBefore08.messages} -> ${msgAfter08.messages} ; correlation ${chain08.correlation_id}`);
            figures.ask = { question: ASK_QUESTION, http: askStatus, counters_before: before08, counters_after: after08, messages_before: msgBefore08.messages, messages_after: msgAfter08.messages, chain: chain08, composition: compAsk, provenance_scope: provAsk, published: published08 };

            // 08b — la seconde face (action=answer, capability loop_answer) par la MEME route.
            const before08b = counters(JONAS);
            const since08b = new Date().toISOString();
            const csrf08 = await page.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content || '');
            const answerPost = await page.request.post(`${LOOP_URL}/ask-ai`, { form: { _token: csrf08, action: 'answer' }, maxRedirects: 0 });
            expect(answerPost.status()).toBe(302);
            await page.goto(LOOP_URL);
            await page.waitForTimeout(1500);
            const after08b = counters(JONAS);
            const chain08b = chain(JONAS, since08b, 'LOOP_ANSWER');
            const compAnswer = composition('LOOP_ANSWER', 'chatloop_ai_answer');
            const provAnswer = provenanceScope(chain08b.metadata?.provenance?.['loop.messages'] || []);
            expect(after08b.credit_used).toBe(before08b.credit_used + 1);
            expect(chain08b.feature).toBe('chatloop_ai_answer');
            expect(chain08b.process).toBe('chatloop.answer');
            expect(chain08b.metadata.capability).toBe('loop_answer');
            expect(chain08b.metadata.status).toBe('success');
            expect(chain08b.metadata.sdk_invocation_id).toBeTruthy();
            expect(chain08b.metadata.question ?? null).toBeNull();
            expect(chain08b.ledger[0]).toMatchObject({ operation: 'generation', capability: 'loop_answer', process: 'chatloop.answer', credential_source: 'organization', status: 'success' });
            expect(provAnswer.in_loop_and_org).toBe(provAnswer.ids);
            expect(provAnswer.outside_org).toBe(0);
            expect(String(chain08b.response)).toMatch(DOCTRINE_MARK);
            expect(compAnswer.starts_with_constitution && compAnswer.doctrine_block && compAnswer.capability_line && compAnswer.prompt_key_line && compAnswer.order_constitution_doctrine_instructions).toBe(true);
            await expect(page.locator('body')).toContainText(/Doctrine ArtSciLab appliquée/);
            await page.screenshot({ path: path.join(CAPTURES, '08b-demander-a-l-ia-face-answer-loop-answer.png') });
            writeChain('08b-demander-a-l-ia-loop-answer', chain08b, { reponse: String(chain08b.response).slice(0, 500), composition_prompt_repository: compAnswer, provenance_bornee_au_tenant: provAnswer, credit_avant: before08b, credit_apres: after08b });
            note(`08b ANSWER — action=answer par la meme route -> HTTP ${answerPost.status()} ; capability loop_answer ; doctrine appliquee ; credit ${before08b.credit_used} -> ${after08b.credit_used} ; correlation ${chain08b.correlation_id}`);
            figures.answer = { http: answerPost.status(), counters_before: before08b, counters_after: after08b, chain: chain08b, composition: compAnswer, provenance_scope: provAnswer };

            // ── 09 UX historique : plafond -> refus AVANT provider, invariance ────
            await login(page, ORG_SLUG, MAYA);
            overrideTouched = true;
            await setOverride(page, 'custom', after08b.credit_used);
            await login(page, ORG_SLUG, JONAS);
            const before09 = counters(JONAS);
            const msgBefore09 = loopMessages();
            const capStatus = await askAiViaButton(page, 'Une question de trop ?');
            const banner = page.locator('[data-ai-refusal-code]').first();
            await expect(banner).toBeVisible();
            await expect(banner).toContainText(/crédit IA du mois est épuisé/);
            await expect(banner.locator('[data-ai-offers-link]')).toBeVisible();
            await page.waitForTimeout(1200);
            await page.screenshot({ path: path.join(CAPTURES, '09-plafond-refus-voir-les-offres.png') });
            const after09 = counters(JONAS);
            const msgAfter09 = loopMessages();
            expect(capStatus).toBe(302);
            expect(after09).toEqual(before09);
            expect(msgAfter09.messages).toBe(msgBefore09.messages);
            expect(await page.getByText('Une question de trop ?').count()).toBe(0);
            note(`09 PLAFOND — refus + Voir les offres ; invariance ledger ${before09.ledger}=${after09.ledger}, interactions ${before09.interactions}=${after09.interactions}, credit ${before09.credit_used}=${after09.credit_used}, messages ${msgBefore09.messages}=${msgAfter09.messages}`);
            fs.writeFileSync(path.join(CAPTURES, 'chaine', '09-plafond-invariance.txt'), [
                `TASK-1234 — « Demander a l'IA » au quota epuise (override Organization = utilisations) : refus AVANT provider — develop ${DEVELOP}.`,
                `AVANT : ledger ${before09.ledger}, ai_interactions ${before09.interactions}, credit ${before09.credit_used}, messages de la Boucle ${msgBefore09.messages}.`,
                'ACTION : bouton « Demander a l\'IA », question saisie, envoi -> bandeau « credit epuise » + « Voir les offres ».',
                `APRES : ledger ${after09.ledger}, ai_interactions ${after09.interactions}, credit ${after09.credit_used}, messages ${msgAfter09.messages}.`,
                'INVARIANCE : identiques, strictement ; aucune question publiee.',
            ].join('\n'));
            figures.cap = { counters_before: before09, counters_after: after09, messages_before: msgBefore09.messages, messages_after: msgAfter09.messages };
            await login(page, ORG_SLUG, MAYA);
            await setOverride(page, 'platform');
            overrideTouched = false;

            // ── 10 « Comportement IA » : couverture ──────────────────────────────
            await page.goto(BEHAVIOR_PAGE);
            const coverage = page.locator('[data-behavior-coverage]');
            await expect(coverage).toBeVisible();
            expect(await page.locator('[data-behavior-coverage-item="loop_answer"][data-behavior-coverage-kind="covered"]').count()).toBe(1);
            expect(await page.locator('[data-behavior-coverage-item="loop_ask"][data-behavior-coverage-kind="covered"]').count()).toBe(1);
            expect(await page.locator('[data-behavior-coverage-item="loop_knowledge_answer"][data-behavior-coverage-kind="covered"]').count()).toBe(1);
            expect(await page.locator('[data-behavior-coverage-item="chatloop_direct_answer"]').count()).toBe(0);
            const inherited = await page.locator('[data-behavior-coverage-kind="inherited"]').evaluateAll((els) => els.map((e) => e.getAttribute('data-behavior-coverage-item')));
            const covered = await coverage.getAttribute('data-behavior-coverage-covered');
            const total = await coverage.getAttribute('data-behavior-coverage-total');
            const registry = tinker(`echo json_encode(['covered'=>array_map(fn($d)=>$d->id, app(\\App\\Ai\\NervousSystemCoverage::class)->covered()),'inherited'=>app(\\App\\Ai\\NervousSystemCoverage::class)->inherited()]);`);
            expect(registry.inherited).not.toContain('chatloop_direct_answer');
            expect(registry.covered).toEqual(expect.arrayContaining(['loop_answer', 'loop_ask', 'loop_knowledge_answer', 'loop_summary', 'clarify_help_request']));
            await coverage.scrollIntoViewIfNeeded();
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(CAPTURES, '10-comportement-ia-couverture.png'), fullPage: true });
            note(`10 COUVERTURE — ${covered} / ${total} ; couvertes ${JSON.stringify(registry.covered)} ; heritees ${JSON.stringify(registry.inherited)} ; chatloop_direct_answer absent des heritees`);
            figures.coverage = { covered, total, covered_ids: registry.covered, inherited_ids: registry.inherited, inherited_ui: inherited };

            figures.finished_at = new Date().toISOString();
            fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(figures, null, 2));
            fs.writeFileSync(path.join(CAPTURES, 'journal.txt'), journal.join('\n'));
            assertClean(watch);
        } finally {
            if (createdFileId) {
                try {
                    await login(page, ORG_SLUG, MAYA);
                    await page.goto(DOSSIER_URL);
                    const t = await page.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content || '');
                    const d = await page.request.delete(`${DOSSIER_URL}/files/${createdFileId}`, { headers: { 'X-CSRF-TOKEN': t, Accept: 'application/json' } });
                    note(`NETTOYAGE (finally) — DELETE ${createdFileId} -> HTTP ${d.status()}`);
                } catch (e) { note(`NETTOYAGE (finally) — echec: ${e.message}`); }
            }
            if (overrideTouched) {
                try { await login(page, ORG_SLUG, MAYA); await setOverride(page, 'platform'); note('NETTOYAGE (finally) — override credit remis sur reglage plateforme'); } catch (e) { note(`NETTOYAGE (finally) — override: ${e.message}`); }
            }
            fs.writeFileSync(path.join(CAPTURES, 'journal.txt'), journal.join('\n'));
            const video = page.video();
            await context.close();
            if (video) { try { await video.saveAs(path.join(CAPTURES, 'recette-dod-finale-systeme-nerveux.webm')); } catch (e) { /* pas de video */ } }
        }
    });
});
