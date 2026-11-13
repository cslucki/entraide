import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import {
    setupConsoleLogging, getConsoleErrors, getPageErrors,
    assertNoConsoleErrors, assertNoPageErrors, clearErrors,
} from '../../ai/playwright/helpers/console.js';
import '../setup.js';

// ─── Helpers ─────────────────────────────────────────────────────────

async function ensureProfileComplete(page) {
    await page.goto('/profile/edit');
    if (!page.url().includes('/profile/edit')) return;

    await page.fill('input[name="first_name"]', 'Tiptap');
    await page.fill('input[name="name"]', 'Test User');
    await page.fill('input[name="phone"]', '0612345678');
    await page.fill('input[name="city"]', 'Paris');
    await page.selectOption('select[name="country_code"]', 'FR');
    await page.fill('textarea[name="bio"]', 'Test bio for Tiptap editor tests.');
    await page.click('button:has-text("Enregistrer"), button:has-text("Save")');
    await page.waitForURL(url => url.pathname !== '/profile/edit', { timeout: 10000 });
    await page.waitForTimeout(500);
}

async function waitForTiptapInit(page) {
    await page.waitForSelector('.ProseMirror', { timeout: 10000 });
    await page.waitForSelector('[role="toolbar"]', { timeout: 5000 });
    await page.waitForSelector('textarea[data-tiptap-initialized="true"]', { timeout: 5000, state: 'attached' });
}

async function gotoCreate(page, options = {}) {
    const { locale } = options;

    if (locale) {
        await page.request.post('/locale/' + locale);
    }

    await page.goto('/services/create');
    await page.waitForTimeout(500);

    if (page.url().includes('/profile/edit')) {
        await ensureProfileComplete(page);
        await page.goto('/services/create');
    }

    await waitForTiptapInit(page);
    clearErrors();
}

// ─── Console allowlist — external errors only ─────────────────────────

const ALLOWED_ERROR_PATTERNS = [
    'favicon',
    'net::ERR',
    'Sentry',
    '403',
    'Forbidden',
    'tiptap warn',
    'Cannot redefine property',
    'Cannot redefine property: $persist',
];

function filterAllowed(errors) {
    return errors.filter(e => {
        const msg = e.message || '';
        return !ALLOWED_ERROR_PATTERNS.some(p => msg.includes(p));
    });
}

// ─── Test suites ─────────────────────────────────────────────────────

