// TASK-1308 — E2E VERSIONNE du chemin critique ChatLoop HYBRIDE, sur le banc
// ai-validation (127.0.0.1:8010, DB bouclepro_ai_validation — meme convention
// que T1302, jamais bouclepro/test20260822).
//
// REMPLACE `chatloop-critical-path.spec.js` (T1302), dont la premisse (`/ia`
// comme invocation canonique) n'existe plus depuis TASK-1308 : le composeur
// choisit desormais le moteur (IA/Dossiers) EXPLICITEMENT a chaque tour,
// independamment du fil de reponse. UNE seule spec pretend etre le chemin
// critique canonique de ChatLoop.
//
// Parcours protege :
//
//   Scenario A (IA -> IA -> Dossiers, meme fil de reponse) :
//     login -> Boucle Emergence -> mode IA -> question -> reponse
//     Organization · IA -> Repondre -> continuation IA coherente (contexte
//     transmis) -> Repondre -> switch Dossiers DANS CE MEME reply thread ->
//     question documentaire -> reponse Organization · Dossiers -> sources
//     visibles -> preview HTTP 200 -> retour -> refresh -> persistance
//     complete (4 messages, modes, sources).
//
//   Scenario B (Dossiers -> IA, direction inverse) :
//     mode Dossiers -> question documentaire -> reponse sourcee -> Repondre
//     -> switch IA -> continuation LLM avec le contexte de la reponse RAG.
//
//   Scenario C (TASK-1309 — le 4e moteur dans le meme fil) :
//     mode Dossiers -> reponse sourcee -> Repondre -> les DEUX moteurs
//     allumes (IA + Dossiers) -> reponse croisee, identite
//     `Organization · IA + Dossiers`, sources CITEES seulement -> Repondre
//     -> IA seule -> Repondre -> Dossiers seuls. Un fil, quatre tours, le
//     moteur rechoisi a chaque fois. Refresh : tout persiste.
//
// FIXTURE — entierement REUTILISEE (dataset ArtSciLab, aucune purge, aucun
// ajout) : Boucle `artscilab-emergence`, 4 dossiers Emergence, document pivot
// `prototype-observations.txt` (titre public « Prototype Observations »,
// texte quasi verbatim NECESSAIREMENT retrouve par la recherche reelle).
//
// PROVIDER REEL, borne : Scenario A = 1 (IA) + 1 (IA reply) + 2 (Dossiers :
// embedding + generation) = 4 invocations. Scenario B = 2 (Dossiers) + 1 (IA
// reply) = 3 invocations. Scenario C = 2 (Dossiers) + 2 (IA + Dossiers :
// embedding + generation) + 1 (IA) + 2 (Dossiers) = 7 invocations. 14 au
// total pour le fichier — aucune boucle, aucune repetition.
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/chatloop-hybrid-ai-critical-path.spec.js
// Secret : env AI_VALIDATION_PASSWORD (convention du banc). Pas de networkidle.

import { test, expect } from '@playwright/test';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1308-e2e');
fs.mkdirSync(CAPTURES, { recursive: true });

const ORG_SLUG = 'artscilab-demo';
const LOOP_SLUG = 'artscilab-emergence';
const LOOP_URL = `/org/${ORG_SLUG}/loops/${LOOP_SLUG}`;
const USER = 'jonas@artscilab-demo.test';
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const DOC_TITLE = 'Prototype Observations';
const DOC_DOSSIER = 'Emergence — Session 01';
const DOC_SENTENCE = 'visitors asked who selected the data';
const RAG_CAPABILITY = 'loop_knowledge_answer';
const LLM_CAPABILITY = 'loop_ask';
// TASK-1309 : le 4e moteur trace sa PROPRE capability dans le ledger,
// tout en partageant le process economique du mode Dossiers.
const HYBRID_CAPABILITY = 'loop_hybrid_answer';

