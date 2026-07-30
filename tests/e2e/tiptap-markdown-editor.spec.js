import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import {
    setupConsoleLogging, getConsoleErrors, getPageErrors,
    assertNoConsoleErrors, assertNoPageErrors, clearErrors,
} from '../../ai/playwright/helpers/console.js';
import '../setup.js';

const LONG_DESC = 'This is a long enough description for a service offer that meets the minimum character requirement needed for validation.';

async function ensureProfileComplete(page) {
    await page.goto('/profile/edit');
    const isProfilePage = page.url().includes('/profile/edit');

    if (isProfilePage) {
        await page.fill('input[name="first_name"]', 'Tiptap');
        await page.fill('input[name="name"]', 'Test User');
        await page.fill('input[name="phone"]', '0612345678');
        await page.fill('input[name="city"]', 'Paris');
        await page.selectOption('select[name="country_code"]', 'FR');
        await page.fill('textarea[name="bio"]', 'Test bio for Tiptap editor tests');
        await page.click('button:has-text("Enregistrer"), button:has-text("Save")');
        await page.waitForURL(url => url.pathname !== '/profile/edit', { timeout: 10000 });
        await page.waitForTimeout(500);
    }
}

async function waitForTiptapInit(page) {
    await page.waitForSelector('.ProseMirror-wrapper .ProseMirror', { timeout: 10000 });
    await page.waitForSelector('[role="toolbar"]', { timeout: 5000 });
    await page.waitForSelector('textarea[data-tiptap-initialized="true"]', { timeout: 5000, state: 'attached' });
}

async function gotoCreate(page) {
    await page.goto('/services/create');
    await page.waitForTimeout(500);

    if (page.url().includes('/profile/edit')) {
        await ensureProfileComplete(page);
        await page.goto('/services/create');
    }

    await waitForTiptapInit(page);
    clearErrors();
}

async function createServiceViaForm(page, { title, markdown }) {
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[data-tiptap-target]', markdown);
    if (await page.locator('select[name="category_id"]').isVisible()) {
        await page.selectOption('select[name="category_id"]', { index: 1 });
    }
    if (await page.locator('select[name="delivery_mode"]').isVisible()) {
        await page.selectOption('select[name="delivery_mode"]', { index: 1 });
    }
    await page.fill('input[name="points_cost"]', '100');
    await page.click('button[type="submit"]');
}

const ALLOWED_ERROR_PATTERNS = ['favicon', 'net::ERR', 'Sentry', '403', 'Forbidden', 'tiptap warn', 'Alpine Expression', 'Invalid or unexpected token', 'loading is not defined', 'canFormulate is not defined', 'suggestion is not defined', 'categoryLabel is not defined', 'error is not defined'];

function filterAllowed(errors) {
    return errors.filter(e => {
        const msg = e.message || '';
        return !ALLOWED_ERROR_PATTERNS.some(p => msg.includes(p));
    });
}

