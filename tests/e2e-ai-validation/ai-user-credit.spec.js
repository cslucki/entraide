// TASK-1229 — Credit IA par utilisateur et seuils d'abonnement V1.
//
// Banc IA (playwright.ai-validation.config.mjs, 127.0.0.1:8010, DB
// bouclepro_ai_validation, ArtSciLab Demo). Parcours « ce que Cyril doit
// VOIR », en FR, avec de VRAIES invocations (questions aux Dossiers depuis la
// Boucle LaunchPals, ~0.0002 $ chacune) :
//
//   01 SuperAdmin « Monetisation IA » : IA gratuite, quota 100 / mois, alerte
//      80 %, blocage 100 %, proposer un abonnement (trace de la modification).
//   02 Admin Organization (Maya) : override « valeur propre » (petit quota de
//      demonstration = utilisations actuelles de Jonas + 10), qui approche sa
//      limite.
//   03 Jonas (membre, credit intact) : « Mes usages IA » — ce qu'il lui reste.
//   04 Jonas pose des questions REELLES jusqu'au seuil : message d'alerte
//      calme dans le modal + sur la page, action NON bloquee.
//   05 Jonas continue jusqu'au plafond : la question suivante est REFUSEE,
//      message clair « credit epuise » + bouton « Voir les offres ».
//   06 Preuve : l'appel refuse n'a rien produit — compteur du releve
//      utilisateur identique avant/apres, credit non decompte.
//   07 Budget Organization atteint (Maya abaisse le budget) mais credit de
//      Sofia intact : le message parle de l'ORGANIZATION, sans « offres ».
//      Puis budget restaure.
//   08 Essais de doctrine (bac a sable, Maya, dont le credit est au plafond
//      sous l'override de demonstration) : appel REEL, comptabilise dans le
//      budget de l'Organization, HORS credit — le compteur de credit de Maya
//      ne bouge pas.
//   09 Mobile 390x844.
//   Fin : override remis sur « reglage plateforme », budget 5.00 $ (banc SAFE).
//
// LIVRABLE VISUEL : PNG 01..09 + video dans _local/captures/TASK-1229/ (ecran
// REEL, aucun montage). `test.use({ video, screenshot })` ICI seulement.
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs tests/e2e-ai-validation/ai-user-credit.spec.js

import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

test.use({ video: 'on', screenshot: 'on' });

const CAPTURES = path.resolve('_local/captures/TASK-1229');
fs.mkdirSync(CAPTURES, { recursive: true });

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const PASSWORD = 'password';
const LOOP = `${ORG_ROOT}/loops/artscilab-launchpals`;
const USER_PAGE = `${ORG_ROOT}/profile/ai-usage`;
const OFFERS_PAGE = `${ORG_ROOT}/profile/ai-usage/offers`;
const ORG_AI_PAGE = `${ORG_ROOT}/admin/ai`;
const ORG_CONSUMPTION_PAGE = `${ORG_ROOT}/admin/ai-consumption`;
const ORG_BEHAVIOR_PAGE = `${ORG_ROOT}/admin/ai-behavior`;
const PLATFORM_PAGE = '/admin/ai-monetization';

const MAYA = 'maya@artscilab-demo.test';
const JONAS = 'jonas@artscilab-demo.test';
const SOFIA = 'sofia@artscilab-demo.test';
const PLATFORM_ADMIN_ORG_SLUG = 'ai-validation-org-a';
const PLATFORM_ADMIN = 'admin@ai-validation-org-a.ai-validation.test';

// Questions proches du corpus ArtSciLab (une installation itinérante qui tient
// dans une valise, kit de réparation, contraintes matérielles, partenaires
// locaux, revue de prototype honnête, consortium européen) : chacune trouve des
// sources, donc coûte 1 recherche + 1 génération = 2 utilisations.
const QUESTIONS = [
    'Que doit contenir une installation itinérante ?',
    'Que doit contenir une installation itinérante qui tient dans une valise ?',
    'Pourquoi une installation itinérante doit-elle inclure un kit de réparation ?',
    'Comment une installation itinérante documente-t-elle ses contraintes matérielles ?',
    'De quoi les partenaires locaux ont-ils besoin pour adapter une installation itinérante ?',
    'À quoi ressemble une revue de prototype honnête ?',
    'Comment un projet européen réussit-il avec ses partenaires ?',
    'Que doit contenir une installation itinérante ?',
    'Que doit contenir une installation itinérante qui tient dans une valise ?',
    'Pourquoi une installation itinérante doit-elle inclure un kit de réparation ?',
];

