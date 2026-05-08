import { test, expect } from '@playwright/test';

test.describe('Homepage Visual Refinement', () => {
    test('1. Desktop Screenshot', async ({ page }) => {
        await page.goto('http://localhost:8000');
        await page.screenshot({ path: 'screenshots/1-desktop-home.png', fullPage: false });
    });

    test('2. Desktop with Profile Dropdown', async ({ page }) => {
        await page.goto('http://localhost:8000');
        // We are guest, so no profile dropdown. Let's check guest login button.
        await page.screenshot({ path: 'screenshots/2-desktop-guest.png' });
    });

    test('3. Mobile Screenshot', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('http://localhost:8000');
        await page.screenshot({ path: 'screenshots/3-mobile-home.png' });
    });

    test('4. Mobile with Menu Open', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('http://localhost:8000');
        await page.click('button:has(svg path.inline-flex)');
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'screenshots/4-mobile-menu-open.png' });
    });

    test('5. Dark Mode Screenshot', async ({ page }) => {
        await page.goto('http://localhost:8000');
        await page.evaluate(() => document.documentElement.classList.add('dark'));
        await page.screenshot({ path: 'screenshots/5-dark-mode.png' });
    });

    test('6. AI Block Interaction', async ({ page }) => {
        await page.goto('http://localhost:8000');
        await page.fill('textarea', 'Je cherche un expert Laravel');
        await page.screenshot({ path: 'screenshots/6-ai-typing.png' });
    });

    test('7. Placeholder Change after Hint', async ({ page }) => {
        await page.goto('http://localhost:8000');
        await page.click('button:has-text("Proposer un service")');
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'screenshots/7-ai-hint-selection.png' });
    });
});
