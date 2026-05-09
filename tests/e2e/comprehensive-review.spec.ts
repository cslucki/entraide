import { test, expect } from '@playwright/test';

test.describe('BouclePro Comprehensive Visual Review', () => {

    test('Full Visual Suite', async ({ page }) => {
        // 1. Desktop Home
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('http://localhost:8000');
        await page.screenshot({ path: 'docs/visual-review/01-desktop-home.png' });

        // 2. Sticky Nav
        await page.mouse.wheel(0, 500);
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'docs/visual-review/02-desktop-sticky-nav.png' });

        // 3. Mobile Home
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('http://localhost:8000');
        await page.screenshot({ path: 'docs/visual-review/03-mobile-home.png' });

        // 4. Mobile Menu
        await page.click('button:has(svg path.inline-flex)');
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'docs/visual-review/04-mobile-menu-open.png' });

        // 5. Dark Mode
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('http://localhost:8000');
        await page.evaluate(() => {
            localStorage.setItem('theme', 'dark');
            document.documentElement.classList.add('dark');
        });
        await page.waitForTimeout(300);
        await page.screenshot({ path: 'docs/visual-review/05-dark-mode.png' });

        // 6. AI Interaction
        await page.locator('textarea').fill('Je veux proposer un service de design');
        await page.screenshot({ path: 'docs/visual-review/06-ai-interaction.png' });

        // 7. Hint selected
        await page.goto('http://localhost:8000'); // reset
        await page.click('button:has-text("Proposer un service")');
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'docs/visual-review/07-ai-hint-selected.png' });

        // 8. Tablet
        await page.setViewportSize({ width: 768, height: 1024 });
        await page.goto('http://localhost:8000');
        await page.screenshot({ path: 'docs/visual-review/08-tablet-spacing.png' });

        // 9. Profile Dropdown (Logged in)
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto('http://localhost:8000/login');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/dashboard');
        await page.goto('http://localhost:8000');
        await page.click('button:has-text("Utilisateur Test")');
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'docs/visual-review/09-desktop-profile-dropdown.png' });

        // 10. Notification Center
        await page.goto('http://localhost:8000/cpme/notifications');
        await page.screenshot({ path: 'docs/visual-review/10-notification-center.png' });
    });
});