const figures = {};

async function login(page, orgSlug, email) {
    await page.context().clearCookies();
    await page.goto(`/org/${orgSlug}/login`);
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', PASSWORD);
    await page.getByRole('button', { name: /sign in|se connecter/i }).click();
    await page.waitForURL((url) => url.pathname !== `/org/${orgSlug}/login`);
    // Laisser la redirection post-connexion se poser avant de basculer la locale
    // (sinon la navigation en cours est avortee : ERR_ABORTED).
    await page.waitForLoadState('load');
    await page.waitForTimeout(400);
    await switchToFrench(page);
}

// La locale est de SESSION (POST /locale/fr) : a refaire apres chaque connexion.
async function switchToFrench(page) {
    // Soumission d'un vrai formulaire (navigation de document, comme le
    // selecteur EN/FR de l'interface) : pas de fetch + reload qui avorterait
    // une navigation en cours.
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
        if (!/_boost\//.test(request.url())) failed.push(`${request.method()} ${request.url()} :: ${request.failure()?.errorText}`);
    });
    page.on('response', (response) => {
        if (response.status() >= 500) serverErrors.push(`${response.status()} ${response.url()}`);
    });
    return { errors, failed, serverErrors };
}

function assertClean(watch) {
    const relevantErrors = watch.errors.filter((e) => !/favicon|_boost|Failed to send logs|the server responded with a status of 4\d\d/i.test(e));
    expect(relevantErrors, relevantErrors.join('\n')).toEqual([]);
    expect(watch.failed, watch.failed.join('\n')).toEqual([]);
    expect(watch.serverErrors, watch.serverErrors.join('\n')).toEqual([]);
}

const num = (v) => Number(v ?? 0);

async function creditFigures(page) {
    await page.goto(USER_PAGE);
    const card = page.locator('[data-my-ai-credit]');
    return {
        state: await card.getAttribute('data-my-ai-credit-state'),
        used: num(await card.getAttribute('data-my-ai-credit-used')),
        quota: (await card.getAttribute('data-my-ai-credit-quota')) || null,
        remaining: (await card.getAttribute('data-my-ai-credit-remaining')) || null,
        headline: (await page.locator('[data-my-ai-credit-headline]').innerText()).replace(/\s+/g, ' ').trim(),
        monthCount: num(await page.locator('[data-my-ai-usage-month]').getAttribute('data-my-ai-usage-month-count')),
        sandboxExcluded: (await page.locator('[data-my-ai-credit-sandbox-excluded]').count())
            ? num(await page.locator('[data-my-ai-credit-sandbox-excluded]').getAttribute('data-my-ai-credit-sandbox-excluded'))
            : 0,
    };
}

async function askQuestion(page, question) {
    await page.goto(LOOP);
    await page.locator('[data-knowledge-open]').first().click();
    await page.fill('#knowledge-question', question);
    const responsePromise = page.waitForResponse((r) => /\/knowledge$/.test(new URL(r.url()).pathname) && r.request().method() === 'POST', { timeout: 90000 });
    await page.locator('[data-knowledge-dialog] form button[type="submit"]').click();
    const response = await responsePromise;
    let payload = null;
    try { payload = await response.json(); } catch (e) { payload = null; }
    await page.waitForTimeout(600);
    return { status: response.status(), payload };
}

async function orgFigures(page) {
    await page.goto(ORG_CONSUMPTION_PAGE);
    return {
        total: num(await page.locator('[data-consumption-breakdown]').getAttribute('data-consumption-total-count')),
        sandbox: (await page.locator('[data-consumption-nature="sandbox"]').count())
            ? num(await page.locator('[data-consumption-nature="sandbox"]').getAttribute('data-consumption-nature-count'))
            : 0,
        consumed: (await page.locator('[data-consumption-budget-consumed]').innerText()).trim(),
    };
}

