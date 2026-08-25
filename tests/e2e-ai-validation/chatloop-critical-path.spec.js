// TASK-1302 — FILE-1 : E2E VERSIONNE du chemin critique ChatLoop IA, sur le
// banc ai-validation (127.0.0.1:8010, DB bouclepro_ai_validation — arbitrage
// Cyril 25/08 : JAMAIS bouclepro/test20260822).
//
// Parcours protege (T1294 RAG loop-scoped, T1296 sources previewables,
// T1297 persistance unifiee, T1299 /ia, T1300 continuation, T1301 sources
// dans la bulle) :
//
//   login -> Boucle Emergence -> /ia question -> reponse IA -> sources
//   visibles -> source previewable (/preview) -> refresh -> reply au message
//   IA -> continuation -> refresh final
//
// FIXTURE — entierement REUTILISEE, rien d'ajoute : dataset ArtSciLab du banc
// (`ai-validation:reset` + `ai-validation:index-artscilab`). Boucle
// `artscilab-emergence` (type project, jamais agent) ; perimetre RAG = les
// 4 dossiers Emergence (« Session 01 » gouverne + « Session 02/03 »,
// « References » partages avec la Boucle), 4 documents indexes. Document
// pivot : `prototype-observations.txt` (titre public « Prototype
// Observations », 1 chunk : « Observed: visitors asked who selected the data
// and how uncertainty was shown. », text/plain previewable). Les questions
// sont quasi verbatim de ce chunk : il est NECESSAIREMENT retrouve — le
// nombre TOTAL de sources depend en revanche de la distance par question
// (top_k 5, max_distance 0.60) et n'est jamais pin en absolu.
//
// REJOUABILITE — aucune purge DB, aucun compte absolu : etat initial mesure
// (ids de messages, ledger), nouveaux enregistrements identifies par
// DIFFERENCE d'ids + marqueur de run unique dans les questions ; les lignes
// ledger DU RUN sont identifiees par les `ai_interaction_id` persistes en
// metadata des reponses IA (correlation_id exacte), le delta global restant
// une sentinelle secondaire (banc exclusif).
//
// PROVIDER REEL, strictement borne : 2 embeddings (query) + 2 generations
// par run vert — aucune boucle, aucune repetition.
//
// ROUGE AVANT VERT (methode consignee, production jamais mutee dans un
// commit) : la spec a ete demontree rouge contre deux mutations LOCALES
// temporaires puis restaurees —
//   A. retrait de la prop `:sources` de la bulle IA (loop-chat.blade.php,
//      regression T1301) -> echec « bloc SOURCES visible » ;
//   B. `type=ai` -> `type=never-ai` dans LoopChat::continuationParent()
//      (regression T1300) -> echec « continuation : nouvelle reponse IA ».
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/chatloop-critical-path.spec.js
// Secret : env AI_VALIDATION_PASSWORD (convention du banc). Pas de networkidle.

import { test, expect } from '@playwright/test';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1302');
fs.mkdirSync(CAPTURES, { recursive: true });

const ORG_SLUG = 'artscilab-demo';
const LOOP_SLUG = 'artscilab-emergence';
const LOOP_URL = `/org/${ORG_SLUG}/loops/${LOOP_SLUG}`;
const USER = 'jonas@artscilab-demo.test';
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const DOC_TITLE = 'Prototype Observations';
const DOC_DOSSIER = 'Emergence — Session 01';
const DOC_SENTENCE = 'visitors asked who selected the data';
const CAPABILITY = 'loop_knowledge_answer';

// Marqueur unique de run : rend chaque question identifiable dans le fil et
// permet au second run de reussir malgre l'historique du premier. Suffixe
// court pour ne pas peser sur la distance d'embedding (max_distance 0.60).
const RUN_ID = crypto.randomBytes(4).toString('hex');
// Questions quasi verbatim du document : retrieval deterministe (seule source
// du perimetre, distance tres en dessous du seuil calibre 0.60).
const Q1 = `According to the prototype observations, what did visitors ask about who selected the data and how uncertainty was shown? (run ${RUN_ID})`;
const Q2 = `In that same prototype observations document, what exactly did visitors ask about uncertainty in the data? (run ${RUN_ID}-b)`;

