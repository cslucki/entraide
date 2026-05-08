import { test, expect } from '@playwright/test';

test.describe('BouclePro Visual Validation Logged In', () => {
    test('Logged In States', async ({ page }) => {
        await page.goto('http://localhost:8000/login');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/dashboard');

        // 1. Desktop Profile Dropdown
        await page.goto('http://localhost:8000');
        await page.click('button:has-text("Utilisateur Test")');
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'screenshots/02-desktop-profile-dropdown.png' });

        // 2. Notification Center
        // Try to find community slug from URL or just go to /notifications if it works
        await page.goto('http://localhost:8000/cpme/notifications'); // Based on UserSeeder community
        await page.screenshot({ path: 'screenshots/08-notification-center.png' });
    });
});
