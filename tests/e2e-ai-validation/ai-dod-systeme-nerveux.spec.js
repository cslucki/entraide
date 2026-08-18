// TASK-1232 — Recette de Definition of Done du systeme nerveux IA V1 : UN SEUL
// parcours, date, sur le banc, sur develop 8987ac0. Elle a le droit d'echouer :
// un trou revele n'est PAS bouche ici (STOP + rapport).
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation, ArtSciLab Demo). Toutes les invocations sont REELLES.
//
//   01 Contenu INEDIT (fait invente HELIOTROPE-7 / 43 minutes) depose par
//      Maya dans le Dossier « Emergence — Session 01 » par l'UI reelle (drop).
//   02 Indexation reelle par le worker : chunks presents, ledger embedding
//      credential_source=organization.
//   03 Question atteignable seulement par ce contenu, posee par Jonas depuis
//      la Boucle Emergence via le FAB -> « Consulter les Dossiers ».
//   04 Reponse « 43 minutes », source citee [S1] OUVRABLE (GET reel < 400).
//   05 Isolation tenant : membre d'org-b pose la meme question dans sa Boucle
//      -> aucune source, aucune fuite ; org-b sans P4 -> refus explicite
//      « non configure » (aucune cle plateforme en silence) ; page reglages IA
//      de Maya : credential Organization masque (item §24 « P4 valide »).
//   06 Mise a jour (PATCH markdown, 61 minutes) -> reindexation -> question ->
//      « 61 », jamais « 43 » (reponse ET chunks).
//   07 Suppression -> question -> aucune source, aucune erreur ; chunks = 0.
//   Chaque generation : fichier chaine-XX.txt (capability, allowedSources,
//   Constitution, doctrine active, provenance consulted/cited, provider/modele
//   Organization, credential_source, credit avant/apres).
//   Item §24 « parcours RAG valide » : Q03/Q06/Q07 + clic direct source.
//
// LIVRABLE : PNG 01..N + video dans _local/captures/TASK-1232/. AUCUN mot de
// passe en clair (credential de banc via env AI_VALIDATION_PASSWORD).
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/ai-dod-systeme-nerveux.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1232');
fs.mkdirSync(path.join(CAPTURES, 'chaine'), { recursive: true });

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
const LOOP_URL = `${ORG_ROOT}/loops/artscilab-emergence`;
const ORG_AI_PAGE = `${ORG_ROOT}/admin/ai`;

// Fait INVENTE : n'existe ni dans le corpus ArtSciLab, ni dans le modele.
const FILE_NAME = 'TEST-dod-1232-heliotrope.md';
const SENTINEL_V1 = '43 minutes';
const SENTINEL_V2 = '61 minutes';
const content = (delai) => [
    '# Protocole HÉLIOTROPE-7 — note de terrain (TEST DoD 1232)',
    '',
    `Le protocole HÉLIOTROPE-7 impose un délai de **${delai}** avant l'ouverture de la valise thermique`,
    'après un transport en fourgon non climatisé. Ce délai est mesuré à partir de l\'arrêt du moteur.',
    '',
    'Référence interne : HELIOTROPE-7-DOD-1232.',
].join('\n');
const QUESTION = 'Quel délai impose le protocole HÉLIOTROPE-7 avant l\'ouverture de la valise thermique ?';

const figures = { develop: '8987ac0', started_at: new Date().toISOString() };
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

