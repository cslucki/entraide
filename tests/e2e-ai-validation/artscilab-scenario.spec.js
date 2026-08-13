// TASK-1203 - ArtSciLab/LaunchPals product-validation scenario.
//
// Uses the dedicated baseURL from playwright.ai-validation.config.mjs and is
// intentionally self-contained, like sentinel-isolation.spec.js. The records
// asserted below are deterministic outputs of ArtSciLabScenarioSeeder.

import { stat } from 'node:fs/promises';
import { test, expect } from '@playwright/test';

const ORG_SLUG = 'artscilab-demo';
const ORG_ROOT = `/org/${ORG_SLUG}`;
const EMAIL = 'maya@artscilab-demo.test';
const PASSWORD = 'password';
const TENANT_SENTINEL = /SENTINEL-(?:A|B)/i;

const LOOP_SCENARIOS = [
    {
        name: 'LaunchPals',
        description: 'The shared home for introductions, mutual help and monthly gatherings.',
        content: /\[ASL-\d{3}\].*(introductions|open studio|skills we can exchange|newcomers)/i,
    },
    {
        name: 'Emergence',
        description: 'A workshop for fragile ideas that need critique, partners and a first test.',
        content: /\[ASL-\d{3}\].*(tactile climate prototype|field test|evidence)/i,
    },
    {
        name: 'Experimental Publishing',
        description: 'An editorial studio for field notes, essays and unusual publication formats.',
        content: /\[ASL-\d{3}\].*(field-notes|editing|image permissions|public reading)/i,
    },
    {
        name: 'AI Shepherd & Ethics',
        description: 'A working group for accountable choices around generative and adaptive systems.',
        content: /\[ASL-\d{3}\].*(consent|human review|model limits|red-team)/i,
    },
    {
        name: 'European Projects',
        description: 'A delivery room for consortium building, budgets, milestones and evidence.',
        content: /\[ASL-\d{3}\].*(Horizon|partner responsibilities|travel budget|delivery calendar)/i,
    },
];

const MEMBERS = [
    'Maya Chen', 'Jonas Weber', 'Sofia Rossi', 'Theo Martin', 'Amina Diallo',
    'Lucas Novak', 'Ines Costa', 'Noah Williams', 'Elena Petrov', 'Samir Haddad',
    'Clara Jensen',
];

const ARTICLE_TITLES = [
    'Why LaunchPals starts with useful offers',
    'Field notes from a low-bandwidth exhibition',
    'A consent checklist for generative media',
    'What an honest prototype review sounds like',
    'Editing collective essays without one institutional voice',
    'The hidden work in a European consortium',
    'Three ways to host a better open studio',
    'When evaluation becomes part of the artwork',
    'A touring installation that fits in one case',
    'Human checkpoints for adaptive systems',
    'Budget narratives are design documents',
];

const DOSSIER_NAMES = [
    'LaunchPals — Briefs',
    'LaunchPals — Member Needs',
    'LaunchPals — Pilot Results',
    'Emergence — Session 01',
    'Emergence — Session 02',
    'Emergence — Session 03',
    'Emergence — References',
    'Experimental Publishing — Drafts',
    'Experimental Publishing — Sources',
    'Experimental Publishing — Published',
    'AI Ethics — Papers',
    'AI Ethics — Working Notes',
    'European Projects — Proposal',
    'European Projects — Budget',
    'European Projects — Partners',
];

const qualityByPage = new WeakMap();

async function login(page) {
    await page.goto(`${ORG_ROOT}/login`);
    await page.fill('input[name="email"]', EMAIL);
    await page.fill('input[name="password"]', PASSWORD);
    await page.getByRole('button', { name: /sign in|se connecter/i }).click();
    await page.waitForURL((url) => url.pathname !== `${ORG_ROOT}/login`);

    expect(page.url()).toContain(ORG_ROOT);
    expect(page.url()).not.toContain('/profile/edit');
    await page.goto(ORG_ROOT);
}

