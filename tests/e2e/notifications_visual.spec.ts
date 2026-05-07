import { test, expect } from '@playwright/test';

test.describe('Notification Center Visual Verification', () => {
    test('Notification Dropdown and History Page Rendering', async ({ page }) => {
        // Login
        await page.goto('/login');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');

        // Navigate to a community dashboard
        await page.goto('/cpme/dashboard');

        // Take screenshot of the dashboard with the bell icon
        await page.screenshot({ path: '/home/jules/verification/screenshots/1_dashboard_bell.png' });

        // Open the notification dropdown
        await page.click('button[title="Notifications"]');
        await page.waitForSelector('h3:has-text("Notifications")');
        await page.screenshot({ path: '/home/jules/verification/screenshots/2_dropdown_open.png' });

        // Go to History Page
        await page.click('text=Voir toutes les notifications');
        await page.waitForURL('**/notifications');
        await page.screenshot({ path: '/home/jules/verification/screenshots/3_history_page.png' });

        // Check Dark Mode
        await page.evaluate(() => document.documentElement.classList.add('dark'));
        await page.screenshot({ path: '/home/jules/verification/screenshots/4_history_dark.png' });
    });
});
