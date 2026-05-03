import { test, expect } from '@playwright/test';
import path from 'path';
import fs from 'fs';

const SCREENSHOT_DIR = 'test-results';

function screenshot(page, name) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    return page.screenshot({ path: path.join(SCREENSHOT_DIR, `${name}.png`) });
}

test.describe('Smoke', () => {
    test('login and dashboard', async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');

        await page.waitForURL('**/dashboard');
        await expect(page).toHaveURL(/\/dashboard/);

        // Titre de page et éléments clés
        await expect(page.locator('h1, h2').first()).toBeVisible();
        await expect(page.locator('nav')).toBeVisible();

        await screenshot(page, 'dashboard');
    });

    test('admin dashboard', async ({ page }) => {
        // Connexion
        await page.goto('/login');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/dashboard');

        // Admin dashboard
        await page.goto('/admin/dashboard');
        await expect(page).toHaveURL(/\/admin\/dashboard/);
        await expect(page.locator('main, [class*="content"], h1, h2').first()).toBeVisible();

        await screenshot(page, 'admin-dashboard');
    });
});