test.describe('Tiptap Markdown WYSIWYG Runtime', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
        clearErrors();
    });

    test.afterEach(async ({ page }, testInfo) => {
        if (testInfo.status !== 'passed') return;

        const consoleErrs = filterAllowed(getConsoleErrors());
        const pageErrs = filterAllowed(getPageErrors());

        if (consoleErrs.length > 0) {
            throw new Error(
                `Found ${consoleErrs.length} unfiltered console errors:\n` +
                `  - ${consoleErrs.map(e => e.message).join('\n  - ')}`
            );
        }
        if (pageErrs.length > 0) {
            throw new Error(
                `Found ${pageErrs.length} unfiltered page errors:\n` +
                `  - ${pageErrs.map(e => e.message).join('\n  - ')}`
            );
        }
    });

    // ═══ INITIALIZATION ════════════════════════════════════════════

    test('Tiptap editor initializes correctly', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        await expect(page.locator('.ProseMirror')).toBeVisible();
        await expect(page.locator('[role="toolbar"]')).toBeVisible();
        await expect(page.locator('textarea[data-tiptap-initialized="true"]')).toBeAttached();
        await expect(page.locator('textarea[data-tiptap-target]')).toBeAttached();
        await expect(page.locator('textarea[data-tiptap-target]')).not.toBeVisible();
    });

    test('only one editor instance exists', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        await expect(page.locator('.ProseMirror')).toHaveCount(1);
        await expect(page.locator('[role="toolbar"]')).toHaveCount(1);
        await expect(page.locator('textarea[data-tiptap-target]')).toHaveCount(1);
    });

    test('no console errors after initialization', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        expect(filterAllowed(getConsoleErrors())).toEqual([]);
    });

    // ═══ DOUBLE INIT RESILIENCE ════════════════════════════════════

    test('double DOMContentLoaded does not duplicate editor', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        await expect(page.locator('.ProseMirror')).toHaveCount(1);

        await page.evaluate(() => document.dispatchEvent(new Event('DOMContentLoaded')));
        await page.waitForTimeout(500);

        await expect(page.locator('.ProseMirror')).toHaveCount(1);
        await expect(page.locator('[role="toolbar"]')).toHaveCount(1);
    });

    // ═══ BOLD ══════════════════════════════════════════════════════

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

    // ═══ H2 / H3 ══════════════════════════════════════════════════

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

    // ═══ BULLET LIST ══════════════════════════════════════════════

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

    // ═══ UNDO / REDO ══════════════════════════════════════════════

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

    // ═══ LINKS ════════════════════════════════════════════════════

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

    // ═══ AI FORMULATION — blocking assertions, no catch/if ═══════

    test('AI formulation: formulate → preview → apply', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.pressSequentially('Je veux proposer du coaching professionnel pour les cadres dirigeants.');
        await page.waitForTimeout(500);

        await page.fill('input[name="title"]', 'Coaching professionnel pour cadres');
        await page.waitForTimeout(300);

        const formulateBtn = page.locator('button').filter({ hasText: /formuler|formulate/i });
        await expect(formulateBtn).toBeVisible({ timeout: 5000 });
        await expect(formulateBtn).toBeEnabled({ timeout: 8000 });

        const mockMarkdown = '## Coaching pro\n\n**Sessions** personnalisees\n\n- Conseil\n- Suivi';
        const mockPreviewHtml = '<h2>Coaching pro</h2><p><strong>Sessions</strong> personnalisees</p><ul><li>Conseil</li><li>Suivi</li></ul>';

        await page.evaluate(([markdown, previewHtml]) => {
            const origFetch = window.fetch;
            window.fetch = function(url, options) {
                if (typeof url === 'string' && url.includes('/ai-formulate')) {
                    return Promise.resolve(new Response(JSON.stringify({
                        suggestion: {
                            title: 'Coaching professionnel pour cadres',
                            description_markdown: markdown,
                            description_preview_html: previewHtml,
                            category_id: '00000000-0000-0000-0000-000000000001',
                            delivery_mode: 'remote',
                            points_cost: 50,
                        },
                    }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
                }
                return origFetch.call(this, url, options);
            };
        }, [mockMarkdown, mockPreviewHtml]);

        await formulateBtn.click();
        await page.waitForTimeout(3000);

        const preview = page.locator('[x-html*="suggestion"]');
        await expect(preview).toBeVisible({ timeout: 5000 });

        const applyBtn = page.locator('button').filter({ hasText: /appliquer|apply/i });
        await expect(applyBtn).toBeVisible({ timeout: 5000 });
        await applyBtn.click();
        await page.waitForTimeout(500);

        await expect(editor.locator('h2').first()).toBeVisible();
        await expect(editor.locator('strong').first()).toBeVisible();
        await expect(editor.locator('ul').first()).toBeVisible();

        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).toContain('## Coaching pro');
        expect(md).toContain('**Sessions**');
        expect(md).toContain('- Conseil');
        expect(md).not.toContain('<h2>');
        expect(md).not.toContain('<strong>');

        const isOnCreatePage = page.url().includes('/create');
        expect(isOnCreatePage).toBe(true);
    });

    // ═══ AI DISPATCH EVENT (technical) ════════════════════════════

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

    // ═══ CREATE / SUBMIT / SHOW — blocking assertions ═══════════

    test('create: Tiptap fill → submit → show page', async ({ page }) => {
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
        await editor.pressSequentially(' sur mesure.');
        await editor.press('Enter');
        await editor.press('Enter');
        await page.click('[data-markdown-tool="bulletList"]');
        await editor.pressSequentially('Session 1: diagnostic');
        await editor.press('Enter');
        await editor.pressSequentially('Session 2: plan action');
        await editor.press('Enter');
        await editor.pressSequentially('Session 3: suivi personnalise');

        const title = 'AccompagnementPro ' + Date.now();
        await page.fill('input[name="title"]', title);

        await page.waitForTimeout(500);
        await page.selectOption('select[name="category_id"]', { index: 1 });
        await page.locator('label:has(input[name="delivery_mode"])').first().click();

        await page.fill('input[name="points_cost"]', '100');
        await page.locator('form[data-marketplace-validation] button[type="submit"]').click();

        await page.waitForURL(url => url.pathname.includes('/services/') && !url.pathname.includes('/create') && !url.pathname.includes('/edit'), { timeout: 10000 });
        await page.waitForTimeout(500);

        await expect(page.locator('body')).toContainText(title);
        await expect(page.locator('h2:has-text("Mon accompagnement"), h1:has-text("Mon accompagnement")').first()).toBeVisible();
        await expect(page.locator('strong:has-text("coaching")').first()).toBeVisible();
        await expect(page.locator('li:has-text("Session 1")').first()).toBeVisible();
        await expect(page.locator('body')).toContainText('Je propose un');

        expect(page.url()).not.toContain('/create');
        expect(page.url()).not.toContain('/edit');

        const hasValidationError = await page.locator('.text-red-500, .text-red-600, [class*="error"]').count();
        expect(hasValidationError).toBe(0);
    });

    // ═══ EDIT — create → edit → modify → save → reopen ═════════

    test('edit: loads markdown, modify, save, reopen', async ({ page }) => {
        const originalMarkdown = '## Service existant\n\n**Details** importants\n\n- Point A\n- Point B';
        const updatedMarkdown = '## Service modifie\n\n**Changements** appliques\n\n- Nouveau A\n- Nouveau B';
        const serviceTitle = 'EditTest ' + Date.now();

        await loginAsMember(page);
        await gotoCreate(page);

        await page.locator('.ProseMirror').click();
        await page.locator('.ProseMirror').fill('');

        await page.click('[data-markdown-tool="h2"]');
        await page.locator('.ProseMirror').pressSequentially('Service existant');
        await page.locator('.ProseMirror').press('Enter');
        await page.locator('.ProseMirror').pressSequentially('Details');
        await page.keyboard.press('ControlOrMeta+a');
        await page.click('[data-markdown-tool="bold"]');
        await page.click('[data-markdown-tool="bold"]');
        await page.locator('.ProseMirror').pressSequentially(' importants');
        await page.locator('.ProseMirror').press('Enter');
        await page.locator('.ProseMirror').press('Enter');
        await page.click('[data-markdown-tool="bulletList"]');
        await page.locator('.ProseMirror').pressSequentially('Point A');
        await page.locator('.ProseMirror').press('Enter');
        await page.locator('.ProseMirror').pressSequentially('Point B');

        await page.fill('input[name="title"]', serviceTitle);
        await page.waitForSelector('select[name="category_id"] option:not([value=""])', { timeout: 10000, state: 'attached' });
        await page.selectOption('select[name="category_id"]', { index: 1 });
        await page.waitForSelector('select[name="delivery_mode"] option:not([value=""])', { timeout: 10000, state: 'attached' });
        await page.locator('label:has(input[name="delivery_mode"])').first().click();
        await page.fill('input[name="points_cost"]', '100');
        await page.locator('form[data-marketplace-validation] button[type="submit"]').click();

        await page.waitForURL(url => url.pathname.includes('/services/') && !url.pathname.includes('/create') && !url.pathname.includes('/edit'), { timeout: 10000 });
        await page.waitForTimeout(500);

        const showUrl = page.url();
        const editUrl = showUrl.replace(/\/+$/, '') + '/edit';
        await page.goto(editUrl);
        await waitForTiptapInit(page);

        const ta = page.locator('textarea[data-tiptap-target]');
        await expect(ta).toBeAttached({ timeout: 5000 });
        const mdValue = await ta.inputValue();
        expect(mdValue).toContain('## Service existant');
        expect(mdValue).toContain('**Details**');
        expect(mdValue).toContain('- Point A');
        expect(mdValue).toContain('- Point B');

        await expect(page.locator('.ProseMirror h2')).toBeVisible();
        await expect(page.locator('.ProseMirror strong')).toBeVisible();
        await expect(page.locator('.ProseMirror ul')).toBeVisible();

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await page.keyboard.press('ControlOrMeta+a');
        await editor.fill('');
        await editor.fill('');
        await page.click('[data-markdown-tool="h2"]');
        await editor.pressSequentially('Service modifie');
        await editor.press('Enter');
        await editor.pressSequentially('Changements');
        await page.keyboard.press('ControlOrMeta+a');
        await page.click('[data-markdown-tool="bold"]');
        await page.click('[data-markdown-tool="bold"]');
        await editor.pressSequentially(' appliques');
        await editor.press('Enter');
        await editor.press('Enter');
        await page.click('[data-markdown-tool="bulletList"]');
        await editor.pressSequentially('Nouveau A');
        await editor.press('Enter');
        await editor.pressSequentially('Nouveau B');

        await page.locator('form[data-marketplace-validation] button[type="submit"]').click();

        await page.waitForURL(url => url.pathname.includes('/services/') && !url.pathname.includes('/create') && !url.pathname.includes('/edit'), { timeout: 10000 });
        await page.waitForTimeout(500);

        const showUrl2 = page.url();
        const editUrl2 = showUrl2.replace(/\/+$/, '') + '/edit';
        await page.goto(editUrl2);
        await waitForTiptapInit(page);

        const ta2 = page.locator('textarea[data-tiptap-target]');
        await expect(ta2).toBeAttached({ timeout: 5000 });
        const mdValue2 = await ta2.inputValue();
        expect(mdValue2).toContain('## Service modifie');
        expect(mdValue2).toContain('**Changements**');
        expect(mdValue2).toContain('- Nouveau A');
        expect(mdValue2).toContain('- Nouveau B');
    });

    // ═══ LEGACY MULTILINE — create → edit → verify → modify → save → reopen ═══

    test('legacy plain text: create, edit, modify, save, reopen', async ({ page }) => {
        const legacyTitle = 'LegacyTest ' + Date.now();

        await loginAsMember(page);
        await gotoCreate(page);

        const editor = page.locator('.ProseMirror');
        await editor.click();
        await editor.fill('');
        await editor.pressSequentially('Premiere ligne de contenu pour le service propose');
        await editor.press('Enter');
        await editor.pressSequentially('Deuxieme ligne avec des informations complementaires');
        await editor.press('Enter');
        await editor.pressSequentially('Troisieme ligne avec encore plus de details');
        await editor.press('Enter');
        await editor.pressSequentially('Quatrieme ligne pour atteindre 100 caracteres');

        await page.fill('input[name="title"]', legacyTitle);
        await page.waitForTimeout(500);
        await page.selectOption('select[name="category_id"]', { index: 1 });
        await page.locator('label:has(input[name="delivery_mode"])').first().click();
        await page.fill('input[name="points_cost"]', '100');
        await page.locator('form[data-marketplace-validation] button[type="submit"]').click();

        await page.waitForURL(url => url.pathname.includes('/services/') && !url.pathname.includes('/create') && !url.pathname.includes('/edit'), { timeout: 10000 });
        await page.waitForTimeout(500);

        const showUrl = page.url();
        const editUrl = showUrl.replace(/\/+$/, '') + '/edit';
        await page.goto(editUrl);
        await waitForTiptapInit(page);

        const ta = page.locator('textarea[data-tiptap-target]');
        await expect(ta).toBeAttached({ timeout: 5000 });
        const value = await ta.inputValue();
        expect(value).toContain('Premiere ligne');
        expect(value).toContain('Deuxieme ligne');
        expect(value).toContain('Troisieme ligne');

        const paraCount = await page.locator('.ProseMirror p').count();
        expect(paraCount).toBeGreaterThanOrEqual(3);

        await editor.click();
        await page.keyboard.press('ControlOrMeta+a');
        await editor.fill('');
        await editor.pressSequentially('Nouvelle premiere ligne');
        await editor.press('Enter');
        await editor.pressSequentially('Nouvelle deuxieme ligne');
        await editor.press('Enter');
        await editor.pressSequentially('Nouvelle troisieme ligne');

        await page.locator('form[data-marketplace-validation] button[type="submit"]').click();
        await page.waitForURL(url => url.pathname.includes('/services/') && !url.pathname.includes('/create') && !url.pathname.includes('/edit'), { timeout: 10000 });
        await page.waitForTimeout(500);

        const showUrl2 = page.url();
        const editUrl2 = showUrl2.replace(/\/+$/, '') + '/edit';
        await page.goto(editUrl2);
        await waitForTiptapInit(page);

        const ta2 = page.locator('textarea[data-tiptap-target]');
        await expect(ta2).toBeAttached({ timeout: 5000 });
        const value2 = await ta2.inputValue();
        expect(value2).toContain('Nouvelle premiere ligne');
        expect(value2).toContain('Nouvelle deuxieme ligne');
        expect(value2).toContain('Nouvelle troisieme ligne');
    });

    // ═══ RESPONSIVE ══════════════════════════════════════════════

    test('editor renders on mobile (375px)', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await loginAsMember(page);
        await gotoCreate(page);

        await expect(page.locator('.ProseMirror')).toBeVisible();
        await expect(page.locator('[role="toolbar"]')).toBeVisible();
    });

    // ═══ DARK / LIGHT ═══════════════════════════════════════════

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

    // ═══ i18n FR ════════════════════════════════════════════════

    test('toolbar i18n: French locale values', async ({ page }) => {
        await loginAsMember(page);

        await page.request.post('/locale/fr');
        await page.goto('/services/create');
        await page.waitForTimeout(500);
        if (page.url().includes('/profile/edit')) {
            await ensureProfileComplete(page);
            await page.goto('/services/create');
        }
        await waitForTiptapInit(page);

        const container = page.locator('[data-tiptap-container]');

        await expect(container).toHaveAttribute('data-i18n-undo', 'Annuler');
        await expect(container).toHaveAttribute('data-i18n-redo', 'Rétablir');
        await expect(container).toHaveAttribute('data-i18n-bold', 'Gras');
        await expect(container).toHaveAttribute('data-i18n-link', 'Lien');
        await expect(container).toHaveAttribute('data-i18n-h2', 'Titre 2');
        await expect(container).toHaveAttribute('data-i18n-h3', 'Titre 3');
        await expect(container).toHaveAttribute('data-i18n-bullet-list', 'Liste à puces');
        await expect(container).toHaveAttribute('data-i18n-toolbar', "Barre d'outils de formatage");
        await expect(container).toHaveAttribute('data-i18n-url-prompt', 'URL');
    });

    // ═══ i18n EN ════════════════════════════════════════════════

    test('toolbar i18n: English locale values', async ({ page }) => {
        await loginAsMember(page);

        await page.request.post('/locale/en');
        await page.goto('/services/create');
        await page.waitForTimeout(500);
        if (page.url().includes('/profile/edit')) {
            await ensureProfileComplete(page);
            await page.goto('/services/create');
        }
        await waitForTiptapInit(page);

        const container = page.locator('[data-tiptap-container]');

        await expect(container).toHaveAttribute('data-i18n-undo', 'Undo');
        await expect(container).toHaveAttribute('data-i18n-redo', 'Redo');
        await expect(container).toHaveAttribute('data-i18n-bold', 'Bold');
        await expect(container).toHaveAttribute('data-i18n-link', 'Link');
        await expect(container).toHaveAttribute('data-i18n-h2', 'Heading 2');
        await expect(container).toHaveAttribute('data-i18n-h3', 'Heading 3');
        await expect(container).toHaveAttribute('data-i18n-bullet-list', 'Bullet list');
        await expect(container).toHaveAttribute('data-i18n-toolbar', 'Formatting toolbar');
        await expect(container).toHaveAttribute('data-i18n-url-prompt', 'URL');
    });

    // ═══ TOOLBAR BUTTONS ════════════════════════════════════════

    test('all toolbar buttons are present', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        await expect(page.locator('[data-markdown-tool]').first()).toBeVisible();

        const toolTypes = await page.locator('[data-markdown-tool]').evaluateAll(
            els => els.map(e => e.dataset.markdownTool)
        );
        expect(toolTypes).toContain('undo');
        expect(toolTypes).toContain('redo');
        expect(toolTypes).toContain('bold');
        expect(toolTypes).toContain('link');
        expect(toolTypes).toContain('h2');
        expect(toolTypes).toContain('h3');
        expect(toolTypes).toContain('bulletList');
    });

    // ═══ KEYBOARD ACCESS ════════════════════════════════════════

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