async function setOverride(page, mode, uses = null) {
    await page.goto(ORG_AI_PAGE);
    await page.locator(`[data-ai-user-credit-mode-input="${mode}"]`).check();
    if (mode === 'custom') {
        await page.fill('[data-ai-user-credit-uses-input]', String(uses));
    }
    await page.locator('[data-ai-user-credit-form] button[type="submit"]').click();
    await page.waitForURL((url) => url.pathname.endsWith('/admin/ai'));
    await expect(page.locator('[data-ai-settings-saved]')).toBeVisible();
}

async function setBudget(page, value) {
    await page.goto(ORG_AI_PAGE);
    await page.fill('#ai-monthly-budget', value);
    await page.locator('form[action$="/admin/ai"] button[type="submit"]').first().click();
    await page.waitForURL((url) => url.pathname.endsWith('/admin/ai'));
    await expect(page.locator('[data-ai-settings-saved]')).toBeVisible();
}

test.describe('TASK-1229 Credit IA par utilisateur', () => {
    test('parcours : configuration plateforme -> override Organization -> reste visible -> alerte -> plafond -> zero ledger -> budget Organization -> essais de doctrine hors credit -> mobile', async ({ page }) => {
        test.setTimeout(600000);
        const watch = watchConsole(page);

        // ── 01 SuperAdmin : Monetisation IA ─────────────────────────────────
        await login(page, PLATFORM_ADMIN_ORG_SLUG, PLATFORM_ADMIN);
        await page.goto(PLATFORM_PAGE);
        await expect(page.locator('[data-ai-monetization-form]')).toBeVisible();
        await page.locator('[data-ai-monetization-free-enabled]').check();
        await page.fill('[data-ai-monetization-monthly-uses]', '100');
        await page.fill('[data-ai-monetization-alert-percent]', '80');
        await page.locator('[data-ai-monetization-offer-subscription]').check();
        await page.locator('[data-ai-monetization-form] button[type="submit"]').click();
        await page.waitForURL((url) => url.pathname.endsWith('/admin/ai-monetization'));
        await expect(page.locator('[data-ai-monetization-saved]')).toBeVisible();
        await expect(page.locator('[data-ai-monetization-monthly-uses]')).toHaveValue('100');
        await expect(page.locator('[data-ai-monetization-alert-percent]')).toHaveValue('80');
        await expect(page.locator('[data-ai-monetization-last-change]')).toContainText(/Dernière modification/);
        await page.screenshot({ path: path.join(CAPTURES, '01-configuration-superadmin-monetisation-ia.png'), fullPage: true });
        figures.platform = { free_enabled: true, monthly_uses: 100, alert_percent: 80, offer_subscription: true };

        // ── 03 (avant) : credit de Jonas AVANT tout override, pour dimensionner ──
        await login(page, ORG_SLUG, JONAS);
        const jonasStart = await creditFigures(page);
        // Quota de demonstration : ce que Jonas a deja utilise + 10 -> 5 questions
        // (1 recherche + 1 generation chacune) menent exactement au plafond.
        const demoQuota = jonasStart.used + 10;

        // ── 02 Admin Organization : override « valeur propre » ──────────────
        await login(page, ORG_SLUG, MAYA);
        await setOverride(page, 'custom', demoQuota);
        await expect(page.locator('[data-ai-user-credit]')).toHaveAttribute('data-ai-user-credit-effective', String(demoQuota));
        await expect(page.locator('[data-ai-user-credit-platform]')).toContainText(/100/);
        await expect(page.locator('[data-ai-user-credit-last-change]')).toContainText(/Maya/);
        await page.locator('[data-ai-user-credit]').scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(CAPTURES, '02-override-organization-credit-utilisateur.png'), fullPage: true });
        figures.override = { mode: 'custom', uses: demoQuota, platform_uses: 100 };
        // Maya elle-meme (52+ utilisations) est au plafond sous cet override :
        // visible dans « membres proches de leur limite ».
        figures.mayaListedNearLimit = (await page.locator('[data-ai-user-credit-member]').filter({ hasText: 'Maya' }).count()) > 0;

        // ── 03 Jonas : sous le seuil, son reste visible ─────────────────────
        await login(page, ORG_SLUG, JONAS);
        const jonasBefore = await creditFigures(page);
        expect(jonasBefore.quota).toBe(String(demoQuota));
        expect(jonasBefore.state).toBe('ok');
        expect(num(jonasBefore.remaining)).toBe(10);
        await expect(page.locator('[data-my-ai-credit-remaining-label]')).toContainText(/10 utilisations restantes/);
        await page.screenshot({ path: path.join(CAPTURES, '03-utilisateur-sous-le-seuil-reste-visible.png'), fullPage: true });
        figures.jonasBefore = jonasBefore;

        // ── 04 Franchissement du seuil : questions REELLES jusqu'a l'alerte ──
        let asked = 0;
        let alertPayload = null;
        for (const question of QUESTIONS) {
            const { status, payload } = await askQuestion(page, question);
            expect(status, JSON.stringify(payload)).toBe(200);
            asked++;
            expect(payload.credit, 'toute reponse porte le credit').not.toBeNull();
            expect(payload.credit.exhausted).toBe(false);
            if (payload.credit.alert) {
                alertPayload = payload;
                break;
            }
        }
        expect(alertPayload, 'le seuil d\'alerte doit etre franchi sans blocage').not.toBeNull();
        await expect(page.locator('[data-ai-credit-alert]')).toBeVisible();
        await expect(page.locator('[data-knowledge-answer]')).not.toHaveText('');
        await page.locator('[data-ai-credit-alert]').scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(CAPTURES, '04a-franchissement-du-seuil-modal-alerte-action-non-bloquee.png'), fullPage: false });
        const jonasAlert = await creditFigures(page);
        expect(jonasAlert.state).toBe('alert');
        expect(jonasAlert.used).toBe(alertPayload.credit.used);
        await expect(page.locator('[data-my-ai-credit-alert]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '04b-franchissement-du-seuil-mes-usages-ia.png'), fullPage: true });
        figures.alert = { questions_asked: asked, credit: alertPayload.credit };

        // ── 05 Plafond : on continue jusqu'a l'epuisement, puis refus ───────
        let exhaustedPayload = null;
        for (const question of QUESTIONS.slice(asked)) {
            const { status, payload } = await askQuestion(page, question);
            expect(status, JSON.stringify(payload)).toBe(200);
            asked++;
            if (payload.credit.exhausted) {
                exhaustedPayload = payload;
                break;
            }
        }
        expect(exhaustedPayload, 'le plafond doit etre atteint').not.toBeNull();
        expect(exhaustedPayload.credit.used).toBe(demoQuota);
        const jonasAtCap = await creditFigures(page);
        expect(jonasAtCap.state).toBe('exhausted');
        expect(jonasAtCap.used).toBe(demoQuota);
        const monthCountBeforeRefusal = jonasAtCap.monthCount;

        // La question de trop : REFUSEE avant l'appel, code stable, offres.
        const refused = await askQuestion(page, QUESTIONS[asked % QUESTIONS.length]);
        expect(refused.status).toBe(422);
        expect(refused.payload.code).toBe('user_credit_exhausted');
        expect(refused.payload.error).toMatch(/crédit IA du mois est épuisé/);
        expect(refused.payload.offers_url).toContain('/profile/ai-usage/offers');
        await expect(page.locator('[data-knowledge-error]')).toBeVisible();
        await expect(page.locator('[data-knowledge-error] [data-ai-credit-offers-link]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '05a-plafond-atteint-refus-clair-voir-les-offres.png'), fullPage: false });
        const jonasAfterRefusal = await creditFigures(page);
        await expect(page.locator('[data-my-ai-credit-exhausted]')).toBeVisible();
        await expect(page.locator('[data-my-ai-credit-exhausted] [data-ai-credit-offers-link]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '05b-plafond-atteint-mes-usages-ia.png'), fullPage: true });
        await page.locator('[data-my-ai-credit-exhausted] [data-ai-credit-offers-link]').click();
        await page.waitForURL((url) => url.pathname.endsWith('/profile/ai-usage/offers'));
        await expect(page.locator('[data-ai-offers]')).toBeVisible();
        await page.screenshot({ path: path.join(CAPTURES, '05c-page-voir-les-offres-information-sans-paiement.png'), fullPage: true });

        // ── 06 Preuve : l'appel refuse n'a rien produit ──────────────────────
        expect(jonasAfterRefusal.monthCount).toBe(monthCountBeforeRefusal);
        expect(jonasAfterRefusal.used).toBe(demoQuota);
        figures.cap = {
            questions_asked: asked,
            credit_after_last_allowed: exhaustedPayload.credit,
            refusal: { status: refused.status, code: refused.payload.code, error: refused.payload.error },
            month_count_before_refusal: monthCountBeforeRefusal,
            month_count_after_refusal: jonasAfterRefusal.monthCount,
            credit_used_after_refusal: jonasAfterRefusal.used,
        };
        // Preuve cote ledger : le releve utilisateur (registre des interactions +
        // ledger canonique) compte exactement 2 lignes par question posee, et
        // AUCUNE pour la question refusee.
        expect(jonasAfterRefusal.monthCount - jonasStart.monthCount).toBe(asked * 2);
        await page.goto(USER_PAGE);
        await page.locator('[data-my-ai-usage]').scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(CAPTURES, '06-preuve-zero-ledger-apres-refus.png'), fullPage: true });
        fs.writeFileSync(path.join(CAPTURES, '06-preuve-zero-ledger.txt'), [
            `Jonas — releve « Mes usages IA » (registre des interactions + ledger canonique, mois UTC)`,
            `utilisations au depart              : ${jonasStart.monthCount} (credit ${jonasStart.used})`,
            `questions REELLES posees            : ${asked} (1 recherche documentaire + 1 generation chacune)`,
            `utilisations juste avant le refus   : ${monthCountBeforeRefusal} (credit ${demoQuota} / ${demoQuota})`,
            `question refusee (HTTP ${refused.status}, code ${refused.payload.code})`,
            `utilisations juste apres le refus   : ${jonasAfterRefusal.monthCount} (credit ${jonasAfterRefusal.used} / ${demoQuota})`,
            `=> +${jonasAfterRefusal.monthCount - jonasStart.monthCount} lignes pour ${asked} questions, +0 pour la question refusee : zero invocation, zero ledger, aucun credit decompte.`,
        ].join('\n'));

        // ── 07 Budget Organization atteint, credit de Sofia intact ──────────
        await login(page, ORG_SLUG, MAYA);
        const orgBeforeBudgetCut = await orgFigures(page);
        await setBudget(page, '0');
        await login(page, ORG_SLUG, SOFIA);
        const sofiaBefore = await creditFigures(page);
        expect(sofiaBefore.state).not.toBe('exhausted');
        const orgRefused = await askQuestion(page, QUESTIONS[0]);
        expect(orgRefused.status).toBe(422);
        expect(orgRefused.payload.code).toBe('organization_budget_reached');
        expect(orgRefused.payload.error).toMatch(/budget IA mensuel de cette organisation est atteint/i);
        expect(orgRefused.payload.offers_url).toBeNull();
        await expect(page.locator('[data-knowledge-error]')).toBeVisible();
        expect(await page.locator('[data-knowledge-error] [data-ai-credit-offers-link]').isVisible()).toBe(false);
        await page.screenshot({ path: path.join(CAPTURES, '07-budget-organization-atteint-credit-intact-message-organization.png'), fullPage: false });
        figures.organizationBudget = { sofia_credit_before: sofiaBefore, refusal: { status: orgRefused.status, code: orgRefused.payload.code, error: orgRefused.payload.error }, consumed_before_cut: orgBeforeBudgetCut.consumed };
        // Budget restaure.
        await login(page, ORG_SLUG, MAYA);
        await setBudget(page, '5.00');

        // ── 08 Essais de doctrine : hors credit, dans le budget ─────────────
        const mayaBefore = await creditFigures(page);
        const orgBefore = await orgFigures(page);
        await page.goto(ORG_BEHAVIOR_PAGE);
        await page.selectOption('#sandbox-capability', 'loop_knowledge_answer');
        await page.fill('#sandbox-question', QUESTIONS[0]);
        await page.locator('[data-behavior-sandbox-run]').click();
        await page.waitForURL((url) => url.pathname.endsWith('/admin/ai-behavior'), { timeout: 90000 });
        const sandboxStatus = await page.locator('[data-behavior-sandbox-result]').getAttribute('data-behavior-sandbox-status');
        expect(['answered', 'no_sources']).toContain(sandboxStatus);
        await expect(page.locator('[data-behavior-sandbox-ledgered]')).toHaveAttribute('data-behavior-sandbox-ledgered', '1');
        await page.locator('[data-behavior-sandbox-result]').scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(CAPTURES, '08a-essai-de-doctrine-appel-reel.png'), fullPage: false });
        const mayaAfter = await creditFigures(page);
        const orgAfter = await orgFigures(page);
        // Credit de Maya : inchange. Budget / releve Organization : +1 essai.
        expect(mayaAfter.used).toBe(mayaBefore.used);
        expect(mayaAfter.monthCount).toBeGreaterThan(mayaBefore.monthCount);
        expect(mayaAfter.sandboxExcluded).toBeGreaterThan(mayaBefore.sandboxExcluded);
        if (sandboxStatus === 'answered') {
            expect(orgAfter.sandbox).toBe(orgBefore.sandbox + 1);
        }
        expect(orgAfter.total).toBeGreaterThan(orgBefore.total);
        await page.goto(USER_PAGE);
        await page.screenshot({ path: path.join(CAPTURES, '08b-essais-de-doctrine-hors-credit-mes-usages-ia-maya.png'), fullPage: true });
        await page.goto(ORG_CONSUMPTION_PAGE);
        await page.screenshot({ path: path.join(CAPTURES, '08c-essais-de-doctrine-dans-le-budget-releve-organization.png'), fullPage: true });
        figures.sandbox = { status: sandboxStatus, maya_credit_before: mayaBefore.used, maya_credit_after: mayaAfter.used, maya_month_before: mayaBefore.monthCount, maya_month_after: mayaAfter.monthCount, maya_sandbox_excluded_before: mayaBefore.sandboxExcluded, maya_sandbox_excluded_after: mayaAfter.sandboxExcluded, org_total_before: orgBefore.total, org_total_after: orgAfter.total, org_sandbox_before: orgBefore.sandbox, org_sandbox_after: orgAfter.sandbox, org_consumed_before: orgBefore.consumed, org_consumed_after: orgAfter.consumed };

        // ── Fin : banc SAFE — override remis sur la plateforme, budget 5.00 $ ─
        await setOverride(page, 'platform');
        await expect(page.locator('[data-ai-user-credit]')).toHaveAttribute('data-ai-user-credit-effective', '100');
        figures.restored = { override: 'platform', budget_usd: '5.00' };

        fs.writeFileSync(path.join(CAPTURES, 'figures.json'), JSON.stringify(figures, null, 2));
        assertClean(watch);

        // La video du parcours complet est copiee dans le dossier de captures a la
        // fin de l'enregistrement (voir test.afterEach).
    });

    test.afterEach(async ({ page }, testInfo) => {
        const video = page.video();
        // Le test mobile ouvre son propre contexte : seule la video du parcours
        // principal (fixture `page`) est copiee.
        if (!video || testInfo.title.startsWith('mobile')) return;
        const target = path.join(CAPTURES, 'parcours-credit-ia.webm');
        await page.context().close();
        try {
            await video.saveAs(target);
        } catch (e) {
            // La video n'est disponible qu'apres fermeture du contexte ; ne pas faire echouer le parcours pour une copie.
        }
    });

    test('mobile 390x844 : Mes usages IA (credit) et Monetisation IA', async ({ browser }) => {
        test.setTimeout(120000);
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
        const page = await context.newPage();
        const watch = watchConsole(page);

        await login(page, ORG_SLUG, JONAS);
        await page.goto(USER_PAGE);
        await expect(page.locator('[data-my-ai-credit]')).toBeVisible();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflow).toBeLessThanOrEqual(0);
        await page.screenshot({ path: path.join(CAPTURES, '09a-mobile-mes-usages-ia-credit.png'), fullPage: true });

        await login(page, PLATFORM_ADMIN_ORG_SLUG, PLATFORM_ADMIN);
        await page.goto(PLATFORM_PAGE);
        await expect(page.locator('[data-ai-monetization-form]')).toBeVisible();
        const overflowAdmin = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
        expect(overflowAdmin).toBeLessThanOrEqual(0);
        await page.screenshot({ path: path.join(CAPTURES, '09b-mobile-monetisation-ia.png'), fullPage: true });

        assertClean(watch);
        await context.close();
    });
});
