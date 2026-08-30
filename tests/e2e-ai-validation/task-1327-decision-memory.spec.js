// TASK-1327 — Premium-1 « Decision Memory IA » : captures d'acceptance.
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation, ArtSciLab Demo). VRAIE invocation LLM (openrouter,
// credential du banc), aucun fake. Scene : Boucle « AI Shepherd & Ethics »
// (artscilab-ethics), HORS des Boucles utilisees par les autres specs.
//
//   00 (prep tinker) un message CONCLUSIF est poste dans le ChatLoop.
//   01 Maya (owner, decisions.record) : la Card Decisions et son bouton
//      « Cette discussion a-t-elle abouti a une decision ? (IA) ».
//   02 clic -> VRAIE generation -> brouillon pre-rempli : bandeau suggestion,
//      message source cite, note « proposition IA non verifiee ».
//      MESURE : aucune ligne loop_decisions ecrite a ce stade.
//   03 edition HUMAINE du titre dans le formulaire pre-rempli.
//   04 « Capitaliser cette decision » -> la Decision consignee, badge
//      « Depuis le ChatLoop ». MESURE : une ligne, author = Maya (l'humain),
//      loop_message_id = le message conclusif.
//   05 Sofia (member, lecture seule) : la Card se lit, AUCUN bouton IA.
//   Annexes : trace ai_interactions (capability, process partage, metadata
//   decision_suggestion avec provenance verified/ai_wording).
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/task-1327-decision-memory.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1327');
fs.mkdirSync(path.join(CAPTURES, 'annexes'), { recursive: true });

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';
const LOOP_SLUG = 'artscilab-ethics';
const LOOP = `${ORG_ROOT}/loops/${LOOP_SLUG}`;

const MAYA = 'maya@artscilab-demo.test';
const SOFIA = 'sofia@artscilab-demo.test';

// Marqueur de seance : chaque execution poste SON message conclusif et
// capitalise SA Decision — un message deja promu ne se re-propose pas, une
// re-execution sur le meme message echouerait donc par construction.
const SEANCE = new Date().toISOString().slice(0, 16).replace('T', ' ');

const CONCLUSION = 'Après discussion, on tranche : nous adoptons la checklist de revue humaine v2 '
    + 'pour toutes les démos publiques dès septembre. Théo la met à jour cette semaine, '
    + 'Amina la fait circuler aux partenaires. La proposition de audit externe est écartée '
    + `pour cette année, faute de budget. (Relevé de séance ${SEANCE})`;

const TITRE_EDITE = `Checklist de revue humaine v2 adoptée (séance ${SEANCE})`;

function tinker(code) {
    return execFileSync('php', ['artisan', 'tinker', '--execute', code], {
        env: { ...process.env, APP_ENV: 'ai-validation' },
        encoding: 'utf8',
    }).trim();
}

function decisionsCount() {
    return Number(tinker(`
        $l = \\App\\Models\\Loop::where('slug', '${LOOP_SLUG}')->firstOrFail();
        echo \\App\\Models\\LoopDecision::where('loop_id', $l->id)->count();
    `));
}

async function login(page, email) {
    await page.context().clearCookies();
    await page.goto(`${ORG_ROOT}/login`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.getByRole('button', { name: /sign in|se connecter/i }).click();
    await page.waitForURL((url) => url.pathname !== `${ORG_ROOT}/login`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(400);
    await switchToFrench(page);
}

// La Card vit dans le panneau lateral du workspace : on l'ouvre par
// l'evenement public du workspace (le meme que « voir le sondage » depuis un
// message), jamais en forcant le DOM.
async function openDecisionsCard(page) {
    await page.waitForSelector('[data-loop-decisions]', { state: 'attached', timeout: 20000 });
    await page.evaluate(() => {
        window.dispatchEvent(new CustomEvent('bp-open-loop-card', { detail: { card: 'core.decisions' } }));
    });
    await expect(page.locator('[data-loop-decisions]')).toBeVisible({ timeout: 10000 });
    await page.waitForTimeout(400);
    return page.locator('[data-loop-decisions]');
}

async function switchToFrench(page) {
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'load' }),
        page.evaluate(() => {
            const token = document.querySelector('meta[name=csrf-token]')?.getAttribute('content') || '';
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/locale/fr';
            for (const [name, value] of [['_token', token], ['redirect_to', window.location.href]]) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }),
    ]);
}