const journal = [];
const note = (m) => { journal.push(`[${new Date().toISOString().slice(11, 19)}] ${m}`); console.log(journal.at(-1)); };

// ---------------------------------------------------------------------------
// DB (lecture seule) via tinker APP_ENV=ai-validation — convention du banc
// (T1233). Chaque appel rend UN objet JSON derriere un marqueur dedie.
function db(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', `echo 'E2E_JSON:' . json_encode((function () { ${php} })());`], {
        env: { ...process.env, APP_ENV: 'ai-validation' },
        encoding: 'utf8',
    });
    const m = out.match(/E2E_JSON:(.*)/s);
    if (!m) throw new Error(`tinker: sortie inattendue: ${out}`);
    return JSON.parse(m[1].trim());
}

// Etat initial d'un segment du run : ids de TOUS les messages de la Boucle
// (jamais un compte absolu) + volume ledger de l'Organization + horloge DB
// (bornes du run mesurees cote serveur, pas cote client).
function snapshot() {
    return db(`
        $loop = \\App\\Models\\Loop::where('slug', '${LOOP_SLUG}')->firstOrFail();
        return [
            'now' => now()->format('Y-m-d H:i:s.u'),
            'message_ids' => $loop->messages()->pluck('id')->all(),
            'org_ledger_count' => \\DB::table('ai_provider_invocations')->where('organization_id', $loop->organization_id)->count(),
        ];`);
}

// Messages apparus depuis un snapshot — ordre de creation (UUID v7, ordonnes
// par construction). C'est la SEULE identification des messages du run.
function newMessagesSince(ids) {
    return db(`
        $loop = \\App\\Models\\Loop::where('slug', '${LOOP_SLUG}')->firstOrFail();
        return $loop->messages()
            ->whereNotIn('id', ${JSON.stringify(ids)})
            ->orderBy('id')
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'type' => $m->type,
                'sender_email' => $m->sender?->email,
                'reply_to_id' => $m->reply_to_id,
                'body' => $m->body,
                'metadata' => $m->metadata,
                'deleted' => $m->isDeleted(),
            ])->all();`);
}

// Lignes ledger appartenant AU RUN : correlation exacte via l'ai_interaction
// persistee en metadata de la reponse IA (Organization + user + capability +
// status verifies ligne a ligne).
function ledgerOfInteraction(interactionId) {
    return db(`
        $i = \\App\\Models\\AiInteraction::findOrFail('${interactionId}');
        return [
            'correlation_id' => $i->correlation_id,
            'user_email' => \\App\\Models\\User::find($i->user_id)?->email,
            'rows' => \\App\\Models\\AiProviderInvocation::where('correlation_id', $i->correlation_id)
                ->orderBy('id')
                ->get()
                ->map(fn ($r) => [
                    'operation' => $r->operation,
                    'capability' => $r->capability,
                    'status' => $r->status,
                    'credential_source' => $r->credential_source,
                    'organization_id' => $r->organization_id,
                    'provider' => $r->provider,
                    'model' => $r->model,
                ])->all(),
        ];`);
}

// TOUTES les invocations de l'Organization dans la fenetre du run — la preuve
// « aucune 5e invocation appartenant au parcours » (toutes capabilities).
function orgLedgerBetween(fromDb, toDb) {
    return db(`
        $org = \\App\\Models\\Organization::where('slug', '${ORG_SLUG}')->firstOrFail();
        return \\App\\Models\\AiProviderInvocation::where('organization_id', $org->id)
            ->where('created_at', '>=', '${fromDb}')
            ->where('created_at', '<=', '${toDb}')
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => ['correlation_id' => $r->correlation_id, 'operation' => $r->operation, 'capability' => $r->capability, 'status' => $r->status])
            ->all();`);
}

