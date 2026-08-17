// TASK-1226 — DEMO ROGER : upload -> indexation observee EN DIRECT -> RAG.
//
// Ce spec joue l'histoire cible sur le banc reel (127.0.0.1:8010, DB
// bouclepro_ai_validation, ArtSciLab Demo) et fait des appels IA REELS
// (embedding d'ingestion + embedding de requete + generation). Il ne s'execute
// donc QUE sur demande explicite :
//
//   ROGER_DEMO=1 npx playwright test --config=playwright.ai-validation.config.mjs \
//       tests/e2e-ai-validation/knowledge-observatory-roger-demo.spec.js
//   ROGER_DEMO=1 KEEP=1 ...   -> laisse le fichier de demo en place (spot-check)
//
// Prerequis : serveur 8010 + worker de queue lances depuis CE worktree
// (ai-validation-serve.sh / ai-validation-worker.sh).
//
// Deroule (§12 du MASTER) :
//   1. onglet A : Observatoire ouvert, compteurs releves ;
//   2. onglet B : ajout d'un fichier Markdown par l'UI reelle (drop) dans un
//      Dossier de la Boucle Emergence ;
//   3. onglet A, SANS reload : la source apparait, puis passe « Indexe »
//      (ou apparait directement « Indexe » si la queue est plus rapide que le
//      poll — les deux sont des succes, rien n'est simule) ;
//   4. Ask the Folders depuis la Boucle Emergence : la reponse contient la
//      sentinelle, cite [S1], la source est ouvrable ;
//   5. tenant : un membre d'une autre Organization ne voit rien.
//   6. nettoyage par id du fichier cree (sauf KEEP=1).
//
// La sentinelle est une donnee de test BouclePro : elle ne pretend citer
// personne.

import { test, expect } from '@playwright/test';

test.skip(!process.env.ROGER_DEMO, 'Demo Roger : appels IA reels, lancer avec ROGER_DEMO=1');

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const ADMIN_EMAIL = 'maya@artscilab-demo.test';
const PASSWORD = 'password';
const OBSERVATORY = `${ORG_ROOT}/admin/ai-knowledge`;
const DEMO_DOSSIER_ID = '019ffb69-cb3f-720e-b192-659b1fe5c64b'; // Emergence — Session 01 (Dossier de la Boucle Emergence)
const DEMO_LOOP_SLUG = 'artscilab-emergence';
const DEMO_FILE_NAME = 'smart-village-roger-demo.md';
const SENTINEL = 'ROGER-SMART-VILLAGE-1226';
const DEMO_FILE_CONTENT = [
    '# Smart Village — scenario Roger Demo',
    '',
    'Dans le scenario Roger Demo, le Smart Village associe participation locale,',
    'technologie appropriee et cooperation interdisciplinaire.',
    '',
    `Code de demonstration : ${SENTINEL}.`,
    '',
    '_Sentinelle de test BouclePro (TASK-1226). Ce texte ne cite personne._',
    '',
].join('\n');
const QUESTION = 'Quel est le code de demonstration associe au Smart Village ?';

const OTHER_ORG_SLUG = 'ai-validation-org-b';
const OTHER_MEMBER_EMAIL = 'member1@ai-validation-org-b.ai-validation.test';