const RUN_ID = crypto.randomBytes(4).toString('hex');
const Q_DOC_A = `According to the prototype observations, what did visitors ask about who selected the data and how uncertainty was shown? (run ${RUN_ID}-a)`;
const Q_DOC_B = `In that same prototype observations document, what exactly did visitors ask about uncertainty in the data? (run ${RUN_ID}-b)`;
const Q_DOC_C = `What do the prototype observations say about what visitors asked regarding who selected the data? (run ${RUN_ID}-c)`;
const Q_HYBRID_C = `Compare what our documents say about those visitor reactions with what you know about museum audience studies in general. (run ${RUN_ID}-c2)`;

const journal = [];
const note = (m) => { journal.push(`[${new Date().toISOString().slice(11, 19)}] ${m}`); console.log(journal.at(-1)); };

// ---------------------------------------------------------------------------
// DB (lecture seule) via tinker APP_ENV=ai-validation — convention du banc
// (T1233/T1302). Chaque appel rend UN objet JSON derriere un marqueur dedie.
function db(php) {
    const out = execFileSync('php', ['artisan', 'tinker', '--execute', `echo 'E2E_JSON:' . json_encode((function () { ${php} })());`], {
        env: { ...process.env, APP_ENV: 'ai-validation' },
        encoding: 'utf8',
    });
    const m = out.match(/E2E_JSON:(.*)/s);
    if (!m) throw new Error(`tinker: sortie inattendue: ${out}`);
    return JSON.parse(m[1].trim());
}

function snapshot() {
    return db(`
        $loop = \\App\\Models\\Loop::where('slug', '${LOOP_SLUG}')->firstOrFail();
        return [
            'now' => now()->format('Y-m-d H:i:s.u'),
            'organization_name' => $loop->organization->name,
            'message_ids' => $loop->messages()->pluck('id')->all(),
            'org_ledger_count' => \\DB::table('ai_provider_invocations')->where('organization_id', $loop->organization_id)->count(),
        ];`);
}

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

// Attente EXPLICITE (jamais generique) de la publication de la reponse IA.
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