// ═══ FALLBACK (no JS) — blocking assertions, no early return ═════

test.describe('Tiptap Fallback (no JavaScript)', () => {
    test('textarea is visible without JS, Tiptap is absent', async ({ browser }) => {
        const memberContext = await browser.newContext();
        const memberPage = await memberContext.newPage();
        await loginAsMember(memberPage);

        await memberPage.goto('/profile/edit');
        await memberPage.waitForTimeout(500);
        if (memberPage.url().includes('/profile/edit')) {
            await memberPage.fill('input[name="first_name"]', 'NoJS');
            await memberPage.fill('input[name="name"]', 'NoJS User');
            await memberPage.fill('input[name="phone"]', '0600000000');
            await memberPage.fill('input[name="city"]', 'Paris');
            await memberPage.selectOption('select[name="country_code"]', 'FR');
            await memberPage.fill('textarea[name="bio"]', 'Test bio no-js.');
            await memberPage.click('button:has-text("Enregistrer"), button:has-text("Save")');
            await memberPage.waitForURL(url => url.pathname !== '/profile/edit', { timeout: 10000 });
            await memberPage.waitForTimeout(500);
        }

        const storageState = await memberContext.storageState();
        await memberContext.close();

        const noJsContext = await browser.newContext({
            javaScriptEnabled: false,
            storageState: storageState,
        });
        const page = await noJsContext.newPage();

        await page.goto('/services/create', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2000);

        const url = page.url();
        expect(url).not.toContain('/login');
        expect(url).not.toContain('/dashboard');
        expect(url).not.toContain('/profile/edit');
        expect(url).toContain('/create');

        const textarea = page.locator('textarea[name="description"][data-tiptap-target]');
        await expect(textarea).toBeAttached({ timeout: 3000 });
        await expect(textarea).toBeVisible({ timeout: 3000 });

        await textarea.fill('Service description');
        const value = await textarea.inputValue();
        expect(value).toBe('Service description');

        await expect(page.locator('.ProseMirror')).toHaveCount(0);
        await expect(page.locator('[role="toolbar"]')).toHaveCount(0);

        await noJsContext.close();
    });
});