async function login(page, orgSlug, email) {
    await page.goto(`/org/${orgSlug}/login`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.getByRole('button', { name: /sign in|se connecter/i }).click();
    await page.waitForURL((url) => url.pathname !== `/org/${orgSlug}/login`);
}

function stamp() {
    return new Date().toISOString().slice(11, 23);
}

test.describe('TASK-1226 Demo Roger — upload -> indexation en direct -> RAG', () => {
    test.setTimeout(240000);

    test('l’histoire complete, sans reload et sans simulation', async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
        const observatory = await context.newPage();
        const log = [];
        const note = (message) => { log.push(`[${stamp()}] ${message}`); console.log(log.at(-1)); };

        await login(observatory, ORG_SLUG, ADMIN_EMAIL);

        // 1. Observatoire ouvert, compteurs AVANT.
        await observatory.goto(OBSERVATORY);
        await observatory.waitForResponse((r) => r.url().includes('/ai-knowledge/live') && r.status() === 200);
        await observatory.evaluate(() => { window.__roger1226NoReload = true; });
        const before = {
            files: await observatory.locator('[data-knowledge-counter="knowledge_console_files"] [data-knowledge-counter-value]').innerText(),
            indexedSources: await observatory.locator('[data-knowledge-counter="knowledge_console_indexed_sources"] [data-knowledge-counter-value]').innerText(),
            chunks: await observatory.locator('[data-knowledge-counter="knowledge_console_chunks"] [data-knowledge-counter-value]').innerText(),
            emergence: await observatory.locator('[data-knowledge-loop]', { hasText: 'Emergence' }).innerText(),
        };
        note(`AVANT — fichiers=${before.files} sources indexees=${before.indexedSources} extraits=${before.chunks} | ${before.emergence.replace(/\s+/g, ' ')}`);
        expect(await observatory.locator(`tr[data-source-key]`, { hasText: DEMO_FILE_NAME }).count()).toBe(0);

        // 2. Onglet B : upload par l'UI reelle (drop sur le Dossier).
        const dossierTab = await context.newPage();
        await dossierTab.goto(`${ORG_ROOT}/dossiers/${DEMO_DOSSIER_ID}`);
        await expect(dossierTab.locator('[x-data*="dossierFilesCard"], [data-dossier-files]').first()).toBeVisible({ timeout: 15000 }).catch(() => {});
        const uploadResponse = dossierTab.waitForResponse((r) => r.url().includes(`/dossiers/${DEMO_DOSSIER_ID}/files`) && r.request().method() === 'POST', { timeout: 30000 });
        const dropped = await dossierTab.evaluate(async ({ name, content }) => {
            const zone = Array.from(document.querySelectorAll('[\\@drop\\.prevent], [x-on\\:drop\\.prevent]')).find((el) => el.getAttribute('@drop.prevent')?.includes('handleMediaFiles') || el.getAttribute('x-on:drop.prevent')?.includes('handleMediaFiles'));
            if (!zone) return 'no-zone';
            const file = new File([content], name, { type: 'text/markdown' });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            zone.dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer }));
            zone.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer }));
            return 'dropped';
        }, { name: DEMO_FILE_NAME, content: DEMO_FILE_CONTENT });
        expect(dropped).toBe('dropped');
        const upload = await uploadResponse;
        expect(upload.status(), await upload.text()).toBeLessThan(300);
        const uploadedAt = Date.now();
        const uploadJson = await upload.json();
        const created = (uploadJson.files || []).find((f) => (f.display_name || f.original_name || '').includes('smart-village-roger-demo'));
        note(`UPLOAD — HTTP ${upload.status()} — fichier ${created?.id ?? '(id non renvoye)'} « ${created?.display_name ?? DEMO_FILE_NAME} »`);

        // 3. Onglet A, SANS reload : apparition puis indexation.
        const row = observatory.locator('tr[data-source-key]', { hasText: DEMO_FILE_NAME });
        await expect(row).toHaveCount(1, { timeout: 15000 });
        const appearedAfterMs = Date.now() - uploadedAt;
        const stateAtAppearance = await row.getAttribute('data-source-indexed');
        note(`APPARITION — ${appearedAfterMs} ms apres l'upload, etat initial vu par le poll : ${stateAtAppearance === '1' ? 'Indexe (queue plus rapide que le poll)' : 'Non indexe'}`);
        await expect(row.locator('[data-source-scope]')).toHaveAttribute('data-source-scope', 'loop');
        await expect(row.locator('[data-source-scope]')).toContainText('Emergence');

        await expect(row).toHaveAttribute('data-source-indexed', '1', { timeout: 120000 });
        const indexedAfterMs = Date.now() - uploadedAt;
        const chunks = await row.getAttribute('data-source-chunks');
        const indexedAtText = await row.locator('td').nth(6).innerText();
        note(`INDEXATION — detectee ${indexedAfterMs} ms apres l'upload, extraits=${chunks}, derniere indexation=${indexedAtText.trim()}`);
        expect(Number(chunks)).toBeGreaterThan(0);
        expect(await observatory.evaluate(() => window.__roger1226NoReload === true)).toBe(true);

        await expect.poll(async () => observatory.locator('[data-knowledge-counter="knowledge_console_chunks"] [data-knowledge-counter-value]').innerText(), { timeout: 10000 })
            .not.toBe(before.chunks);
        const after = {
            files: await observatory.locator('[data-knowledge-counter="knowledge_console_files"] [data-knowledge-counter-value]').innerText(),
            indexedSources: await observatory.locator('[data-knowledge-counter="knowledge_console_indexed_sources"] [data-knowledge-counter-value]').innerText(),
            chunks: await observatory.locator('[data-knowledge-counter="knowledge_console_chunks"] [data-knowledge-counter-value]').innerText(),
            emergence: await observatory.locator('[data-knowledge-loop]', { hasText: 'Emergence' }).innerText(),
        };
        note(`APRES — fichiers=${after.files} sources indexees=${after.indexedSources} extraits=${after.chunks} | ${after.emergence.replace(/\s+/g, ' ')}`);
        expect(Number(after.files.replace(/\D/g, ''))).toBe(Number(before.files.replace(/\D/g, '')) + 1);
        await observatory.screenshot({ path: 'test-results/roger-demo-observatory-after.png', fullPage: false });

        // 4. Ask the Folders depuis la Boucle Emergence.
        const loopTab = await context.newPage();
        await loopTab.goto(`${ORG_ROOT}/loops/${DEMO_LOOP_SLUG}`);
        await loopTab.locator('[data-knowledge-open]').first().click();
        await expect(loopTab.locator('[data-knowledge-dialog]')).toBeVisible();
        await loopTab.fill('#knowledge-question', QUESTION);
        const askResponse = loopTab.waitForResponse((r) => r.url().includes('/knowledge') && r.request().method() === 'POST', { timeout: 90000 });
        await loopTab.locator('[data-knowledge-dialog] button[type="submit"]').click();
        const ask = await askResponse;
        const answer = await ask.json();
        note(`ASK — HTTP ${ask.status()} — grounded=${answer.grounded} — sources=${(answer.sources || []).map((s) => `[${s.ref}] ${s.title}`).join(' ; ')}`);
        note(`REPONSE — ${String(answer.answer || '').replace(/\s+/g, ' ').slice(0, 300)}`);
        expect(ask.status()).toBe(200);
        expect(String(answer.answer)).toContain(SENTINEL);
        const s1 = (answer.sources || []).find((s) => s.ref === 'S1');
        expect(s1, 'la reponse cite [S1]').toBeTruthy();
        expect(String(answer.answer)).toMatch(/\[S1\]/);
        const demoSource = (answer.sources || []).find((s) => (s.title || '').includes('smart-village-roger-demo'));
        expect(demoSource, 'la source citee est le fichier de demo').toBeTruthy();
        expect(demoSource.url).toBeTruthy();
        await expect(loopTab.locator('[data-knowledge-answer]')).toContainText(SENTINEL);
        await loopTab.screenshot({ path: 'test-results/roger-demo-ask-the-folders.png' });

        // Source ouvrable (DossierPolicy : Maya est membre d'Emergence).
        const open = await loopTab.request.get(demoSource.url);
        note(`SOURCE — ${demoSource.url} -> HTTP ${open.status()}`);
        expect(open.status()).toBeLessThan(400);

        // 5. Tenant : un membre d'une autre Organization ne voit rien.
        const foreign = await browser.newContext();
        const foreignPage = await foreign.newPage();
        await login(foreignPage, OTHER_ORG_SLUG, OTHER_MEMBER_EMAIL);
        expect((await foreignPage.request.get(OBSERVATORY)).status()).toBe(403);
        expect((await foreignPage.request.get(demoSource.url)).status()).toBeGreaterThanOrEqual(400);
        note('TENANT — membre org B : 403 sur l’Observatoire ArtSciLab, source de demo inaccessible');
        await foreign.close();

        // 6. Nettoyage par id (sauf KEEP=1).
        if (created?.id && !process.env.KEEP) {
            const token = await dossierTab.evaluate(() => document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '');
            const del = await dossierTab.request.delete(`${ORG_ROOT}/dossiers/${DEMO_DOSSIER_ID}/files/${created.id}`, {
                headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            });
            note(`NETTOYAGE — DELETE fichier ${created.id} -> HTTP ${del.status()}`);
            expect(del.status()).toBeLessThan(300);
            await expect(observatory.locator('tr[data-source-key]', { hasText: DEMO_FILE_NAME })).toHaveCount(0, { timeout: 15000 });
        } else {
            note(`CONSERVE — fichier ${created?.id ?? '?'} laisse en place (KEEP=1 ou id inconnu)`);
        }

        console.log('\n=== DEMO ROGER — JOURNAL ===\n' + log.join('\n'));
        await context.close();
    });
});
