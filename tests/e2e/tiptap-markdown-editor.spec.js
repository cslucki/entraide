import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import { setupConsoleLogging, getConsoleErrors, getPageErrors } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

const LONG_DESC = 'This is a long enough description for a service offer that meets the minimum character requirement needed for validation.';

async function waitForTiptapInit(page) {
    await page.waitForSelector('.ProseMirror-wrapper .ProseMirror', { timeout: 10000 });
    await page.waitForSelector('[role="toolbar"]', { timeout: 5000 });
    await page.waitForSelector('textarea[data-tiptap-initialized="true"]', { timeout: 5000 });
}

async function gotoCreate(page) {
    await page.goto('/services/create');
    await waitForTiptapInit(page);
}

async function fillServiceForm(page, { title, description, categoryIndex = 1, modeIndex = 1, cost = '100' }) {
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[data-tiptap-target]', description);
    if (await page.locator('select[name="category_id"]').isVisible()) {
        await page.selectOption('select[name="category_id"]', { index: categoryIndex });
    }
    if (await page.locator('select[name="delivery_mode"]').isVisible()) {
        await page.selectOption('select[name="delivery_mode"]', { index: modeIndex });
    }
    await page.fill('input[name="points_cost"]', cost);
}

test.describe('Tiptap Markdown WYSIWYG Runtime', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const consoleErrors = getConsoleErrors().filter(e =>
            !e.includes('favicon') && !e.includes('net::ERR') && !e.includes('Sentry')
        );
        const pageErrors = getPageErrors();
        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.warn(`[${testInfo.title}] Console errors:`, consoleErrors);
        }
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
        await expect(page.locator('textarea[data-tiptap-target]')).not.toBeVisible({ timeout: 2000 }).catch(() => {});
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

        const errors = getConsoleErrors().filter(e =>
            !e.includes('favicon') && !e.includes('net::ERR') && !e.includes('Sentry')
        );
        expect(errors).toEqual([]);
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

        const textarea = page.locator('textarea[data-tiptap-target]');
        const md = await textarea.inputValue();
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
        const initialMd = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(initialMd).toContain('Version initiale');

        await page.click('[data-markdown-tool="bold"]');

        const textarea = page.locator('textarea[data-tiptap-target]');
        let md = await textarea.inputValue();
        expect(md).toContain('**Version initiale**');

        await page.click('[data-markdown-tool="undo"]');
        md = await textarea.inputValue();
        expect(md).not.toContain('**');

        await page.click('[data-markdown-tool="redo"]');
        md = await textarea.inputValue();
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

        await page.evaluate(() => {
            window.prompt = () => 'javascript:alert(1)';
        });
        await page.click('[data-markdown-tool="link"]');

        await expect(editor.locator('a')).toHaveCount(0);

        const md = await page.locator('textarea[data-tiptap-target]').inputValue();
        expect(md).not.toContain('javascript:');
    });

    // ═══ IA ═══════════════════════════════════════════════

    test('AI suggestion applies to Tiptap editor', async ({ page }) => {
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
                detail: { name: 'description', markdown: md }
            }));
        }, aiMarkdown);

        await page.waitForTimeout(300);

        const newMd = await textarea.inputValue();
        expect(newMd).toContain('## Coaching pro');
        expect(newMd).toContain('**Sessions**');
        expect(newMd).toContain('- Conseil');
        expect(newMd).toContain('- Suivi');

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

    // ═══ LEGACY MULTILINE ═════════════════════════════════

    test('legacy plain text load preserves content visually', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const legacy = 'Premiere ligne\nDeuxieme ligne\nTroisieme ligne';
        const textarea = page.locator('textarea[data-tiptap-target]');
        await textarea.fill(legacy);

        expect(await textarea.inputValue()).toBe(legacy);

        await page.evaluate((md) => {
            document.dispatchEvent(new CustomEvent('bp:markdown-editor:set-content', {
                detail: { name: 'description', markdown: md }
            }));
        }, legacy);

        await page.waitForTimeout(500);

        const editor = page.locator('.ProseMirror');
        const paraCount = await editor.locator('p').count();
        expect(paraCount).toBeGreaterThanOrEqual(1);
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
        await page.locator('html').evaluate(el => el.classList.remove('dark'));
        await waitForTiptapInit(page);

        await expect(page.locator('.ProseMirror')).toBeVisible();
        await expect(page.locator('[data-tiptap-target]').getAttribute('data-tiptap-initialized')).resolves.toBe('true');
    });

    // ═══ i18n ════════════════════════════════════════════

    test('toolbar data-i18n attributes are present', async ({ page }) => {
        await loginAsMember(page);
        await gotoCreate(page);

        const container = page.locator('[data-tiptap-container]');
        await expect(container).toHaveAttribute('data-i18n-undo');
        await expect(container).toHaveAttribute('data-i18n-bold');
        await expect(container).toHaveAttribute('data-i18n-link');
        await expect(container).toHaveAttribute('data-i18n-h2');
        await expect(container).toHaveAttribute('data-i18n-h3');
        await expect(container).toHaveAttribute('data-i18n-bullet-list');
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
});

// ═══ FALLBACK (no JS) ═════════════════════════════════════

test.describe('Tiptap Fallback (no JavaScript)', () => {
    test('textarea is visible and functional without JS', async ({ browser }) => {
        const context = await browser.newContext({ javaScriptEnabled: false });
        const page = await context.newPage();

        await page.goto('http://test.laravel/services/create');

        const textarea = page.locator('textarea[name="description"][data-tiptap-target]');
        await expect(textarea).toBeVisible();
        await expect(textarea).toHaveAttribute('name', 'description');

        await expect(page.locator('.ProseMirror')).toHaveCount(0);
        await expect(page.locator('[role="toolbar"]')).toHaveCount(0);

        await context.close();
    });
});
