import { test, expect } from '@playwright/test';

test.describe('BouclePro Final Visual Review', () => {

    test('1. Desktop Homepage', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('http://localhost:8000');
        await expect(page.locator('h1')).toContainText('Échangez vos compétences');
        await page.screenshot({ path: 'screenshots/1-desktop-home.png' });
    });

    test('2. Desktop with Menu Open', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('http://localhost:8000');
        // Since it's desktop, "menu open" might mean the profile dropdown if logged in,
        // or just the sticky nav state. Let's do sticky nav first.
        await page.mouse.wheel(0, 500);
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'screenshots/2-desktop-sticky-nav.png' });
    });

    test('3. Mobile Homepage', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('http://localhost:8000');
        await page.screenshot({ path: 'screenshots/3-mobile-home.png' });
    });

    test('4. Mobile with Menu Open', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('http://localhost:8000');
        // The path inline-flex is our hamburger icon
        await page.click('button:has(svg path.inline-flex)');
        await page.waitForTimeout(800); // Wait for transition
        await page.screenshot({ path: 'screenshots/4-mobile-menu-open.png' });
    });

    test('5. Dark Mode Homepage', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('http://localhost:8000');
        await page.evaluate(() => {
            localStorage.setItem('theme', 'dark');
            document.documentElement.classList.add('dark');
        });
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'screenshots/5-dark-mode.png' });
    });

    test('6. AI Conversational Input Interaction', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('http://localhost:8000');
        const textarea = page.locator('textarea');
        await textarea.click();
        await textarea.fill('Je veux proposer un cours de cuisine');
        await page.waitForTimeout(300);
        await page.screenshot({ path: 'screenshots/6-ai-interaction.png' });
    });

    test('7. Placeholder Change after Hint', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('http://localhost:8000');
        // Click a suggestion
        await page.click('button:has-text("Proposer un service")');
        await page.waitForTimeout(1000); // Wait for potential state change
        await page.screenshot({ path: 'screenshots/7-ai-hint-selected.png' });
    });

    test('8. Responsive Spacing Verification', async ({ page }) => {
        // Tablet view
        await page.setViewportSize({ width: 768, height: 1024 });
        await page.goto('http://localhost:8000');
        await page.screenshot({ path: 'screenshots/8-tablet-spacing.png' });
    });
});