function watchConsole(page) {
    const errors = [];
    const failed = [];
    const serverErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') errors.push(message.text());
    });
    page.on('requestfailed', (request) => {
        const navigationAbortedPoll = /\/livewire[^/]*\/update$/.test(new URL(request.url()).pathname) && request.failure()?.errorText === 'net::ERR_ABORTED';
        if (!/_boost\//.test(request.url()) && !navigationAbortedPoll) failed.push(`${request.method()} ${request.url()} :: ${request.failure()?.errorText}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });
    return { errors, failed, serverErrors };
}

test.describe.configure({ mode: 'serial' });

test('Decision Memory IA — brouillon, edition humaine, capitalisation', async ({ page }) => {
    test.setTimeout(180000);

    const sonde = watchConsole(page);

    // ── 00 : la matiere — un message conclusif dans le ChatLoop ────────────
    const messageId = tinker(`
        $l = \\App\\Models\\Loop::where('slug', '${LOOP_SLUG}')->firstOrFail();
        $maya = \\App\\Models\\User::where('email', '${MAYA}')->firstOrFail();
        $m = \\App\\Models\\LoopMessage::firstOrCreate(
            ['loop_id' => $l->id, 'body' => ${JSON.stringify(CONCLUSION)}],
            ['organization_id' => $l->organization_id, 'sender_id' => $maya->id],
        );
        echo $m->id;
    `);
    expect(messageId).toMatch(/^[0-9a-f-]{36}$/);

    const decisionsAvant = decisionsCount();

    // ── 01 : la Card et son bouton, cote owner ─────────────────────────────
    await login(page, MAYA);
    await page.goto(LOOP);
    const card = await openDecisionsCard(page);
    await expect(card.locator('[data-decision-suggest]')).toBeVisible();
    await card.screenshot({ path: path.join(CAPTURES, '01-card-bouton-suggerer.png') });

    // ── 02 : VRAIE generation -> brouillon pre-rempli, RIEN d'ecrit ───────
    await card.locator('[data-decision-suggest]').click();
    await expect(card.locator('[data-decision-suggestion]')).toBeVisible({ timeout: 90000 });
    await expect(card.locator('[data-decision-suggestion-source]')).toBeVisible();
    await expect(card.locator('[data-decision-suggestion-promote]')).toBeVisible();
    await card.screenshot({ path: path.join(CAPTURES, '02-brouillon-prerempli.png') });

    expect(decisionsCount()).toBe(decisionsAvant); // la suggestion n'ecrit RIEN

    // ── 03 : edition HUMAINE du brouillon ──────────────────────────────────
    const titre = card.locator('input[wire\\:model="title"]');
    const titrePropose = await titre.inputValue();
    expect(titrePropose.trim().length).toBeGreaterThan(0);
    await titre.fill(TITRE_EDITE);
    await card.screenshot({ path: path.join(CAPTURES, '03-edition-humaine.png') });

    // ── 04 : capitalisation par la surface canonique ───────────────────────
    await card.locator('[data-decision-suggestion-promote]').click();
    await expect(card.getByText(TITRE_EDITE).first()).toBeVisible({ timeout: 15000 });
    await card.screenshot({ path: path.join(CAPTURES, '04-capitalisee.png') });

    expect(decisionsCount()).toBe(decisionsAvant + 1);

    const preuve = tinker(`
        $l = \\App\\Models\\Loop::where('slug', '${LOOP_SLUG}')->firstOrFail();
        $maya = \\App\\Models\\User::where('email', '${MAYA}')->firstOrFail();
        $d = \\App\\Models\\LoopDecision::where('loop_id', $l->id)->orderByDesc('created_at')->first();
        $i = \\App\\Models\\AiInteraction::where('organization_id', $l->organization_id)
            ->where('feature', 'loop_decision_suggestion')->orderByDesc('created_at')->first();
        echo json_encode([
            'decision' => ['title' => $d->title, 'author_is_maya' => $d->author_id === $maya->id,
                'loop_message_id' => $d->loop_message_id, 'organization_id' => $d->organization_id],
            'interaction' => $i ? ['capability' => $i->metadata['capability'] ?? null, 'process' => $i->process,
                'status' => $i->metadata['status'] ?? null,
                'decision_suggestion' => $i->metadata['decision_suggestion'] ?? null] : null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    `);
    fs.writeFileSync(path.join(CAPTURES, 'annexes', 'trace-decision-et-interaction.json'), preuve + '\n');

    const parsed = JSON.parse(preuve);
    expect(parsed.decision.author_is_maya).toBe(true); // l'humain, jamais l'IA
    expect(parsed.decision.loop_message_id).toBe(messageId);
    expect(parsed.interaction.capability).toBe('loop_decision_suggestion');
    expect(parsed.interaction.process).toBe('chatloop.summarize');
    expect(parsed.interaction.decision_suggestion.decision_found).toBe(true);
    expect(parsed.interaction.decision_suggestion.provenance.verified[0].loop_message_id).toBe(messageId);
    expect(parsed.interaction.decision_suggestion.provenance.ai_wording.verified).toBe(false);

    // ── 05 : Sofia (member) — lecture sans bouton IA ───────────────────────
    await login(page, SOFIA);
    await page.goto(LOOP);
    const cardSofia = await openDecisionsCard(page);
    await expect(cardSofia.getByText(TITRE_EDITE).first()).toBeVisible();
    await expect(cardSofia.locator('[data-decision-suggest]')).toHaveCount(0);
    await cardSofia.screenshot({ path: path.join(CAPTURES, '05-membre-sans-suggestion.png') });

    fs.writeFileSync(
        path.join(CAPTURES, 'annexes', 'console.json'),
        JSON.stringify(sonde, null, 2) + '\n',
    );
    expect(sonde.serverErrors).toEqual([]);
});