// ─────────────────────────────────────────────────────────────────────
// TASK-1818 — la suggestion IA doit atterrir DANS TipTap
// ─────────────────────────────────────────────────────────────────────

/**
 * Le defaut : `applySuggestion()` ecrivait dans le `<textarea>` que le
 * composant d'edition CACHE depuis T1773. La suggestion partait au serveur
 * sans jamais s'afficher — l'auteur voyait son texte et postait autre chose.
 *
 * Ces tests mesurent les trois contenus SEPAREMENT : ce qui est a l'ecran
 * (ProseMirror), ce que porte le champ soumis (textarea), et ce que le
 * navigateur enverrait reellement (FormData construit depuis le formulaire).
 * Comparer seulement les deux premiers laisserait passer une divergence a
 * l'envoi.
 *
 * AUCUNE DEPENSE FOURNISSEUR : la route de formulation est doublee, et le vrai
 * Service Worker est bloque — sans quoi il intercepterait le `fetch` et le POST
 * partirait au serveur reel (piege mesure le 03/11, cf. task-1745-first-run).
 */
test.describe('TASK-1818 — suggestion IA appliquee dans Tiptap', () => {
    test.use({ serviceWorkers: 'block' });

    const SUGGESTION = {
        title: 'Besoin d un coup de main pour un demenagement',
        description: 'Je cherche **deux personnes** pour m aider a porter des cartons samedi matin.\n\nLe logement est au 3e sans ascenseur, et j ai prevu de quoi dejeuner.',
        category_id: null,
    };

    /** Le bloc IA n'est rendu que si l'IA est reellement utilisable ici. */
    let etatInitialIa = null;

    test.beforeAll(async () => {
        const { execFileSync } = await import('node:child_process');
        const lire = execFileSync('php', ['artisan', 'tinker', '--execute',
            `$s = App\\Models\\OrganizationAiSetting::whereHas('organization', fn($q) => $q->where('slug', 'main'))->first();
             echo $s ? json_encode(['id' => $s->id, 'is_enabled' => $s->is_enabled, 'api_key' => $s->api_key]) : 'null';`
        ], { encoding: 'utf8' }).trim();

        etatInitialIa = lire.includes('{') ? JSON.parse(lire.slice(lire.indexOf('{'))) : null;

        if (etatInitialIa) {
            execFileSync('php', ['artisan', 'tinker', '--execute',
                `$s = App\\Models\\OrganizationAiSetting::find('${etatInitialIa.id}');
                 $s->is_enabled = true; $s->api_key = 'sk-banc-e2e-1818'; $s->save();`
            ], { encoding: 'utf8' });
        }
    });

    test.afterAll(async () => {
        if (!etatInitialIa) return;
        const { execFileSync } = await import('node:child_process');
        const cle = etatInitialIa.api_key === null ? 'null' : `'${etatInitialIa.api_key}'`;
        execFileSync('php', ['artisan', 'tinker', '--execute',
            `$s = App\\Models\\OrganizationAiSetting::find('${etatInitialIa.id}');
             $s->is_enabled = ${etatInitialIa.is_enabled ? 'true' : 'false'}; $s->api_key = ${cle}; $s->save();`
        ], { encoding: 'utf8' });
    });

    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
        clearErrors();
    });

    /** Double la formulation IA. Aucun appel ne sort. */
    async function doublerFormulation(page, suggestion = SUGGESTION) {
        await page.route('**/requests/ai-formulate', (route) =>
            route.fulfill({ json: { suggestion } })
        );
    }

    /** Compte les POST vers la creation d'une demande — doit rester a zero. */
    function surveillerPublication(page) {
        const envois = [];
        page.on('request', (requete) => {
            if (requete.method() === 'POST' && /\/requests(\?|$)/.test(new URL(requete.url()).pathname + '')) {
                envois.push(requete.url());
            }
        });
        return envois;
    }

    async function allerSurDemande(page, { mobile = false } = {}) {
        if (mobile) await page.setViewportSize({ width: 390, height: 844 });

        await page.goto('/requests/create');
        await page.waitForTimeout(500);

        if (page.url().includes('/profile/edit')) {
            await ensureProfileComplete(page);
            await page.goto('/requests/create');
        }

        await waitForTiptapInit(page);
        clearErrors();
    }

    /** Les TROIS contenus, lus separement sur le DOM reel. */
    async function lireLesTroisContenus(page) {
        return await page.evaluate(() => {
            const form = document.querySelector('form[data-marketplace-validation]');
            const textarea = form.querySelector('textarea[name="description"]');
            const visible = document.querySelector('.ProseMirror');
            return {
                ecran: visible ? visible.innerText.trim() : null,
                champ: textarea ? textarea.value.trim() : null,
                // Ce que le navigateur enverrait reellement, construit depuis le
                // formulaire lui-meme — pas une valeur que le test re-derive.
                envoi: (new FormData(form).get('description') || '').toString().trim(),
            };
        });
    }

    async function declencherEtAppliquer(page) {
        await page.fill('input[name="title"]', 'Un coup de main');
        await page.waitForTimeout(200);

        await page.click(`button:has-text("M'aider à formuler")`);
        await expect(page.locator(`button:has-text("Appliquer la suggestion")`)).toBeVisible();

        await page.click(`button:has-text("Appliquer la suggestion")`);
        await page.waitForTimeout(400);
    }

    // ═══ 1 + 2 — la description s'affiche, et les trois contenus coincident ══

    for (const appareil of [
        { nom: 'desktop', mobile: false },
        { nom: 'mobile', mobile: true },
    ]) {
        test(`la suggestion appliquee apparait dans Tiptap et les trois contenus coincident (${appareil.nom})`, async ({ page }) => {
            await loginAsMember(page);
            await doublerFormulation(page);
            const publications = surveillerPublication(page);
            await allerSurDemande(page, appareil);

            await declencherEtAppliquer(page);

            const { ecran, champ, envoi } = await lireLesTroisContenus(page);

            // 1 — visible a l'ecran, immediatement.
            expect(ecran).toContain('deux personnes');
            expect(ecran).toContain('3e sans ascenseur');

            // 2 — l'ecran, le champ soumis et l'envoi portent le MEME texte.
            expect(champ).toContain('deux personnes');
            expect(envoi).toBe(champ);
            expect(envoi).toContain('3e sans ascenseur');

            // Le Markdown atteint bien le champ soumis, pas seulement le rendu.
            expect(champ).toContain('**deux personnes**');

            // 5 — rien n'a ete publie.
            expect(publications).toHaveLength(0);
        });
    }

    // ═══ 3 — une saisie manuelle posterieure reste synchronisee ═══════════

    test('une modification manuelle apres application reste synchronisee', async ({ page }) => {
        await loginAsMember(page);
        await doublerFormulation(page);
        await allerSurDemande(page);

        await declencherEtAppliquer(page);

        await page.click('.ProseMirror');
        await page.keyboard.press('End');
        await page.keyboard.type(' Merci d avance.');
        await page.waitForTimeout(400);

        const { ecran, champ, envoi } = await lireLesTroisContenus(page);

        expect(ecran).toContain('Merci d avance.');
        expect(champ).toContain('Merci d avance.');
        expect(envoi).toBe(champ);
        // Le texte d'origine n'a pas ete perdu en route.
        expect(champ).toContain('deux personnes');
    });

    // ═══ 4 — ignorer ne touche a rien ════════════════════════════════════

    test('ignorer la suggestion ne modifie aucun champ', async ({ page }) => {
        await loginAsMember(page);
        await doublerFormulation(page);
        const publications = surveillerPublication(page);
        await allerSurDemande(page);

        await page.fill('input[name="title"]', 'Mon titre a moi');
        await page.click('.ProseMirror');
        await page.keyboard.type('Ma description a moi.');
        await page.waitForTimeout(300);

        const avant = await lireLesTroisContenus(page);
        const titreAvant = await page.inputValue('input[name="title"]');

        await page.click(`button:has-text("M'aider à formuler")`);
        await expect(page.locator(`button:has-text("Annuler")`)).toBeVisible();
        await page.click(`button:has-text("Annuler")`);
        await page.waitForTimeout(300);

        const apres = await lireLesTroisContenus(page);

        expect(apres.ecran).toBe(avant.ecran);
        expect(apres.champ).toBe(avant.champ);
        expect(apres.envoi).toBe(avant.envoi);
        expect(await page.inputValue('input[name="title"]')).toBe(titreAvant);
        expect(apres.champ).not.toContain('deux personnes');
        expect(publications).toHaveLength(0);
    });

    // ═══ 6 — le formulaire d'offre d'aide n'a pas bouge ══════════════════

    test('le formulaire d offre d aide reste fonctionnel', async ({ page }) => {
        await loginAsMember(page);
        await page.route('**/services/ai-formulate', (route) =>
            route.fulfill({ json: { suggestion: { title: 'Je propose un coup de main', description_markdown: 'Je peux **aider** a porter des cartons.' } } })
        );
        await gotoCreate(page);

        await page.fill('input[name="title"]', 'Un coup de main');
        await page.waitForTimeout(200);
        await page.click(`button:has-text("M'aider à formuler")`);
        await expect(page.locator(`button:has-text("Appliquer la suggestion")`)).toBeVisible();
        await page.click(`button:has-text("Appliquer la suggestion")`);
        await page.waitForTimeout(400);

        const { ecran, champ, envoi } = await lireLesTroisContenus(page);

        expect(ecran).toContain('porter des cartons');
        expect(champ).toContain('**aider**');
        expect(envoi).toBe(champ);
    });
});