// Attente EXPLICITE (jamais generique) de la publication de la reponse IA :
// la chaine /ia est synchrone dans la requete Livewire, on borne l'attente et
// on echoue avec un message net — aucun timeout masque.
async function waitForAiReply(page, previousIds, deadlineMs) {
    const deadline = Date.now() + deadlineMs;
    for (;;) {
        const fresh = newMessagesSince(previousIds);
        const ai = fresh.find((m) => m.type === 'ai');
        if (ai) return fresh;
        if (Date.now() > deadline) {
            throw new Error(`Reponse IA absente apres ${deadlineMs / 1000}s — nouveaux messages: ${JSON.stringify(fresh.map((m) => [m.type, m.body?.slice(0, 60)]))}`);
        }
        await page.waitForTimeout(2000);
    }
}

// ---------------------------------------------------------------------------
// Navigateur : console/reseau/downloads surveilles sur TOUT le parcours.
// Filtres repris du banc (T1233) — volontairement minimaux.
function watchBrowser(page) {
    const errors = []; const failed = []; const serverErrors = []; const downloads = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('requestfailed', (r) => {
        const navigationAbortedPoll = /\/livewire[^/]*\/update$/.test(new URL(r.url()).pathname) && r.failure()?.errorText === 'net::ERR_ABORTED';
        if (!/_boost\//.test(r.url()) && !navigationAbortedPoll) failed.push(`${r.method()} ${r.url()} :: ${r.failure()?.errorText}`);
    });
    page.on('response', (r) => { if (r.status() >= 500) serverErrors.push(`${r.status()} ${r.url()}`); });
    page.on('download', (d) => downloads.push(d.suggestedFilename()));
    return { errors, failed, serverErrors, downloads };
}
function assertClean(w) {
    const rel = w.errors.filter((e) => !/favicon|_boost|Failed to send logs|the server responded with a status of 4\d\d/i.test(e));
    expect(rel, rel.join('\n')).toEqual([]);
    expect(w.failed, w.failed.join('\n')).toEqual([]);
    expect(w.serverErrors, w.serverErrors.join('\n')).toEqual([]);
    expect(w.downloads, `telechargement intempestif: ${w.downloads.join(', ')}`).toEqual([]);
}

// Login — selecteur CONTRAINT `form[action*="/login"]` : la page a trois
// formulaires (2 boutons de langue + login), le generique
// `button[type="submit"]` est un piege prouve. Locale FR forcee ensuite
// (libelles asserts en francais), convention du banc.
async function login(page) {
    await page.context().clearCookies();
    await page.goto(`/org/${ORG_SLUG}/login`);
    await page.fill('input[name="email"]', USER);
    await page.fill('input[name="password"]', PASSWORD);
    await page.locator('form[action*="/login"] button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
    await page.waitForLoadState('load');
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

async function sendInComposer(page, body) {
    const composer = page.locator('form[wire\\:submit="sendMessage"]');
    await composer.locator('textarea[wire\\:model="body"]').fill(body);
    await composer.locator('button[type="submit"]').click();
}

const bubble = (page, id) => page.locator(`#loop-message-${id}`);

// Les 4 messages du run apparaissent dans le fil UNE fois chacun, dans
// l'ordre du parcours — verifie apres chaque refresh.
async function assertThreadChain(page, orderedIds) {
    for (const id of orderedIds) {
        await expect(bubble(page, id), `message ${id} absent ou duplique dans le fil`).toHaveCount(1);
        await expect(bubble(page, id)).toBeVisible();
    }
    const domOrder = await page.evaluate((ids) => {
        const all = [...document.querySelectorAll('[id^="loop-message-"]')].map((el) => el.id);
        return ids.map((id) => all.indexOf(`loop-message-${id}`));
    }, orderedIds);
    for (let i = 1; i < domOrder.length; i++) {
        expect(domOrder[i], `ordre du fil incorrect: ${JSON.stringify(domOrder)}`).toBeGreaterThan(domOrder[i - 1]);
    }
}

// Forme PUBLIQUE des sources (T1297/T1301) : non vides, CHAQUE source porte
// exactement les 5 champs publics, rien d'interne (jamais de chunk_id). Le
// document pivot est necessairement present (question quasi verbatim) avec
// son URL previewable servie par le serveur (T1296) — jamais re-derivee.
function assertPublicSources(sources) {
    expect(Array.isArray(sources) && sources.length > 0, 'metadata.sources non vides').toBe(true);
    for (const source of sources) {
        expect(Object.keys(source).sort()).toEqual(['dossier_name', 'excerpt', 'ref', 'title', 'url']);
    }
    expect(JSON.stringify(sources)).not.toMatch(/chunk/i);
    const doc = sources.find((s) => s.title === DOC_TITLE);
    expect(doc, `document pivot absent des sources: ${JSON.stringify(sources.map((s) => s.title))}`).toBeTruthy();
    expect(doc.ref).toMatch(/^S\d$/);
    expect(doc.dossier_name).toBe(DOC_DOSSIER);
    expect(doc.excerpt).toContain('visitors asked');
    expect(doc.url).toMatch(new RegExp(`/org/${ORG_SLUG}/dossiers/[0-9a-f-]+/files/[0-9a-f-]+/preview$`));
    return doc;
}

test('chemin critique ChatLoop IA — /ia, sources, preview, continuation, persistance', async ({ page }) => {
    test.setTimeout(420000);
    const watch = watchBrowser(page);
    note(`run ${RUN_ID} — ${USER} sur ${LOOP_URL}`);

    // -- Etat initial : mesure, jamais presuppose -------------------------
    const before = snapshot();
    note(`etat initial: ${before.message_ids.length} messages, ledger org ${before.org_ledger_count}`);

    await login(page);
    await page.goto(LOOP_URL);
    await expect(page.locator('form[wire\\:submit="sendMessage"]')).toBeVisible();

    // ====================================================================
    // /ia — invocation explicite (T1299) sur la connaissance de la Boucle
    // ====================================================================
    let humanAsk; let aiAnswer;
    await test.step('/ia : question -> 1 message humain + 1 reponse IA sourcee', async () => {
        await sendInComposer(page, `/ia ${Q1}`);
        const fresh = await waitForAiReply(page, before.message_ids, 150000);

        // Exactement 1 nouveau message humain et 1 reponse IA — rien d'autre.
        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [humanAsk, aiAnswer] = fresh;

        // Le message humain : corps persiste TEL QUE TAPE (prefixe compris),
        // provenance slash_ia en metadata (T1299).
        expect(humanAsk.sender_email).toBe(USER);
        expect(humanAsk.body).toBe(`/ia ${Q1}`);
        expect(humanAsk.metadata?.slash_ia).toBe(true);
        expect(humanAsk.deleted).toBe(false);

        // La reponse IA : liee au declencheur par reply_to_id (T1297/T1299),
        // action slash_ia, question depouillee du prefixe, sources publiques.
        expect(aiAnswer.sender_email).toBeNull();
        expect(aiAnswer.reply_to_id).toBe(humanAsk.id);
        expect(aiAnswer.metadata?.action).toBe('slash_ia');
        expect(aiAnswer.metadata?.question).toBe(Q1);
        expect(aiAnswer.metadata?.grounded).toBe(true);
        expect(aiAnswer.metadata?.ai_interaction_id).toBeTruthy();
        assertPublicSources(aiAnswer.metadata?.sources);
        expect(aiAnswer.body.length).toBeGreaterThan(0);
        note(`/ia ok — humain ${humanAsk.id}, ia ${aiAnswer.id}`);
    });

    await test.step('/ia : bloc SOURCES visible dans la bulle (T1301)', async () => {
        const aiBubble = bubble(page, aiAnswer.id);
        await expect(aiBubble).toBeVisible();
        const sourcesBlock = aiBubble.locator('[data-message-sources]');
        await expect(sourcesBlock).toBeVisible({ timeout: 15000 });
        await expect(sourcesBlock).toContainText('Sources utilisées');
        // La bulle est le MIROIR exact de la metadata : autant d'items que de
        // sources publiques, dont le document pivot avec son lien /preview.
        await expect(sourcesBlock.locator('[data-message-source]')).toHaveCount(aiAnswer.metadata.sources.length);
        const docItem = sourcesBlock.locator(`[data-message-source]:has-text("${DOC_TITLE}")`);
        await expect(docItem).toHaveCount(1);
        const openLink = docItem.getByRole('link', { name: 'Ouvrir' });
        await expect(openLink).toHaveAttribute('href', /\/preview$/);
        await page.screenshot({ path: path.join(CAPTURES, `run-${RUN_ID}-01-ia-sources.png`), fullPage: true });
    });

    await test.step('/ia : lignes ledger DU RUN = 1 embedding + 1 generation', async () => {
        const led = ledgerOfInteraction(aiAnswer.metadata.ai_interaction_id);
        expect(led.user_email).toBe(USER);
        expect(led.rows.map((r) => r.operation).sort()).toEqual(['embedding', 'generation']);
        for (const row of led.rows) {
            expect(row.capability).toBe(CAPABILITY);
            expect(row.status).toBe('success');
            expect(row.credential_source).toBe('organization');
        }
        note(`ledger /ia: correlation ${led.correlation_id}, 2 lignes`);
    });

    // ====================================================================
    // Refresh n°1 — la persistance porte l'ecran (T1297)
    // ====================================================================
    await test.step('refresh 1 : question, reponse et sources persistantes, aucun doublon', async () => {
        await page.reload({ waitUntil: 'load' });
        await assertThreadChain(page, [humanAsk.id, aiAnswer.id]);
        await expect(bubble(page, humanAsk.id)).toContainText(`/ia ${Q1}`);
        const sourcesBlock = bubble(page, aiAnswer.id).locator('[data-message-sources]');
        await expect(sourcesBlock).toBeVisible();
        await expect(sourcesBlock).toContainText(DOC_TITLE);
    });

    // ====================================================================
    // Source previewable — clic reel, apercu inline, retour au fil (T1296)
    // ====================================================================
    await test.step('preview : la source s\'ouvre inline, aucun telechargement, retour au fil', async () => {
        const source = aiAnswer.metadata.sources.find((s) => s.title === DOC_TITLE);

        // Le serveur sert bien un apercu inline (regle serveur T1296).
        const resp = await page.request.get(source.url);
        expect(resp.status()).toBe(200);
        expect(resp.headers()['content-type'] || '').toContain('text/plain');
        expect(resp.headers()['content-disposition'] || '').toContain('inline');
        expect(await resp.text()).toContain(DOC_SENTENCE);

        // Le clic REEL depuis la bulle : nouvel onglet sur l'URL /preview,
        // contenu du document visible, aucun download declenche.
        const [popup] = await Promise.all([
            page.waitForEvent('popup'),
            bubble(page, aiAnswer.id).locator(`[data-message-source]:has-text("${DOC_TITLE}")`).getByRole('link', { name: 'Ouvrir' }).click(),
        ]);
        await popup.waitForLoadState('load');
        expect(popup.url()).toBe(new URL(source.url, popup.url()).toString());
        await expect(popup.getByText(DOC_SENTENCE)).toBeVisible();
        await popup.screenshot({ path: path.join(CAPTURES, `run-${RUN_ID}-02-preview.png`) });
        await popup.close();

        // Retour au fil : la page d'origine est intacte.
        expect(new URL(page.url()).pathname).toBe(LOOP_URL);
        await expect(bubble(page, aiAnswer.id)).toBeVisible();
        expect(watch.downloads).toEqual([]);
    });

    // ====================================================================
    // Continuation — reply au message IA (T1300)
    // ====================================================================
    let humanReply; let aiAnswer2;
    await test.step('continuation : reply au message IA -> 1 humain lie + 1 reponse IA liee', async () => {
        const midIds = [...before.message_ids, humanAsk.id, aiAnswer.id];

        await bubble(page, aiAnswer.id).locator('button[aria-label="Répondre"]').click();
        await expect(page.getByText('Réponse à').first()).toBeVisible();
        await sendInComposer(page, Q2);
        const fresh = await waitForAiReply(page, midIds, 150000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [humanReply, aiAnswer2] = fresh;

        // Le reply humain vise le message IA (T1300), provenance persistee.
        expect(humanReply.sender_email).toBe(USER);
        expect(humanReply.reply_to_id).toBe(aiAnswer.id);
        expect(humanReply.body).toBe(Q2);
        expect(humanReply.metadata?.ai_continuation).toBe(true);
        expect(humanReply.metadata?.slash_ia).toBeUndefined();

        // La 2e reponse IA est liee au reply humain, action `continuation`.
        expect(aiAnswer2.sender_email).toBeNull();
        expect(aiAnswer2.reply_to_id).toBe(humanReply.id);
        expect(aiAnswer2.metadata?.action).toBe('continuation');
        expect(aiAnswer2.metadata?.question).toBe(Q2);
        expect(aiAnswer2.metadata?.ai_interaction_id).toBeTruthy();
        assertPublicSources(aiAnswer2.metadata?.sources);

        // Ordre du fil (UUID v7 : l'ordre des ids EST l'ordre de creation).
        const ordered = [humanAsk.id, aiAnswer.id, humanReply.id, aiAnswer2.id];
        expect([...ordered].sort()).toEqual(ordered);
        await assertThreadChain(page, ordered);
        await page.screenshot({ path: path.join(CAPTURES, `run-${RUN_ID}-03-continuation.png`), fullPage: true });
        note(`continuation ok — humain ${humanReply.id}, ia ${aiAnswer2.id}`);
    });

    await test.step('continuation : lignes ledger DU RUN = 1 embedding + 1 generation', async () => {
        const led = ledgerOfInteraction(aiAnswer2.metadata.ai_interaction_id);
        expect(led.user_email).toBe(USER);
        expect(led.rows.map((r) => r.operation).sort()).toEqual(['embedding', 'generation']);
        for (const row of led.rows) {
            expect(row.capability).toBe(CAPABILITY);
            expect(row.status).toBe('success');
            expect(row.credential_source).toBe('organization');
        }
    });

    // ====================================================================
    // Refresh final — la chaine persistante complete, sans fantome
    // ====================================================================
    await test.step('refresh final : chaine /ia -> IA 1 -> continuation -> IA 2, exactement', async () => {
        await page.reload({ waitUntil: 'load' });
        await assertThreadChain(page, [humanAsk.id, aiAnswer.id, humanReply.id, aiAnswer2.id]);
        await expect(bubble(page, aiAnswer2.id).locator('[data-message-sources]')).toBeVisible();

        // Aucun message fantome : le run a produit EXACTEMENT 4 messages.
        const all = newMessagesSince(before.message_ids);
        expect(all.map((m) => [m.type, m.id])).toEqual([
            ['user', humanAsk.id], ['ai', aiAnswer.id], ['user', humanReply.id], ['ai', aiAnswer2.id],
        ]);
        await page.screenshot({ path: path.join(CAPTURES, `run-${RUN_ID}-04-refresh-final.png`), fullPage: true });
    });

    // ====================================================================
    // Invariant economique — les 4 invocations DU RUN, aucune 5e
    // ====================================================================
    await test.step('ledger : exactement 4 invocations appartenant au parcours', async () => {
        const after = snapshot();
        const corr1 = ledgerOfInteraction(aiAnswer.metadata.ai_interaction_id).correlation_id;
        const corr2 = ledgerOfInteraction(aiAnswer2.metadata.ai_interaction_id).correlation_id;
        expect(corr1).not.toBe(corr2);

        // TOUTES les invocations de l'Organization dans la fenetre du run
        // appartiennent aux deux correlations du parcours : 2 embeddings
        // query + 2 generations, rien d'autre, d'aucune capability.
        const window_ = orgLedgerBetween(before.now, after.now);
        expect(window_.length, `invocations hors parcours: ${JSON.stringify(window_)}`).toBe(4);
        for (const row of window_) {
            expect([corr1, corr2]).toContain(row.correlation_id);
            expect(row.capability).toBe(CAPABILITY);
            expect(row.status).toBe('success');
        }
        expect(window_.filter((r) => r.operation === 'embedding').length).toBe(2);
        expect(window_.filter((r) => r.operation === 'generation').length).toBe(2);

        // Sentinelle secondaire (banc exclusif) : delta global +4.
        expect(after.org_ledger_count - before.org_ledger_count).toBe(4);
        note(`ledger: +4 exactement (correlations ${corr1} / ${corr2})`);
    });

    assertClean(watch);
    fs.writeFileSync(path.join(CAPTURES, `run-${RUN_ID}-journal.txt`), journal.join('\n'));
});