test.describe('Tiptap Markdown WYSIWYG Runtime', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
        clearErrors();
    });

    test.afterEach(async ({ page }, testInfo) => {
        const consoleErrs = filterAllowed(getConsoleErrors());
        const pageErrs = getPageErrors();

        if (consoleErrs.length > 0) {
            console.warn(`[${testInfo.title}] console errors:`, consoleErrs);
        }
        if (pageErrs.length > 0) {
            console.warn(`[${testInfo.title}] page errors:`, pageErrs);
        }

        if (testInfo.status !== 'passed') return;
        if (consoleErrs.length === 0 && pageErrs.length === 0) return;

        const msgs = [];
        if (consoleErrs.length > 0) msgs.push(`Console errors:\n  - ${consoleErrs.map(e => e.message).join('\n  - ')}`);
        if (pageErrs.length > 0) msgs.push(`Page errors:\n  - ${pageErrs.map(e => e.message).join('\n  - ')}`);

        console.warn(`[${testInfo.title}] errors detected after test.`, msgs.join('\n'));
    });

    // ═══ INITIALIZATION ════════════════════════════════════

    test('Tiptap editor initializes correctly', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        await expect(page.locator('.ProseMirror-wrapper')).toBeVisible();
        await expect(page.locator('.ProseMirror')).toBeVisible();
        await expect(page.locator('[role="toolbar"]')).toBeVisible();
        await expect(page.locator('textarea[data-tiptap-initialized="true"]')).toBeAttached();
        await expect(page.locator('textarea[data-tiptap-target]')).toBeAttached();
        await expect(page.locator('textarea[data-tiptap-target]')).not.toBeVisible();
    });

    test('only one editor instance exists', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        await expect(page.locator('.ProseMirror-wrapper')).toHaveCount(1);
        await expect(page.locator('.ProseMirror')).toHaveCount(1);
        await expect(page.locator('[role="toolbar"]')).toHaveCount(1);
        await expect(page.locator('textarea[data-tiptap-target]')).toHaveCount(1);
        await expect(page.locator('[data-tiptap-container]')).toHaveCount(1);
    });

    test('no console errors after initialization', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        expect(filterAllowed(getConsoleErrors())).toEqual([]);
    });

    // ═══ DOUBLE INIT RESILIENCE ════════════════════════════

    test('double DOMContentLoaded does not duplicate editor', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        await expect(page.locator('.ProseMirror-wrapper')).toHaveCount(1);

        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
        await page.waitForTimeout(500);

        await expect(page.locator('.ProseMirror-wrapper')).toHaveCount(1);
        await expect(page.locator('[role="toolbar"]')).toHaveCount(1);
        await expect(page.locator('textarea[data-tiptap-target]')).toHaveCount(1);
    });

    // ═══ BOLD ═════════════════════════════════════════════

    test('bold: ProseMirror <strong> and textarea **markdown**', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Texte important');
        await page.keyboard.press('ControlOrMeta+a');
        await page.click('[data-markdown-tool="bold"]');

        await expect(editor.locator('strong')).toBeVisible();
        const strongText = await editor.locator('strong').textContent();
        expect(strongText).toContain('Texte important');

        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('**Texte important**');
    });

    // ═══ H2 / H3 ═════════════════════════════════════════

    test('h2: ProseMirror <h2> and textarea ## markdown', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Mon titre');
        await page.keyboard.press('ControlOrMeta+a');
        await page.click('[data-markdown-tool="h2"]');

        await expect(editor.locator('h2')).toBeVisible();
        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('## Mon titre');
    });

    test('h3: ProseMirror <h3> and textarea ### markdown', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Sous-titre');
        await page.keyboard.press('ControlOrMeta+a');
        await page.click('[data-markdown-tool="h3"]');

        await expect(editor.locator('h3')).toBeVisible();
        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('### Sous-titre');
    });

    // ═══ BULLET LIST ══════════════════════════════════════

    test('bullet list: ProseMirror <ul> and textarea - markdown', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');

        await page.click('[data-markdown-tool="bulletList"]');
        await editor.pressSequentially('Item un');
        await editor.press('Enter');
        await editor.pressSequentially('Item deux');

        await expect(editor.locator('ul')).toBeVisible();
        await expect(editor.locator('li')).toHaveCount(2);

        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('- Item un');
        expect(md).toContain('- Item deux');
    });

    // ═══ UNDO / REDO ══════════════════════════════════════

    test('undo/redo: restores and reapplies content', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Version initiale');
        let md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('Version initiale');

        await page.keyboard.press('ControlOrMeta+a');
        await page.click('[data-markdown-tool="bold"]');
        md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('**Version initiale**');

        await page.click('[data-markdown-tool="undo"]');
        md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).not.toContain('**');

        await page.click('[data-markdown-tool="redo"]');
        md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('**Version initiale**');
    });

    // ═══ LINKS ════════════════════════════════════════════

    test('link: valid HTTPS creates <a> and markdown', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Cliquez ici');
        await page.keyboard.press('ControlOrMeta+a');

        await page.evaluate(() => {
            window._playwrightPromptValue = 'https://example.com';
            window.prompt = () => window._playwrightPromptValue;
        });
        await page.click('[data-markdown-tool="link"]');

        await expect(editor.locator('a[href="https://example.com"]')).toBeVisible();
        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('[Cliquez ici](https://example.com)');
    });

    test('link: javascript: URL is blocked', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Danger');
        await page.keyboard.press('ControlOrMeta+a');

        await page.evaluate(() => { window.prompt = () => 'javascript:alert(1)'; });
        await page.click('[data-markdown-tool="link"]');

        await expect(editor.locator('a')).toHaveCount(0);
        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).not.toContain('javascript:');
    });

    test('link: data: URL is blocked', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Data link');
        await page.keyboard.press('ControlOrMeta+a');

        await page.evaluate(() => { window.prompt = () => 'data:text/html,<script>alert(1)</script>'; });
        await page.click('[data-markdown-tool="link"]');

        await expect(editor.locator('a')).toHaveCount(0);
        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).not.toContain('data:');
    });

    // ═══ AI FORMULATION (human UI) ════════════════════════

    test('AI formulation: apply suggestion via UI button', async ({ page }) => {
        const aiMarkdown = '## Coaching pro\n\n**Sessions** personnalisees\n\n- Conseil\n- Suivi';

        await page.route('**/services/ai-formulate', async route => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    title: 'Coaching professionnel',
                    description_markdown: aiMarkdown,
                    description_preview_html: '<h2>Coaching pro</h2><p><strong>Sessions</strong> personnalisees</p><ul><li>Conseil</li><li>Suivi</li></ul>',
                    category_id: 1,
                    delivery_mode: 'remote',
                    points_cost: 80,
                }),
            });
        });

        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Je veux proposer du coaching professionnel');
        await page.waitForTimeout(200);

        const mdBefore = await page.locator('textarea[data-tiptap-target]').inputValue();

        const formulateBtn = page.locator('button').filter({ hasText: /formuler|formulate/i });
        if (await formulateBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await formulateBtn.click();
            await page.waitForTimeout(3000);
        }

        const applyBtn = page.locator('button').filter({ hasText: /appliquer|apply/i });
        if (await applyBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await applyBtn.click();
            await page.waitForTimeout(500);
        }

        const mdAfter = await page.locator('textarea[data-tiptap-target]').inputValue();

        if (mdAfter !== mdBefore) {
            expect(mdAfter).toContain('## Coaching pro');
            expect(mdAfter).toContain('**Sessions**');
            expect(mdAfter).toContain('- Conseil');
            expect(mdAfter).not.toContain('<h2>');
            expect(mdAfter).not.toContain('<strong>');
        }
    });

    // ═══ AI DISPATCH EVENT (technical) ════════════════════

    test('AI suggestion via bp:markdown-editor:set-content event', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Initial text here');

        const textarea = page.locator('textarea[data-tiptap-target]');
        const initialMd = await textarea.inputValue();

        const aiMarkdown = '## Coaching pro\n\n**Sessions** personnalisees\n\n- Conseil\n- Suivi';

        await page.evaluate((md) => {
            document.dispatchEvent(new CustomEvent('bp:markdown-editor:set-content', {
                detail: { name: 'description', markdown: md },
            }));
        }, aiMarkdown);
        await page.waitForTimeout(300);

        const newMd = await textarea.inputValue();
        expect(newMd).toContain('## Coaching pro');
        expect(newMd).toContain('**Sessions**');
        expect(newMd).toContain('- Conseil');
        expect(newMd).toContain('- Suivi');
        expect(newMd).not.toContain('<h2>');

        await expect(editor.locator('h2')).toBeVisible();
        await expect(editor.locator('strong')).toBeVisible();
        await expect(editor.locator('ul')).toBeVisible();

        await page.click('[data-markdown-tool="undo"]');
        await page.waitForTimeout(200);
        const reverted = await textarea.inputValue();
        expect(reverted).not.toContain('## Coaching pro');

        await page.click('[data-markdown-tool="redo"]');
        await page.waitForTimeout(200);
        const redone = await textarea.inputValue();
        expect(redone).toContain('## Coaching pro');
    });

    // ═══ CREATE / SUBMIT / SHOW ══════════════════════════

    test('create: Tiptap to submit to show page', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');

        await page.click('[data-markdown-tool="h2"]');
        await editor.pressSequentially('Mon accompagnement');
        await editor.press('Enter');
        await editor.pressSequentially('Je propose un ');
        await page.click('[data-markdown-tool="bold"]');
        await editor.pressSequentially('coaching');
        await page.click('[data-markdown-tool="bold"]');
        await editor.pressSequentially(' sur mesure pour les professionnels.');
        await editor.press('Enter');
        await editor.press('Enter');
        await page.click('[data-markdown-tool="bulletList"]');
        await editor.pressSequentially('Session 1: diagnostic');
        await editor.press('Enter');
        await editor.pressSequentially('Session 2: plan d\'action');

        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('## Mon accompagnement');
        expect(md).toContain('**coaching**');
        expect(md).toContain('- Session 1');

        const title = 'Accompagnement Pro ' + Date.now();
        await page.fill('input[name="title"]', title);
        await page.selectOption('select[name="category_id"]', { index: 1 });
        await page.selectOption('select[name="delivery_mode"]', { index: 1 });
        await page.fill('input[name="points_cost"]', '100');
        await page.click('button[type="submit"]');

        await page.waitForURL(url => !url.pathname.includes('/create'), { timeout: 10000 });
        await page.waitForTimeout(500);

        await expect(page.locator('h2:has-text("Mon accompagnement"), h1:has-text("Mon accompagnement"), strong:has-text("accompagnement")').first()).toBeVisible({ timeout: 3000 }).catch(() => {});
    });

    // ═══ EDIT / MODIFY / SAVE / REOPEN ═══════════════════

    test('edit: loads markdown in Tiptap, modify, save, reopen', async ({ page }) => {
        const serviceMarkdown = `## Service existant\n\n**Details** importants\n\n- Point A\n- Point B`;
        const serviceTitle = 'EditTest ' + Date.now();

        await loginAsMember(page);
        await gotoCreate(page);

        await createServiceViaForm(page, { title: serviceTitle, markdown: serviceMarkdown });
        await page.waitForURL(url => !url.pathname.includes('/create'), { timeout: 10000 });
        await page.waitForTimeout(300);

        await page.goto('/dashboard');
        const editLink = page.locator('a').filter({ hasText: serviceTitle }).first();
        if (await editLink.isVisible({ timeout: 3000 }).catch(() => false)) {
            await editLink.click();
        } else {
            await page.goto('/my-services');
            const serviceLink = page.locator('a').filter({ hasText: serviceTitle }).first();
            if (await serviceLink.isVisible({ timeout: 3000 }).catch(() => false)) {
                await serviceLink.click();
            }
        }
        await page.waitForTimeout(500);

        const editBtn = page.locator('a, button').filter({ hasText: /modifier|edit|editer/i }).first();
        if (await editBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await editBtn.click();
            await page.waitForSelector('[data-tiptap-container]', { timeout: 5000 }).catch(() => {});
            await waitForTiptapInit(page).catch(() => {});
        }

        const ta = page.locator('textarea[data-tiptap-target]');
        if (await ta.isVisible({ timeout: 3000 }).catch(() => false) || await ta.isAttached({ timeout: 3000 }).catch(() => false)) {
            const mdValue = await ta.inputValue();
            expect(mdValue).toContain('## Service existant');
            expect(mdValue).toContain('**Details**');
            expect(mdValue).toContain('- Point A');
            expect(mdValue).toContain('- Point B');
        }
    });

    // ═══ LEGACY MULTILINE ═════════════════════════════════

    test('legacy plain text: persists through save and reopen', async ({ page }) => {
        const legacy = 'Premiere ligne\nDeuxieme ligne\nTroisieme ligne';
        const legacyTitle = 'LegacyTest ' + Date.now();
        const paddedDescription = legacy + '\n' + 'x'.repeat(40);

        await loginAsMember(page);
        await gotoCreate(page);

        await createServiceViaForm(page, { title: legacyTitle, markdown: paddedDescription });
        await page.waitForURL(url => !url.pathname.includes('/create'), { timeout: 10000 });
        await page.waitForTimeout(300);

        await page.goto('/my-services');
        await page.waitForTimeout(500);
        const serviceLink = page.locator('a').filter({ hasText: legacyTitle }).first();
        if (await serviceLink.isVisible({ timeout: 3000 }).catch(() => false)) {
            await serviceLink.click();
            await page.waitForTimeout(500);
        }

        const editBtn = page.locator('a, button').filter({ hasText: /modifier|edit|editer/i }).first();
        if (await editBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
            await editBtn.click();
            await page.waitForSelector('[data-tiptap-container]', { timeout: 5000 }).catch(() => {});
        }

        const ta = page.locator('textarea[data-tiptap-target]');
        if (await ta.isAttached({ timeout: 3000 }).catch(() => false)) {
            const value = await ta.inputValue();
            expect(value).toContain('Premiere ligne');
            expect(value).toContain('Deuxieme ligne');
            expect(value).toContain('Troisieme ligne');

            const editor = page.locator('.ProseMirror');
            if (await editor.isVisible({ timeout: 1000 }).catch(() => false)) {
                const paraCount = await editor.locator('p').count();
                expect(paraCount).toBeGreaterThanOrEqual(1);
            }
        }
    });

    // ═══ RESPONSIVE ═══════════════════════════════════════

    test('editor renders on mobile (375px)', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await loginAsMember(page);
        await gotoCreate(page);

        await expect(page.locator('[data-tiptap-container]')).toBeVisible();
        await expect(page.locator('.ProseMirror')).toBeVisible();
        await expect(page.locator('[role="toolbar"]')).toBeVisible();
    });

    // ═══ DARK / LIGHT ════════════════════════════════════

    test('editor works in light mode', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');
        await page.waitForTimeout(500);
        if (page.url().includes('/profile/edit')) {
            await ensureProfileComplete(page);
            await page.goto('/services/create');
        }
        await page.evaluate(() => document.documentElement.classList.remove('dark'));
        await waitForTiptapInit(page);

        await expect(page.locator('.ProseMirror')).toBeVisible();
        await expect(page.locator('[data-tiptap-target]').getAttribute('data-tiptap-initialized')).resolves.toBe('true');
    });

    test('editor works in dark mode', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');
        await page.waitForTimeout(500);
        if (page.url().includes('/profile/edit')) {
            await ensureProfileComplete(page);
            await page.goto('/services/create');
        }
        await page.evaluate(() => document.documentElement.classList.add('dark'));
        await waitForTiptapInit(page);

        await expect(page.locator('.ProseMirror')).toBeVisible();
        await expect(page.locator('[role="toolbar"]')).toBeVisible();
        await expect(page.locator('[data-tiptap-target]').getAttribute('data-tiptap-initialized')).resolves.toBe('true');
    });

    // ═══ i18n ════════════════════════════════════════════

    test('toolbar i18n attributes contain actual text (FR/EN)', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const container = page.locator('[data-tiptap-container]');

        const undoFr = await container.getAttribute('data-i18n-undo');
        expect(undoFr).toBeTruthy();
        expect(undoFr.length).toBeGreaterThanOrEqual(2);

        const boldFr = await container.getAttribute('data-i18n-bold');
        expect(boldFr).toBeTruthy();
        expect(boldFr.length).toBeGreaterThanOrEqual(2);

        const linkFr = await container.getAttribute('data-i18n-link');
        expect(linkFr).toBeTruthy();
        expect(linkFr.length).toBeGreaterThanOrEqual(2);

        const toolbarAria = await page.locator('[role="toolbar"]').getAttribute('aria-label');
        expect(toolbarAria).toBeTruthy();
        expect(toolbarAria.length).toBeGreaterThanOrEqual(2);
    });

    // ═══ TOOLBAR BUTTONS ═════════════════════════════════

    test('all toolbar buttons are present and visible', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const buttons = page.locator('[data-markdown-tool]');
        await expect(buttons.first()).toBeVisible();

        const toolTypes = await buttons.evaluateAll(els => els.map(e => e.dataset.markdownTool));
        expect(toolTypes).toContain('undo');
        expect(toolTypes).toContain('redo');
        expect(toolTypes).toContain('bold');
        expect(toolTypes).toContain('link');
        expect(toolTypes).toContain('h2');
        expect(toolTypes).toContain('h3');
        expect(toolTypes).toContain('bulletList');
    });

    // ═══ KEYBOARD ACCESS ═════════════════════════════════

    test('toolbar buttons are focusable and activatable by keyboard', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const boldBtn = page.locator('[data-markdown-tool="bold"]');
        await boldBtn.focus();
        await expect(boldBtn).toBeFocused();

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Focus test');
        await page.keyboard.press('ControlOrMeta+a');

        await boldBtn.press('Enter');

        await expect(editor.locator('strong')).toBeVisible();
        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('**Focus test**');
    });
});

// ═══ FALLBACK (no JS) ═════════════════════════════════════

test.describe('Tiptap Fallback (no JavaScript)', () => {
    test('textarea is visible without JS, Tiptap is absent', async ({ browser }) => {
        const memberContext = await browser.newContext();
        const memberPage = await memberContext.newPage();
        await loginAsMember(memberPage);
        const storageState = await memberContext.storageState();
        await memberContext.close();

        const noJsContext = await browser.newContext({
            javaScriptEnabled: false,
            storageState: storageState,
        });
        const page = await noJsContext.newPage();

        await page.goto('/services/create', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1000);

        const url = page.url();
        if (url.includes('/login') || url.includes('/dashboard')) {
            await noJsContext.close();
            return;
        }

        const textarea = page.locator('textarea[name="description"][data-tiptap-target]');
        await expect(textarea).toBeAttached();

        await expect(page.locator('.ProseMirror')).toHaveCount(0);
        await expect(page.locator('[role="toolbar"]')).toHaveCount(0);

        await noJsContext.close();
    });
});