// Login — selecteur CONTRAINT `form[action*="/login"]` : la page a plusieurs
// formulaires (boutons de langue + login), le generique `button[type=submit]`
// est un piege prouve (T1233/T1302).
async function login(page) {
    await page.context().clearCookies();
    await page.goto(`/org/${ORG_SLUG}/login`);
    await page.fill('input[name="email"]', USER);
    await page.fill('input[name="password"]', PASSWORD);
    await page.locator('form[action*="/login"] button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.endsWith('/login'));
    await page.waitForLoadState('load');
    // Locale FR forcee : libelles asserts en francais (convention du banc,
    // T1233/T1302) — sans elle, le locale par defaut du compte (EN sur ce
    // run) rend "Dossiers"/"Sources utilisées" en anglais.
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

// TASK-1308 : le moteur du tour est un choix EXPLICITE du composeur, jamais
// un texte special dans le corps.
//
// TASK-1309 : les DEUX actions sont devenues des INTERRUPTEURS combinables —
// `toggleComposerEngine('ia'|'dossiers')`, quatre etats accessibles. On cible
// desormais l'attribut stable `data-engine-toggle` plutot que l'expression
// `wire:click` (qui a change) : l'ancre du test suit l'intention du bouton,
// pas la forme de son implementation. `:visible` desambiguise le bouton de la
// barre desktop de son jumeau, present mais masque, dans le bottom sheet
// mobile.
//
// `mode` est l'ETAT VOULU du composeur ('normal'|'ia'|'dossiers'|
// 'ia_dossiers') : la fonction n'allume/eteint que ce qui doit l'etre, donc
// elle est idempotente et sure quel que soit le mode presélectionné par un
// reply.
async function selectMode(page, mode) {
    const wanted = { normal: [], ia: ['ia'], dossiers: ['dossiers'], ia_dossiers: ['ia', 'dossiers'] }[mode];
    if (!wanted) throw new Error(`mode inconnu: ${mode}`);

    for (const engine of ['ia', 'dossiers']) {
        const button = page.locator(`button[data-engine-toggle="${engine}"]:visible`).first();
        const pressed = (await button.getAttribute('aria-pressed')) === 'true';
        if (pressed !== wanted.includes(engine)) {
            await button.click();
            await page.waitForTimeout(300);
        }
    }

    for (const engine of ['ia', 'dossiers']) {
        await expect(
            page.locator(`button[data-engine-toggle="${engine}"]:visible`).first(),
            `moteur ${engine} dans le mauvais etat pour le mode ${mode}`,
        ).toHaveAttribute('aria-pressed', wanted.includes(engine) ? 'true' : 'false');
    }
}

async function replyTo(page, messageId) {
    await bubble(page, messageId).locator('[aria-label="Répondre"]').first().click();
    await expect(page.getByText('Réponse à').first()).toBeVisible();
}

const bubble = (page, id) => page.locator(`#loop-message-${id}`);

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

// Forme PUBLIQUE des sources (T1297/T1301) : 5 champs exacts, rien d'interne.
function assertPublicSources(sources) {
    expect(Array.isArray(sources) && sources.length > 0, 'liste de sources non vide').toBe(true);
    for (const source of sources) {
        expect(Object.keys(source).sort()).toEqual(['dossier_name', 'excerpt', 'ref', 'title', 'url']);
    }
    expect(JSON.stringify(sources)).not.toMatch(/chunk/i);
}

// TASK-1309 — la provenance d'une reponse documentaire, dans ses DEUX etats
// possibles, mutuellement exclusifs :
//
//   `sources`   = ce qui a REELLEMENT soutenu une affirmation (« Sources
//                 utilisées ») — chaque entree DOIT etre citee dans le corps ;
//   `consulted` = a defaut, les documents dont le contenu a ete lu sans
//                 qu'aucune citation valide n'en sorte (« Sources
//                 consultées »).
//
// Pourquoi les deux : constate en recette reelle (run 2b66b90e), un modele
// peut produire une reponse parfaitement fondee SANS ecrire son marqueur
// `[Sn]`. Epingler l'un des deux etats rendrait cette spec dependante de la
// discipline de citation du modele, pas du produit. Ce qui est INVARIANT —
// et donc ce qu'on assert — c'est qu'une provenance existe, qu'elle est
// affichee sous le BON titre, et qu'une source dite « utilisee » est
// toujours citee dans le texte.
async function assertProvenance(page, message, { atLeastOneDocument = true } = {}) {
    const used = message.metadata?.sources ?? [];
    const consulted = message.metadata?.consulted ?? [];
    const block = bubble(page, message.id).locator('[data-message-sources]');

    if (used.length > 0) {
        assertPublicSources(used);
        for (const source of used) {
            expect(message.body, `source [${source.ref}] publiee comme UTILISEE sans etre citee`).toContain(`[${source.ref}]`);
        }
        expect(consulted, 'les deux listes ne coexistent jamais').toEqual([]);
        await expect(block).toHaveAttribute('data-sources-kind', 'used');
        await expect(block.locator('[data-message-source]')).toHaveCount(used.length);
        return used;
    }

    if (atLeastOneDocument) {
        assertPublicSources(consulted);
    }
    if (consulted.length > 0) {
        await expect(block).toHaveAttribute('data-sources-kind', 'consulted');
        await expect(block.locator('[data-message-source]')).toHaveCount(consulted.length);
    } else {
        await expect(block).toHaveCount(0);
    }

    return consulted;
}

// ===========================================================================
// Scenario A — IA -> IA -> Dossiers, le switch se fait DANS LE MEME reply
// thread (le trigger de l'appel Dossiers est le reply a la 2e reponse IA).
// ===========================================================================
test('chemin critique hybride A — IA, continuation, switch Dossiers dans le meme fil', async ({ page }) => {
    test.setTimeout(420000);
    const watch = watchBrowser(page);
    note(`run ${RUN_ID}-A — ${USER} sur ${LOOP_URL}`);

    const before = snapshot();
    const orgName = before.organization_name;
    note(`Organization: "${orgName}" — etat initial ${before.message_ids.length} messages, ledger ${before.org_ledger_count}`);

    await login(page);
    await page.goto(LOOP_URL);
    await expect(page.locator('form[wire\\:submit="sendMessage"]')).toBeVisible();

    // ------------------------------------------------------------------
    // Tour 1 — mode IA, question libre (aucun RAG).
    // ------------------------------------------------------------------
    let question1; let answer1;
    await test.step('mode IA : question -> reponse LLM directe, aucune source', async () => {
        await selectMode(page, 'ia');
        await sendInComposer(page, `Quelle est la capitale de la France ? (run ${RUN_ID})`);
        const fresh = await waitForAiReply(page, before.message_ids, 90000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [question1, answer1] = fresh;

        expect(question1.sender_email).toBe(USER);
        expect(question1.metadata?.requested_mode).toBe('ia');
        expect(answer1.reply_to_id).toBe(question1.id);
        expect(answer1.metadata?.ai_mode).toBe('llm');
        expect(answer1.metadata?.action).toBe('ia');
        expect(answer1.metadata?.sources).toBeUndefined();
        expect(answer1.body.toLowerCase()).toContain('paris');

        await expect(bubble(page, answer1.id)).toContainText(`${orgName} · IA`);
        note(`tour 1 (IA) ok — humain ${question1.id}, ia ${answer1.id}`);
    });

    // ------------------------------------------------------------------
    // Tour 2 — Repondre a la bulle IA -> continuation LLM->LLM.
    // ------------------------------------------------------------------
    let question2; let answer2;
    await test.step('Repondre : continuation IA coherente (contexte transmis)', async () => {
        const before2 = [...before.message_ids, question1.id, answer1.id];
        await replyTo(page, answer1.id);
        // Le mode est PRESELECTIONNE depuis le parent (ai_mode=llm) : IA
        // reste actif sans action supplementaire — visible et modifiable.
        await sendInComposer(page, "Combien d'habitants environ ?");
        const fresh = await waitForAiReply(page, before2, 90000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [question2, answer2] = fresh;

        expect(question2.reply_to_id).toBe(answer1.id);
        expect(answer2.reply_to_id).toBe(question2.id);
        expect(answer2.metadata?.ai_mode).toBe('llm');
        // Preuve DETERMINISTE que le contexte a ete transmis : la reponse
        // pointe la chaine qui remonte jusqu'a la premiere reponse IA.
        expect(answer2.metadata?.context_message_ids).toContain(answer1.id);
        note(`tour 2 (continuation IA) ok — humain ${question2.id}, ia ${answer2.id}`);
    });

    // ------------------------------------------------------------------
    // Tour 3 — Repondre a la 2e bulle IA, SWITCH explicite vers Dossiers.
    // ------------------------------------------------------------------
    let question3; let answer3; let provenance3;
    await test.step('switch Dossiers (meme reply thread) : reponse sourcee, sources visibles', async () => {
        const before3 = [...before.message_ids, question1.id, answer1.id, question2.id, answer2.id];
        await replyTo(page, answer2.id);
        await selectMode(page, 'dossiers');
        await sendInComposer(page, Q_DOC_A);
        const fresh = await waitForAiReply(page, before3, 150000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [question3, answer3] = fresh;

        expect(question3.reply_to_id).toBe(answer2.id);
        expect(question3.metadata?.requested_mode).toBe('dossiers');
        expect(answer3.reply_to_id).toBe(question3.id);
        expect(answer3.metadata?.ai_mode).toBe('rag');
        expect(answer3.metadata?.action).toBe('dossiers');
        await expect(bubble(page, answer3.id)).toContainText(`${orgName} · Dossiers`);
        await expect(bubble(page, answer3.id).locator('[data-message-sources]')).toBeVisible({ timeout: 15000 });
        provenance3 = await assertProvenance(page, answer3);

        const doc = provenance3.find((s) => s.title === DOC_TITLE);
        expect(doc, `document pivot absent: ${JSON.stringify(provenance3.map((s) => s.title))}`).toBeTruthy();
        expect(doc.dossier_name).toBe(DOC_DOSSIER);
        expect(doc.url).toMatch(new RegExp(`/org/${ORG_SLUG}/dossiers/[0-9a-f-]+/files/[0-9a-f-]+/preview$`));
        await page.screenshot({ path: path.join(CAPTURES, `run-${RUN_ID}-A-01-switch-to-dossiers.png`), fullPage: true });
        note(`tour 3 (switch Dossiers) ok — humain ${question3.id}, ia ${answer3.id}`);
    });

    await test.step('preview : la source pivot s\'ouvre en HTTP 200, retour au fil', async () => {
        const doc = provenance3.find((s) => s.title === DOC_TITLE);
        const resp = await page.request.get(doc.url);
        expect(resp.status()).toBe(200);
        expect(await resp.text()).toContain(DOC_SENTENCE);

        const [popup] = await Promise.all([
            page.waitForEvent('popup'),
            bubble(page, answer3.id).locator(`[data-message-source]:has-text("${DOC_TITLE}")`).getByRole('link', { name: 'Ouvrir' }).click(),
        ]);
        await popup.waitForLoadState('load');
        await expect(popup.getByText(DOC_SENTENCE)).toBeVisible();
        await popup.close();

        expect(new URL(page.url()).pathname).toBe(LOOP_URL);
        expect(watch.downloads).toEqual([]);
    });

    await test.step('refresh : les 4 messages, modes et sources persistent, sans doublon', async () => {
        await page.reload({ waitUntil: 'load' });
        const ordered = [question1.id, answer1.id, question2.id, answer2.id, question3.id, answer3.id];
        await assertThreadChain(page, ordered);
        await expect(bubble(page, answer1.id)).toContainText(`${orgName} · IA`);
        await expect(bubble(page, answer3.id)).toContainText(`${orgName} · Dossiers`);
        await expect(bubble(page, answer3.id).locator('[data-message-sources]')).toBeVisible();

        const all = newMessagesSince(before.message_ids);
        expect(all.map((m) => m.id)).toEqual(ordered);
        await page.screenshot({ path: path.join(CAPTURES, `run-${RUN_ID}-A-02-refresh-final.png`), fullPage: true });
    });

    await test.step('ledger : 1 (IA) + 1 (IA reply) + 2 (Dossiers) invocations exactement', async () => {
        const after = snapshot();
        const window_ = orgLedgerBetween(before.now, after.now);
        expect(window_.length, `invocations hors parcours: ${JSON.stringify(window_)}`).toBe(4);
        expect(window_.filter((r) => r.capability === LLM_CAPABILITY).length).toBe(2);
        expect(window_.filter((r) => r.capability === RAG_CAPABILITY).length).toBe(2);
        expect(window_.filter((r) => r.operation === 'embedding').length).toBe(1);
        expect(window_.filter((r) => r.operation === 'generation').length).toBe(3);
        for (const row of window_) expect(row.status).toBe('success');
        expect(after.org_ledger_count - before.org_ledger_count).toBe(4);
        note(`ledger scenario A: +4 exactement`);
    });

    assertClean(watch);
    fs.writeFileSync(path.join(CAPTURES, `run-${RUN_ID}-A-journal.txt`), journal.join('\n'));
});

// ===========================================================================
// Scenario B — direction inverse : Dossiers -> Repondre -> switch IA.
// ===========================================================================
test('chemin critique hybride B — Dossiers puis switch IA sur le meme reply thread', async ({ page }) => {
    test.setTimeout(300000);
    const watch = watchBrowser(page);
    note(`run ${RUN_ID}-B — ${USER} sur ${LOOP_URL}`);

    const before = snapshot();
    const orgName = before.organization_name;

    await login(page);
    await page.goto(LOOP_URL);
    await expect(page.locator('form[wire\\:submit="sendMessage"]')).toBeVisible();

    let question1; let answer1;
    await test.step('mode Dossiers : question documentaire -> reponse sourcee', async () => {
        await selectMode(page, 'dossiers');
        await sendInComposer(page, Q_DOC_B);
        const fresh = await waitForAiReply(page, before.message_ids, 150000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [question1, answer1] = fresh;

        expect(question1.metadata?.requested_mode).toBe('dossiers');
        expect(answer1.metadata?.ai_mode).toBe('rag');
        await expect(bubble(page, answer1.id)).toContainText(`${orgName} · Dossiers`);
        await assertProvenance(page, answer1);
        note(`tour 1 (Dossiers) ok — humain ${question1.id}, ia ${answer1.id}`);
    });

    let question2; let answer2;
    await test.step('Repondre + switch IA : continuation LLM avec le contexte de la reponse RAG', async () => {
        const before2 = [...before.message_ids, question1.id, answer1.id];
        await replyTo(page, answer1.id);
        // Le parent est `ai_mode=rag` : Dossiers est PRESELECTIONNE, mais le
        // membre bascule explicitement vers IA avant d'envoyer.
        await selectMode(page, 'ia');
        await sendInComposer(page, 'Peux-tu reformuler cela plus simplement ?');
        const fresh = await waitForAiReply(page, before2, 90000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [question2, answer2] = fresh;

        expect(question2.reply_to_id).toBe(answer1.id);
        expect(answer2.reply_to_id).toBe(question2.id);
        expect(answer2.metadata?.ai_mode).toBe('llm');
        expect(answer2.metadata?.sources).toBeUndefined();
        // Le contexte transmis remonte bien jusqu'a la reponse Dossiers —
        // mais celle-ci ne devient JAMAIS une source de CETTE reponse LLM.
        expect(answer2.metadata?.context_message_ids).toContain(answer1.id);
        await expect(bubble(page, answer2.id)).toContainText(`${orgName} · IA`);
        note(`tour 2 (switch IA) ok — humain ${question2.id}, ia ${answer2.id}`);
    });

    await test.step('refresh : persistance des 4 messages, sans doublon', async () => {
        await page.reload({ waitUntil: 'load' });
        const ordered = [question1.id, answer1.id, question2.id, answer2.id];
        await assertThreadChain(page, ordered);
        await page.screenshot({ path: path.join(CAPTURES, `run-${RUN_ID}-B-01-refresh-final.png`), fullPage: true });
    });

    await test.step('ledger : 2 (Dossiers) + 1 (IA reply) invocations exactement', async () => {
        const after = snapshot();
        const window_ = orgLedgerBetween(before.now, after.now);
        expect(window_.length, `invocations hors parcours: ${JSON.stringify(window_)}`).toBe(3);
        expect(window_.filter((r) => r.capability === RAG_CAPABILITY).length).toBe(2);
        expect(window_.filter((r) => r.capability === LLM_CAPABILITY).length).toBe(1);
        for (const row of window_) expect(row.status).toBe('success');
        expect(after.org_ledger_count - before.org_ledger_count).toBe(3);
        note(`ledger scenario B: +3 exactement`);
    });

    assertClean(watch);
    fs.writeFileSync(path.join(CAPTURES, `run-${RUN_ID}-B-journal.txt`), journal.join('\n'));
});

// ===========================================================================
// Scenario C (TASK-1309) — le QUATRIEME moteur, dans un seul fil de reponse.
//
// Dossiers -> IA + Dossiers -> IA -> Dossiers. Ce que ce scenario protege et
// que ni A ni B ne peuvent prouver : que le mode croise EXISTE cote produit
// (identite de bulle, `ai_mode = llm_rag`), qu'il s'atteint en combinant les
// DEUX actions existantes, qu'il ne coute qu'UN tour de generation, que ses
// sources publiees sont les sources REELLEMENT citees, et qu'on en sort aussi
// librement qu'on y entre.
// ===========================================================================
test('chemin critique hybride C — Dossiers, IA + Dossiers, IA, Dossiers dans un seul fil', async ({ page }) => {
    test.setTimeout(600000);
    const watch = watchBrowser(page);
    note(`run ${RUN_ID}-C — ${USER} sur ${LOOP_URL}`);

    const before = snapshot();
    const orgName = before.organization_name;

    await login(page);
    await page.goto(LOOP_URL);
    await expect(page.locator('form[wire\\:submit="sendMessage"]')).toBeVisible();

    let q1; let a1;
    await test.step('tour 1 — Dossiers : reponse sourcee', async () => {
        await selectMode(page, 'dossiers');
        await sendInComposer(page, Q_DOC_C);
        const fresh = await waitForAiReply(page, before.message_ids, 150000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [q1, a1] = fresh;
        expect(a1.metadata?.ai_mode).toBe('rag');
        await expect(bubble(page, a1.id)).toContainText(`${orgName} · Dossiers`);
        await assertProvenance(page, a1);
        note(`tour 1 (Dossiers) ok — ${a1.id}`);
    });

    let q2; let a2;
    await test.step('tour 2 — Repondre + allumer AUSSI l\'IA : reponse croisee', async () => {
        const seen = [...before.message_ids, q1.id, a1.id];
        await replyTo(page, a1.id);
        // Le parent est `rag` : Dossiers est deja presélectionné. Allumer
        // l'IA par-dessus donne le 4e etat — c'est TOUT le geste produit.
        await selectMode(page, 'ia_dossiers');
        await expect(page.locator('[data-hybrid-indicator]')).toBeVisible();
        await sendInComposer(page, Q_HYBRID_C);
        const fresh = await waitForAiReply(page, seen, 180000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [q2, a2] = fresh;

        expect(q2.reply_to_id).toBe(a1.id);
        expect(q2.metadata?.requested_mode).toBe('ia_dossiers');
        expect(a2.reply_to_id).toBe(q2.id);
        expect(a2.metadata?.ai_mode).toBe('llm_rag');
        expect(a2.metadata?.action).toBe('ia_dossiers');
        // Le contexte du fil remonte a la reponse Dossiers precedente sans
        // jamais en faire une source de CE tour.
        expect(a2.metadata?.context_message_ids).toContain(a1.id);

        // UN SEUL tour de generation : jamais « IA puis Dossiers ».
        expect(fresh.filter((m) => m.type === 'ai').length).toBe(1);

        // Provenance : ce qui est presente comme UTILISE est ce qui est CITE.
        // Le mode croise peut legitimement ne RIEN citer — une reponse de
        // connaissance generale n'a aucune source documentaire, et ne doit
        // surtout pas s'en inventer : `atLeastOneDocument: false`.
        await expect(bubble(page, a2.id)).toContainText(`${orgName} · IA + Dossiers`);
        const provenance2 = await assertProvenance(page, a2, { atLeastOneDocument: false });

        await page.screenshot({ path: path.join(CAPTURES, `run-${RUN_ID}-C-01-hybrid-answer.png`), fullPage: true });
        note(`tour 2 (IA + Dossiers) ok — ${a2.id}, provenance: ${provenance2.length} entree(s)`);
    });

    let q3; let a3;
    await test.step('tour 3 — Repondre + eteindre les Dossiers : IA seule', async () => {
        const seen = [...before.message_ids, q1.id, a1.id, q2.id, a2.id];
        await replyTo(page, a2.id);
        // Repondre a une bulle croisee PRESELECTIONNE le mode croise...
        await expect(page.locator('[data-hybrid-indicator]')).toBeVisible();
        // ... et il ne verrouille rien : on eteint les Dossiers.
        await selectMode(page, 'ia');
        await sendInComposer(page, 'Reformule cela en deux phrases simples.');
        const fresh = await waitForAiReply(page, seen, 90000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [q3, a3] = fresh;
        expect(q3.reply_to_id).toBe(a2.id);
        expect(a3.metadata?.ai_mode).toBe('llm');
        expect(a3.metadata?.sources).toBeUndefined();
        await expect(bubble(page, a3.id)).toContainText(`${orgName} · IA`);
        note(`tour 3 (IA seule) ok — ${a3.id}`);
    });

    let q4; let a4;
    await test.step('tour 4 — Repondre + rallumer les Dossiers seuls : regrounding reel', async () => {
        const seen = [...before.message_ids, q1.id, a1.id, q2.id, a2.id, q3.id, a3.id];
        await replyTo(page, a3.id);
        await selectMode(page, 'dossiers');
        await sendInComposer(page, Q_DOC_C);
        const fresh = await waitForAiReply(page, seen, 150000);

        expect(fresh.map((m) => m.type)).toEqual(['user', 'ai']);
        [q4, a4] = fresh;
        expect(q4.reply_to_id).toBe(a3.id);
        expect(a4.metadata?.ai_mode).toBe('rag');
        await expect(bubble(page, a4.id)).toContainText(`${orgName} · Dossiers`);
        note(`tour 4 (Dossiers) ok — ${a4.id}`);
    });

    await test.step('refresh : les 8 messages du fil persistent, chacun avec son moteur', async () => {
        await page.reload({ waitUntil: 'load' });
        const ordered = [q1.id, a1.id, q2.id, a2.id, q3.id, a3.id, q4.id, a4.id];
        await assertThreadChain(page, ordered);
        await expect(bubble(page, a1.id)).toContainText(`${orgName} · Dossiers`);
        await expect(bubble(page, a2.id)).toContainText(`${orgName} · IA + Dossiers`);
        await expect(bubble(page, a3.id)).toContainText(`${orgName} · IA`);
        await expect(bubble(page, a4.id)).toContainText(`${orgName} · Dossiers`);

        const all = newMessagesSince(before.message_ids);
        expect(all.map((m) => m.id)).toEqual(ordered);
        await page.screenshot({ path: path.join(CAPTURES, `run-${RUN_ID}-C-02-refresh-final.png`), fullPage: true });
    });

    await test.step('ledger : le mode croise trace sa capability, dans la meme famille', async () => {
        const after = snapshot();
        const window_ = orgLedgerBetween(before.now, after.now);

        // 2 (Dossiers) + 2 (croise) + 1 (IA) + 2 (Dossiers) = 7.
        expect(window_.length, `invocations hors parcours: ${JSON.stringify(window_)}`).toBe(7);
        expect(window_.filter((r) => r.capability === RAG_CAPABILITY).length).toBe(4);
        expect(window_.filter((r) => r.capability === HYBRID_CAPABILITY).length).toBe(2);
        expect(window_.filter((r) => r.capability === LLM_CAPABILITY).length).toBe(1);
        // Le tour croise fait UNE recherche et UNE generation, pas deux tours.
        expect(window_.filter((r) => r.capability === HYBRID_CAPABILITY && r.operation === 'embedding').length).toBe(1);
        expect(window_.filter((r) => r.capability === HYBRID_CAPABILITY && r.operation === 'generation').length).toBe(1);
        for (const row of window_) expect(row.status).toBe('success');
        expect(after.org_ledger_count - before.org_ledger_count).toBe(7);
        note('ledger scenario C: +7 exactement');
    });

    assertClean(watch);
    fs.writeFileSync(path.join(CAPTURES, `run-${RUN_ID}-C-journal.txt`), journal.join('\n'));
});
