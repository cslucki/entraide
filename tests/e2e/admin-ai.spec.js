import { test, expect } from '@playwright/test';

test.describe('Admin AI Orchestration', () => {
    test.beforeEach(async ({ page }) => {
        // Login as admin
        await page.goto('/login');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await expect(page).toHaveURL(/\/dashboard|cpme/);
    });

    test('can edit prompts and verify persistence', async ({ page }) => {
        await page.goto('/admin/ai');

        const newPrompt = 'You are a testing assistant ' + Math.random();
        await page.fill('textarea[name="ai_master_prompt"]', newPrompt);

        await page.click('button:has-text("Save AI Configuration")');

        await expect(page.locator('.bg-green-600')).toBeVisible();

        await page.reload();
        await expect(page.locator('textarea[name="ai_master_prompt"]')).toHaveValue(newPrompt);
    });

    test('can test AI intent locally', async ({ page }) => {
        await page.goto('/admin/ai');

        await page.fill('textarea[placeholder*="Type a test prompt"]', 'I want to help with Excel');
        await page.click('button:has-text("Test AI")');

        await expect(page.locator('pre')).toContainText('"intent": "service_offer"');
        await expect(page.locator('pre')).toContainText('"provider": "fake"');
    });

    test('can switch provider', async ({ page }) => {
        await page.goto('/admin/ai');

        await page.selectOption('select[name="ai_provider"]', 'openai');
        await page.click('button:has-text("Save AI Configuration")');

        await expect(page.locator('select[name="ai_provider"]')).toHaveValue('openai');
        await expect(page.locator('text=Warning: Currently using Fake Provider')).not.toBeVisible();
    });
});
