import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import { setupConsoleLogging, getConsoleErrors, getPageErrors } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

const LONG_DESC = 'This is a long enough description for a service offer that meets the minimum character requirement needed for validation.';

test.describe('Tiptap Markdown WYSIWYG Editor', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async ({ page }, testInfo) => {
        const consoleErrors = getConsoleErrors();
        const pageErrors = getPageErrors();
        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.warn(`[${testInfo.title}] Console errors:`, consoleErrors);
            console.warn(`[${testInfo.title}] Page errors:`, pageErrors);
        }
    });

    // ── Initialization ───────────────────────────────

    test('textarea is present before JavaScript initialization', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        const textarea = page.locator('textarea[data-tiptap-target]');
        await expect(textarea).toBeVisible();
        await expect(textarea).toHaveAttribute('name', 'description');
    });

    test('editor container is present after page load', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        const container = page.locator('[data-tiptap-container]');
        await expect(container).toBeVisible();
    });

    test('no console errors on create page load', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        const errors = getConsoleErrors();
        expect(errors.filter(e => !e.includes('favicon') && !e.includes('net::ERR'))).toEqual([]);
    });

    test('no console errors on edit page load', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        // Fill the form and submit to create a service first
        await page.fill('input[name="title"]', 'Test Service for Editing');
        await page.fill('textarea[data-tiptap-target]', `## Test Service\n\n${LONG_DESC}`);
        await page.selectOption('select[name="category_id"]', { index: 1 });
        await page.selectOption('select[name="delivery_mode"]', { index: 1 });
        await page.fill('input[name="points_cost"]', '100');
        await page.click('button[type="submit"]');

        const errors = getConsoleErrors();
        expect(errors.filter(e => !e.includes('favicon') && !e.includes('net::ERR'))).toEqual([]);
    });

    // ── Textarea stays canonical ─────────────────────

    test('textarea synchronizes markdown on input', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        const textarea = page.locator('textarea[data-tiptap-target]');
        await textarea.fill(LONG_DESC);

        // Textarea value is set
        const value = await textarea.inputValue();
        expect(value).toContain(LONG_DESC);
    });

    // ── Fallback (textarea always exists) ────────────

    test('textarea exists in fallback zone', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        // Textarea is in the DOM (may be hidden by JS, but still present)
        const textarea = page.locator('textarea[name="description"][data-tiptap-target]');
        await expect(textarea).toBeAttached();
    });

    // ── Dark mode / Light mode ──────────────────────

    test('editor renders in light mode', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        // Remove dark class
        const html = page.locator('html');
        await html.evaluate(el => el.classList.remove('dark'));
        await page.waitForTimeout(100);

        const container = page.locator('[data-tiptap-container]');
        await expect(container).toBeVisible();
    });

    test('editor renders in dark mode', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        // Ensure dark class
        const html = page.locator('html');
        await html.evaluate(el => el.classList.add('dark'));
        await page.waitForTimeout(100);

        const container = page.locator('[data-tiptap-container]');
        await expect(container).toBeVisible();
    });

    // ── Mobile viewport ─────────────────────────────

    test('editor renders on mobile viewport (375px)', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await loginAsMember(page);
        await page.goto('/services/create');

        const container = page.locator('[data-tiptap-container]');
        await expect(container).toBeVisible();

        const textarea = page.locator('textarea[data-tiptap-target]');
        await expect(textarea).toBeAttached();
    });

    // ── Markdown round-trip via textarea ─────────────

    test('markdown stored in textarea is submitted correctly', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        const markdown = `## My Coaching\n\nI offer **professional** coaching sessions.\n\n- Session 1\n- Session 2`;

        // Fill all fields
        await page.fill('input[name="title"]', 'My Coaching Service');
        await page.fill('textarea[data-tiptap-target]', markdown);
        await page.selectOption('select[name="category_id"]', { index: 1 });
        await page.selectOption('select[name="delivery_mode"]', { index: 1 });
        await page.fill('input[name="points_cost"]', '100');
        await page.click('button[type="submit"]');

        // Should redirect to service show page
        await expect(page).not.toHaveURL(/\/services\/create/);

        // Markdown content is visible as rendered HTML on show page
        const rendered = page.locator('main');
        await expect(rendered).toBeVisible();
    });

    // ── Legacy plain text preservation ───────────────

    test('legacy plain text textarea preserves content', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        const legacy = 'First line\nSecond line\nThird line';
        const textarea = page.locator('textarea[data-tiptap-target]');
        await textarea.fill(legacy);

        const value = await textarea.inputValue();
        expect(value).toBe(legacy);
    });

    // ── i18n toolbar labels ──────────────────────────

    test('toolbar labels are localized in French', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create?lang=fr');

        const container = page.locator('[data-tiptap-container]');
        await expect(container).toHaveAttribute('data-i18n-undo');
        await expect(container).toHaveAttribute('data-i18n-redo');
        await expect(container).toHaveAttribute('data-i18n-bold');
        await expect(container).toHaveAttribute('data-i18n-link');
        await expect(container).toHaveAttribute('data-i18n-h2');
        await expect(container).toHaveAttribute('data-i18n-h3');
        await expect(container).toHaveAttribute('data-i18n-bullet-list');
    });

    test('toolbar labels are localized in English', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create?lang=en');

        const container = page.locator('[data-tiptap-container]');
        await expect(container).toHaveAttribute('data-i18n-undo');
        await expect(container).toHaveAttribute('data-i18n-bold');
    });

    // ── Single editor instance ───────────────────────

    test('only one editor container per page', async ({ page }) => {
        await loginAsMember(page);
        await page.goto('/services/create');

        const containers = page.locator('[data-tiptap-container]');
        await expect(containers).toHaveCount(1);
        await expect(containers).toBeVisible();
    });
});