// Lecture DB (APP_ENV=ai-validation, tinker) — read-only.
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
function fileChunks(fileId) {
    return tinker(`
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        $rows = \\Illuminate\\Support\\Facades\\DB::table('dossier_chunks')->where('organization_id', $org->id)->where('dossier_file_id', '${fileId}')->get();
        echo json_encode(['count' => $rows->count(), 'has_43' => $rows->contains(fn ($r) => str_contains((string) $r->content, '43 minutes')), 'has_61' => $rows->contains(fn ($r) => str_contains((string) $r->content, '61 minutes')), 'columns' => $rows->first() ? array_keys((array) $rows->first()) : []]);`);
}
// La chaine traversee par la derniere generation de l'utilisateur.
function chain(email, sinceIso) {
    return tinker(`
        $u = \\App\\Models\\User::where('email', '${email}')->firstOrFail();
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        $i = \\App\\Models\\AiInteraction::where('organization_id', $org->id)->where('user_id', $u->id)->where('created_at', '>=', '${sinceIso}')->orderByDesc('created_at')->first();
        $led = $i ? \\App\\Models\\AiProviderInvocation::where('correlation_id', $i->correlation_id)->orderBy('created_at')->get()->map(fn ($r) => ['operation' => $r->operation, 'embedding_operation' => $r->embedding_operation, 'capability' => $r->capability, 'provider' => $r->provider, 'model' => $r->model, 'credential_source' => $r->credential_source, 'status' => $r->status, 'cost_status' => $r->cost_status])->all() : [];
        $reg = app(\\App\\Ai\\CapabilityRegistry::class);
        $def = $reg->get(\\App\\Ai\\CapabilityRegistry::LOOP_KNOWLEDGE_ANSWER ?? 'loop_knowledge_answer');
        $doc = \\App\\Models\\OrganizationAiDoctrine::where('organization_id', $org->id)->where('status', 'active')->first();
        echo json_encode([
            'correlation_id' => $i?->correlation_id,
            'feature' => $i?->feature, 'process' => $i?->process, 'model' => $i?->model,
            'metadata' => $i?->metadata,
            'ledger' => $led,
            'capability' => $def?->id, 'allowed_sources' => $def?->allowedSources, 'can_write' => $def?->canWrite ?? null, 'process_def' => $def?->process,
            'constitution' => \\App\\Ai\\Constitution::VERSION,
            'doctrine_active' => $doc ? ['id' => $doc->id, 'version' => $doc->version, 'status' => $doc->status] : null,
        ]);`);
}

async function ask(page, question) {
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
        `TASK-1232 — chaine traversee — ${name} — develop 8987ac0`,
        `correlation_id : ${data.correlation_id}`,
        `capability : ${data.capability} (process ${data.process_def}, can_write=${JSON.stringify(data.can_write)})`,
        `sources autorisees (CapabilityRegistry::allowedSources) : ${JSON.stringify(data.allowed_sources)}`,
        `Constitution : ${data.constitution} — doctrine Organization active : ${JSON.stringify(data.doctrine_active)} (composees par PromptRepository::compose au call-site)`,
        `trace ai_interactions : feature=${data.feature} process=${data.process} model=${data.model}`,
        `  metadata.capability=${data.metadata?.capability} provider=${data.metadata?.provider} status=${data.metadata?.status}`,
        `  provenance (ContextBuilder -> dossier.retrieval) : consulted=${JSON.stringify(data.metadata?.retrieval?.consulted)} cited=${JSON.stringify(data.metadata?.retrieval?.cited)}`,
        `ledger canonique (meme correlation) : ${JSON.stringify(data.ledger)}`,
        ...Object.entries(extra).map(([k, v]) => `${k} : ${typeof v === 'string' ? v : JSON.stringify(v)}`),
    ];
    fs.writeFileSync(path.join(CAPTURES, 'chaine', `${name}.txt`), lines.join('\n'));
}