function capturePageQuality(page) {
    const quality = { consoleErrors: [], failedCriticalRequests: [] };

    page.on('console', (message) => {
        if (message.type() === 'error') {
            quality.consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => quality.consoleErrors.push(error.message));
    page.on('requestfailed', (request) => {
        const isExpectedDownload = request.resourceType() === 'document'
            && request.url().includes('/dossiers/')
            && request.url().includes('/files/');

        if (isExpectedDownload) {
            return;
        }
        if (['document', 'xhr', 'fetch', 'script', 'stylesheet'].includes(request.resourceType())) {
            quality.failedCriticalRequests.push(
                `${request.method()} ${request.url()} (${request.failure()?.errorText || 'request failed'})`,
            );
        }
    });
    page.on('response', (response) => {
        if (response.status() >= 500
            && ['document', 'xhr', 'fetch', 'script', 'stylesheet'].includes(response.request().resourceType())) {
            quality.failedCriticalRequests.push(
                `${response.status()} ${response.request().method()} ${response.url()}`,
            );
        }
    });

    qualityByPage.set(page, quality);
}

async function expectTenantIsolation(page) {
    await expect(page.locator('body')).not.toContainText(TENANT_SENTINEL);
}

async function gotoTenantPage(page, path) {
    const response = await page.goto(`${ORG_ROOT}${path}`);

    expect(response, `No main-document response for ${path}`).not.toBeNull();
    expect(response.status(), `Unexpected status for ${path}`).toBeLessThan(400);
    await expectTenantIsolation(page);
}

function expectTenantPath(href, pathPrefix) {
    expect(href).not.toBeNull();
    expect(new URL(href, 'http://playwright.invalid').pathname).toMatch(
        new RegExp(`^${ORG_ROOT}${pathPrefix}`),
    );
}

async function downloadFileFromDossier(page, dossierHref, displayName, expectedFilename) {
    await page.goto(dossierHref);
    await expectTenantIsolation(page);
    await page.getByRole('tab', { name: 'Files', exact: true }).click();

    const row = page.locator('tr').filter({ hasText: displayName });
    await expect(row).toBeVisible();

    const [download] = await Promise.all([
        page.waitForEvent('download'),
        row.locator('a[title="Download"]').click(),
    ]);

    expect(download.suggestedFilename()).toBe(expectedFilename);
    expect(await download.failure()).toBeNull();
    const downloadedPath = await download.path();
    expect(downloadedPath).not.toBeNull();
    expect((await stat(downloadedPath)).size).toBeGreaterThan(0);
}

test.describe('AI validation - ArtSciLab scenario (TASK-1203)', () => {
    test.setTimeout(120_000);

    test.beforeEach(async ({ page }) => capturePageQuality(page));

    test.afterEach(async ({ page }) => {
        const quality = qualityByPage.get(page);

        expect(
            quality.consoleErrors,
            `Unexpected browser console/page errors:\n${quality.consoleErrors.join('\n')}`,
        ).toEqual([]);
        expect(
            quality.failedCriticalRequests,
            `Failed critical network requests:\n${quality.failedCriticalRequests.join('\n')}`,
        ).toEqual([]);
    });

    test('login and desktop principal surfaces remain tenant-scoped', async ({ page }) => {
        await login(page);
        await expectTenantIsolation(page);

        const desktopNavigation = page.locator('aside').getByRole('navigation');
        await expect(desktopNavigation).toBeVisible();
        for (const label of ['Loops', 'Agenda', 'Exchanges', 'Messaging', 'Directory', 'Blog', 'My folders']) {
            await expect(desktopNavigation.getByRole('link', { name: label, exact: true })).toBeVisible();
        }

        await desktopNavigation.getByRole('link', { name: 'Directory', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${ORG_ROOT}/membres$`));
        await expect(page.getByRole('heading', { name: 'Member directory' })).toBeVisible();
        await expectTenantIsolation(page);

        await page.locator('aside').getByRole('navigation').getByRole('link', { name: 'Exchanges', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${ORG_ROOT}/explorer`));
        await expect(page.getByRole('heading', { name: 'Exchanges' })).toBeVisible();
        await expectTenantIsolation(page);
    });

    test('all five Loops open with their seeded conversation content', async ({ page }) => {
        await login(page);
        await gotoTenantPage(page, '/loops');

        const loopLinks = {};
        for (const scenario of LOOP_SCENARIOS) {
            const link = page.getByRole('link', { name: scenario.name, exact: true }).first();
            await expect(link).toBeVisible();
            loopLinks[scenario.name] = await link.getAttribute('href');
            expectTenantPath(loopLinks[scenario.name], '/loops/');
        }

        for (const scenario of LOOP_SCENARIOS) {
            await page.goto(loopLinks[scenario.name]);
            await expect(page.getByRole('heading', { name: scenario.name, exact: true })).toBeVisible();
            await expect(page.getByText(scenario.description, { exact: true })).toBeVisible();
            await expect(page.locator('[data-loop-workspace-chat]')).toContainText(scenario.content);
            await expectTenantIsolation(page);
        }
    });

    test('member directory contains exactly the eleven ArtSciLab members', async ({ page }) => {
        await login(page);
        await gotoTenantPage(page, '/membres');

        await expect(page.getByText('11 registered members', { exact: true })).toBeVisible();
        for (const member of MEMBERS) {
            await expect(page.getByRole('link').filter({ hasText: member })).toBeVisible();
        }

        const profileLinks = page.locator(`main a[href*="${ORG_ROOT}/profile/"]`);
        await expect(profileLinks).toHaveCount(11);
    });

    test('marketplace and Maya author dashboards expose requests and offers', async ({ page }) => {
        await login(page);
        await gotoTenantPage(page, '/explorer?tab=requests');

        await expect(page.getByRole('button', { name: 'Requests', exact: true })).toBeVisible();
        await expect(page.getByText('[ArtSciLab] Build a touring equipment checklist', { exact: true })).toBeVisible();
        await expect(page.getByText('[ArtSciLab] Design an evaluation reflection kit', { exact: true })).toBeVisible();

        await page.getByRole('button', { name: 'Proposals', exact: true }).click();
        await expect(page.getByText('[ArtSciLab] Participatory installation prototyping', { exact: true })).toBeVisible();
        await expect(page.getByText('[ArtSciLab] Community hosting playbook', { exact: true })).toBeVisible();
        await expectTenantIsolation(page);

        await gotoTenantPage(page, '/dashboard/requests');
        await expect(page.getByText('[ArtSciLab] Review our Creative Europe concept note', { exact: true })).toBeVisible();
        await expect(page.locator('tbody tr')).toHaveCount(1);

        await gotoTenantPage(page, '/dashboard/services');
        await expect(page.getByText('[ArtSciLab] Learning and evaluation design', { exact: true })).toBeVisible();
        await expect(page.locator('tbody tr')).toHaveCount(1);
    });

    test('seeded transaction is discoverable through Maya messaging UI', async ({ page }) => {
        await login(page);
        await gotoTenantPage(page, '/messages');

        const conversation = page.getByRole('link', {
            name: /Theo Theo Martin Completed \[ArtSciLab\] Participatory installation prototyping/,
        });
        await expect(conversation).toBeVisible();
        await expect(conversation).toContainText('Theo');

        await conversation.click();
        await expect(page).toHaveURL(new RegExp(`${ORG_ROOT}/messages/[0-9a-f-]+$`));
        await expect(page.getByRole('link', { name: '[ArtSciLab] Participatory installation prototyping', exact: true })).toBeVisible();
        await expect(page.getByText('80 pts', { exact: true }).filter({ visible: true })).toBeVisible();
        await expectTenantIsolation(page);
    });

    test('published article catalogue and representative article bodies are available', async ({ page }) => {
        await login(page);
        await gotoTenantPage(page, '/blog');

        for (const title of ARTICLE_TITLES) {
            await expect(page.getByRole('link', { name: title, exact: true }).first()).toBeVisible();
        }

        for (const [slug, title] of [
            ['artscilab-01', ARTICLE_TITLES[0]],
            ['artscilab-11', ARTICLE_TITLES[10]],
        ]) {
            await gotoTenantPage(page, `/blog/${slug}`);
            await expect(page.getByRole('heading', { name: title, exact: true }).first()).toBeVisible();
            await expect(page.locator('main')).toContainText('At ArtSciLab we document the people involved');
        }
    });

    test('all fifteen Dossiers are navigable and seeded files really download', async ({ page }) => {
        await login(page);
        await gotoTenantPage(page, '/dossiers');

        const dossierHrefs = {};
        for (const name of DOSSIER_NAMES) {
            const link = page.getByRole('link', { name, exact: true }).first();
            await expect(link).toBeVisible();
            dossierHrefs[name] = await link.getAttribute('href');
            expectTenantPath(dossierHrefs[name], '/dossiers/');
        }

        for (const name of DOSSIER_NAMES) {
            await page.goto(dossierHrefs[name]);
            await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
            await expectTenantIsolation(page);
        }

        await downloadFileFromDossier(
            page,
            dossierHrefs['LaunchPals — Briefs'],
            'Launchpals Charter',
            'launchpals-charter.md',
        );
        await downloadFileFromDossier(
            page,
            dossierHrefs['LaunchPals — Briefs'],
            'Evaluation Prompts',
            'evaluation-prompts.md',
        );
        await downloadFileFromDossier(
            page,
            dossierHrefs['LaunchPals — Member Needs'],
            'Open Studio Runbook',
            'open-studio-runbook.md',
        );
        await downloadFileFromDossier(
            page,
            dossierHrefs['LaunchPals — Member Needs'],
            'Access Travel Plan',
            'access-travel-plan.txt',
        );
    });

    test('real semantic search retrieves the tenant-local human oversight article', async ({ page }) => {
        await login(page);
        await gotoTenantPage(page, '/dossiers');

        const dossierLink = page.getByRole('link', { name: 'AI Ethics — Papers', exact: true }).first();
        await expect(dossierLink).toBeVisible();
        await dossierLink.click();
        await expect(page.getByRole('heading', { name: 'AI Ethics — Papers', exact: true })).toBeVisible();

        await page.locator('#dossier-semantic-search-query').fill('Where did we discuss cognitive autonomy and human oversight?');
        await page.getByRole('button', { name: 'Search', exact: true }).click();

        const result = page.locator('ol li').first();
        await expect(result).toContainText('Human checkpoints for adaptive systems');
        await expect(result).toContainText(/named reviewer|final decision|model limits/i);
        await expectTenantIsolation(page);

        const articleLink = result.getByRole('link', { name: 'Read article', exact: true });
        const href = await articleLink.getAttribute('href');
        expectTenantPath(href, '/blog/');
        await articleLink.click();
        await expect(page.getByRole('heading', { name: 'Human checkpoints for adaptive systems', exact: true }).first()).toBeVisible();
        await expectTenantIsolation(page);
    });

    test('mobile principal navigation reaches core ArtSciLab surfaces', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await login(page);

        const mobileTopbar = page.locator('header').filter({ has: page.getByRole('heading') });
        const mobileNavigation = page.locator('nav').filter({ has: page.getByRole('link', { name: 'Loops', exact: true }) });
        await expect(mobileTopbar).toBeVisible();
        await expect(mobileNavigation).toBeVisible();

        for (const label of ['Loops', 'Feed', 'Exchanges', 'Messaging', 'Directory']) {
            await expect(mobileNavigation.getByRole('link', { name: label, exact: true })).toBeVisible();
        }

        await mobileNavigation.getByRole('link', { name: 'Loops', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${ORG_ROOT}/loops$`));
        await expect(page.getByText('LaunchPals', { exact: true }).first()).toBeVisible();
        await expectTenantIsolation(page);

        await mobileNavigation.getByRole('link', { name: 'Directory', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${ORG_ROOT}/membres$`));
        await expect(page.getByText('11 registered members', { exact: true })).toBeVisible();
        await expectTenantIsolation(page);

        await mobileNavigation.getByRole('link', { name: 'Exchanges', exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${ORG_ROOT}/explorer`));
        await expect(page.getByRole('button', { name: 'Proposals', exact: true })).toBeVisible();
        await expectTenantIsolation(page);
    });
});