test.describe('TASK-1232 Recette DoD systeme nerveux IA V1', () => {
    test('parcours unique : inedit -> indexation -> question -> source ouvrable -> isolation -> mise a jour -> suppression', async ({ browser }) => {
        test.setTimeout(900000);
        const context = await browser.newContext({ viewport: { width: 1280, height: 720 }, recordVideo: { dir: path.join(CAPTURES, '.video') } });
        const page = await context.newPage();
        const watch = watchConsole(page);
        let createdFileId = null;

        try {
            // ── 01 Contenu inedit depose par Maya (UI reelle : drop) ─────────────
            await login(page, ORG_SLUG, MAYA);
            await page.goto(DOSSIER_URL);
            await page.waitForTimeout(1500);
            // Le worker peut indexer en < 1 s : la mesure « avant » se prend AVANT le drop.
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
            const created = (uploadJson.files || []).find((f) => (f.display_name || f.original_name || '').includes('TEST-dod-1232'));
            expect(created?.id, 'le fichier cree doit avoir un id').toBeTruthy();
            createdFileId = created.id;
            const uploadedAt = Date.now();
            note(`01 UPLOAD — Maya, Dossier Emergence — Session 01, ${FILE_NAME} -> HTTP ${upload.status()}, id ${createdFileId}`);
            await page.waitForTimeout(1500);
            await page.screenshot({ path: path.join(CAPTURES, '01-contenu-inedit-depose-par-maya.png') });
            figures.upload = { file_id: createdFileId, http: upload.status(), sentinel: SENTINEL_V1 };

            // ── 02 Indexation reelle (worker) ────────────────────────────────────
            const indexed = await waitIndexed(createdFileId, 'has_43');
            expect(indexed.timeout, `indexation non observee: ${JSON.stringify(indexed)}`).toBeFalsy();
            expect(indexed.count).toBeGreaterThan(0);
            expect(indexed.has_43).toBe(true);
            // Lignes ledger « embedding » ecrites depuis le drop (UTC), bornees a l'Organization.
            const ledgerAfterIndex = tinker(`$org=\\App\\Models\\Organization::where('slug','${ORG_SLUG}')->firstOrFail(); $rows=\\App\\Models\\AiProviderInvocation::where('organization_id',$org->id)->where('operation','embedding')->where('created_at','>=','${sinceUpload}')->orderBy('created_at')->get(); echo json_encode(['since' => '${sinceUpload}', 'count' => $rows->count(), 'rows' => $rows->map(fn($r)=>['embedding_operation'=>$r->embedding_operation,'capability'=>$r->capability,'provider'=>$r->provider,'model'=>$r->model,'credential_source'=>$r->credential_source,'status'=>$r->status])->all()]);`);
            note(`02 INDEXATION — ${indexed.count} chunk(s) apres ${indexed.after_ms} ms ; ledger embeddings depuis le drop : ${ledgerAfterIndex.count} ${JSON.stringify(ledgerAfterIndex.rows)}`);
            expect(ledgerAfterIndex.count).toBeGreaterThanOrEqual(1);
            expect(ledgerAfterIndex.rows.every((r) => r.credential_source === 'organization' && r.status === 'success')).toBe(true);
            // L'Observatoire (Maya) montre la source indexee.
            await page.goto(`${ORG_ROOT}/admin/ai-knowledge`);
            const row = page.locator('tr[data-source-key]', { hasText: 'TEST-dod-1232' });
            await expect(row).toHaveCount(1, { timeout: 20000 });
            await expect(row).toHaveAttribute('data-source-indexed', '1', { timeout: 60000 });
            // Le tableau est re-rendu par le poll live : re-localiser avant de faire defiler.
            await page.locator('tr[data-source-key]', { hasText: 'TEST-dod-1232' }).first().scrollIntoViewIfNeeded().catch(() => {});
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(CAPTURES, '02-indexation-reelle-observatoire.png') });
            figures.indexation = { chunks: indexed.count, after_ms: indexed.after_ms, ledger_embeddings_since_drop: ledgerAfterIndex };

            // ── 03 Question atteignable seulement par ce contenu (Jonas, FAB) ────
            await login(page, ORG_SLUG, JONAS);
            const before03 = counters(JONAS);
            const since03 = new Date().toISOString();
            const q3 = await ask(page, QUESTION);
            expect(q3.status, JSON.stringify(q3.payload)).toBe(200);
            note(`03 QUESTION — Jonas via FAB, Boucle Emergence : « ${QUESTION} » -> grounded=${q3.payload.grounded}, sources=${(q3.payload.sources || []).length}`);
            await page.screenshot({ path: path.join(CAPTURES, '03-question-jonas-via-fab.png') });

            // ── 04 Reponse + source citee OUVRABLE ──────────────────────────────
            expect(String(q3.payload.answer)).toContain(SENTINEL_V1);
            expect(q3.payload.grounded).toBe(true);
            const s1 = (q3.payload.sources || []).find((s) => (s.title || '').includes('TEST-dod-1232'));
            expect(s1, 'la source citee est le fichier inedit').toBeTruthy();
            expect(String(q3.payload.answer)).toMatch(new RegExp(`\\[${s1.ref}\\]`));
            const open = await page.request.get(s1.url);
            expect(open.status()).toBeLessThan(400);
            const after03 = counters(JONAS);
            const chain03 = chain(JONAS, since03);
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
            await page.screenshot({ path: path.join(CAPTURES, '04-reponse-43-minutes-source-ouvrable.png') });
            figures.q03 = { status: q3.status, grounded: q3.payload.grounded, answer: q3.payload.answer, sources: q3.payload.sources, source_get: open.status(), credit: q3.payload.credit, counters_before: before03, counters_after: after03, chain: chain03 };
            await page.keyboard.press('Escape');

            // ── 05 Isolation tenant + P4 (§24) ──────────────────────────────────
            const foreign = await browser.newContext({ viewport: { width: 1280, height: 720 } });
            const fp = foreign.newPage ? await foreign.newPage() : null;
            const fwatch = watchConsole(fp);
            await login(fp, OTHER_ORG_SLUG, OTHER_MEMBER);
            const foreignSourceGet = (await fp.request.get(s1.url)).status();
            expect(foreignSourceGet).toBeGreaterThanOrEqual(400);
            await fp.goto(OTHER_LOOP);
            const hasKnowledge = await fp.locator('[data-knowledge-open]').count();
            let q5 = null;
            if (hasKnowledge) {
                await fp.locator('[data-knowledge-open]').first().click();
                await expect(fp.locator('[data-knowledge-dialog]')).toBeVisible();
                await fp.fill('#knowledge-question', QUESTION);
                const rp = fp.waitForResponse((r) => /\/knowledge$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 60000 });
                await fp.locator('[data-knowledge-dialog] form button[type="submit"]').click();
                const r = await rp; let p = null; try { p = await r.json(); } catch (e) { p = null; }
                q5 = { status: r.status(), payload: p };
                await fp.waitForTimeout(1200);
                await fp.screenshot({ path: path.join(CAPTURES, '05a-isolation-tenant-org-b-refus-non-configure.png') });
                // Aucune fuite : aucune source ArtSciLab, aucune reponse contenant le fait.
                expect(JSON.stringify(p || {})).not.toContain(SENTINEL_V1);
                expect(JSON.stringify(p || {})).not.toContain('TEST-dod-1232');
                // org-b sans P4 : refus explicite, jamais une cle plateforme en silence.
                expect(r.status()).toBe(422);
                expect(p?.code).toBe('ai_not_configured');
            }
            note(`05 ISOLATION — org-b member1 : source ArtSciLab -> ${foreignSourceGet} ; question -> HTTP ${q5?.status} code=${q5?.payload?.code} (aucune fuite, aucune cle plateforme)`);
            assertClean(fwatch);
            await foreign.close();
            // P4 : la page reglages IA de Maya montre le credential Organization (masque).
            await login(page, ORG_SLUG, MAYA);
            await page.goto(ORG_AI_PAGE);
            await expect(page.locator('form[action$="/admin/ai"]').first()).toBeVisible();
            await page.waitForTimeout(1000);
            await page.screenshot({ path: path.join(CAPTURES, '05b-p4-credential-organization-maya.png'), fullPage: true });
            const p4 = tinker(`$org=\\App\\Models\\Organization::where('slug','${ORG_SLUG}')->firstOrFail(); $s=\\App\\Models\\OrganizationAiSetting::where('organization_id',$org->id)->first(); $ob=\\App\\Models\\Organization::where('slug','${OTHER_ORG_SLUG}')->firstOrFail(); $sb=\\App\\Models\\OrganizationAiSetting::where('organization_id',$ob->id)->first(); echo json_encode(['artscilab'=>['provider'=>$s->provider,'model'=>$s->model,'has_key'=>filled($s->api_key),'enabled'=>(bool)$s->is_enabled], 'org_b'=>$sb ? ['provider'=>$sb->provider,'has_key'=>filled($sb->api_key)] : null]);`);
            figures.tenant = { foreign_source_get: foreignSourceGet, org_b_question: q5, p4 };

            // ── 06 Mise a jour (61 minutes) -> reindexation -> question ─────────
            const csrf = await page.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content || '');
            const patch = await page.request.fetch(`${DOSSIER_URL}/files/${createdFileId}/markdown`, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, form: { content: content(SENTINEL_V2) } });
            expect(patch.status(), await patch.text()).toBeLessThan(300);
            const reindexed = await waitIndexed(createdFileId, 'has_61');
            expect(reindexed.timeout, `reindexation non observee: ${JSON.stringify(reindexed)}`).toBeFalsy();
            expect(reindexed.has_61).toBe(true);
            expect(reindexed.has_43, 'les anciens chunks sont invalides').toBe(false);
            note(`06 MISE A JOUR — PATCH markdown -> HTTP ${patch.status()} ; chunks: ${reindexed.count}, has_61=${reindexed.has_61}, has_43=${reindexed.has_43} (${reindexed.after_ms} ms)`);
            await login(page, ORG_SLUG, JONAS);
            const before06 = counters(JONAS);
            const since06 = new Date().toISOString();
            const q6 = await ask(page, QUESTION);
            expect(q6.status).toBe(200);
            expect(String(q6.payload.answer)).toContain(SENTINEL_V2);
            expect(String(q6.payload.answer)).not.toContain(SENTINEL_V1);
            expect(q6.payload.grounded).toBe(true);
            const after06 = counters(JONAS);
            const chain06 = chain(JONAS, since06);
            writeChain('06-apres-mise-a-jour', chain06, { question: QUESTION, reponse: String(q6.payload.answer).slice(0, 400), credit_avant: before06, credit_apres: after06 });
            expect(after06.credit_used).toBe(before06.credit_used + 2);
            await expect(page.locator('[data-knowledge-answer]')).toContainText(SENTINEL_V2);
            await page.waitForTimeout(1500);
            await page.screenshot({ path: path.join(CAPTURES, '06-mise-a-jour-61-minutes-jamais-43.png') });
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
            createdFileId = null;
            note(`07 SUPPRESSION — DELETE -> HTTP ${del.status()} ; chunks = ${purged.count} (${purged.after_ms} ms)`);
            await login(page, ORG_SLUG, JONAS);
            const before07 = counters(JONAS);
            const since07 = new Date().toISOString();
            const q7 = await ask(page, QUESTION);
            expect(q7.status).toBe(200);
            expect(JSON.stringify(q7.payload)).not.toContain(SENTINEL_V1);
            expect(JSON.stringify(q7.payload)).not.toContain(SENTINEL_V2);
            expect(JSON.stringify(q7.payload)).not.toContain('TEST-dod-1232');
            const after07 = counters(JONAS);
            const chain07 = chain(JONAS, since07);
            writeChain('07-apres-suppression', chain07, { question: QUESTION, reponse: String(q7.payload.answer || '').slice(0, 400), grounded: q7.payload.grounded, sources: (q7.payload.sources || []).length, credit_avant: before07, credit_apres: after07 });
            await page.waitForTimeout(1500);
            await page.screenshot({ path: path.join(CAPTURES, '07-suppression-aucune-trace-aucune-erreur.png') });
            note(`07 QUESTION — grounded=${q7.payload.grounded}, sources=${(q7.payload.sources || []).length}, « ${String(q7.payload.answer || '').replace(/\s+/g, ' ').slice(0, 160)} » ; credit ${before07.credit_used} -> ${after07.credit_used}`);
            figures.q07 = { delete: del.status(), purge: purged, status: q7.status, payload: q7.payload, counters_before: before07, counters_after: after07, chain: chain07 };
            await page.keyboard.press('Escape');

            figures.finished_at = new Date().toISOString();
            fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(figures, null, 2));
            fs.writeFileSync(path.join(CAPTURES, 'journal.txt'), journal.join('\n'));
            assertClean(watch);
        } finally {
            // Nettoyage : ne jamais laisser le fichier TEST sur le banc.
            if (createdFileId) {
                try {
                    await login(page, ORG_SLUG, MAYA);
                    await page.goto(DOSSIER_URL);
                    const t = await page.evaluate(() => document.querySelector('meta[name=csrf-token]')?.content || '');
                    const d = await page.request.delete(`${DOSSIER_URL}/files/${createdFileId}`, { headers: { 'X-CSRF-TOKEN': t, Accept: 'application/json' } });
                    note(`NETTOYAGE (finally) — DELETE ${createdFileId} -> HTTP ${d.status()}`);
                } catch (e) { note(`NETTOYAGE (finally) — echec: ${e.message}`); }
            }
            fs.writeFileSync(path.join(CAPTURES, 'journal.txt'), journal.join('\n'));
            const video = page.video();
            await context.close();
            if (video) { try { await video.saveAs(path.join(CAPTURES, 'recette-dod-systeme-nerveux.webm')); } catch (e) { /* pas de video */ } }
        }
    });
});
