import { test, expect } from '@playwright/test';

test.describe('BouclePro Visual Validation', () => {

    test.beforeEach(async ({ page }) => {
        // Setup: clear cookies/storage if needed
    });

    test('1. Desktop Home & Navigation', async ({ page }) => {
        await page.goto('http://localhost:8000');
        await expect(page.locator('h1')).toContainText('Échangez vos compétences');
        await page.screenshot({ path: 'screenshots/1-desktop-home.png', fullPage: true });

        // Scroll a bit to show sticky header change
        await page.mouse.wheel(0, 500);
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'screenshots/1-desktop-home-scrolled.png' });
    });

    test('2. Desktop Login & Profile Dropdown', async ({ page }) => {
        await page.goto('http://localhost:8000/login');
        await page.fill('input[name="email"]', 'test@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('http://localhost:8000/dashboard');

        await page.goto('http://localhost:8000');
        await page.click('button:has-text("Test User")'); // Assuming the seeded user name is Test User or contains it
        await page.waitForTimeout(300);
        await page.screenshot({ path: 'screenshots/2-desktop-profile-dropdown.png' });

        // Notification dropdown
        await page.click('a[href*="messages"]'); // Just to navigate near it or if there is a bell
        // Assuming there is a bell or similar for notifications as per common UI
        // If not explicitly there, I'll just skip or check messages
        await page.screenshot({ path: 'screenshots/8-notification-center.png' });
    });

    test('3. Mobile Home & Drawer', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('http://localhost:8000');
        await page.screenshot({ path: 'screenshots/3-mobile-home.png' });

        // Open Drawer
        await page.click('button:has(svg path.inline-flex)');
        await page.waitForTimeout(500);
        await page.screenshot({ path: 'screenshots/4-mobile-menu-open.png' });
    });

    test('4. Dark Mode', async ({ page }) => {
        await page.goto('http://localhost:8000');
        await page.evaluate(() => {
            localStorage.setItem('theme', 'dark');
            document.documentElement.classList.add('dark');
        });
        await page.waitForTimeout(300);
        await page.screenshot({ path: 'screenshots/5-dark-mode-home.png', fullPage: true });
    });

    test('5. AI Interaction & Placeholder', async ({ page }) => {
        await page.goto('http://localhost:8000');

        // Typing interaction
        await page.fill('textarea', 'Je veux apprendre le PHP');
        await page.screenshot({ path: 'screenshots/6-ai-typing.png' });

        // Hint selection and placeholder change
        await page.fill('textarea', ''); // Clear
        const initialPlaceholder = await page.getAttribute('textarea', 'placeholder');
        await page.click('button:has-text("Proposer un service")');
        await page.waitForTimeout(300);
        await page.screenshot({ path: 'screenshots/7-ai-hint-selection.png' });
    });

    test('6. Responsive Spacing (Tablet)', async ({ page }) => {
        await page.setViewportSize({ width: 768, height: 1024 });
        await page.goto('http://localhost:8000');
        await page.screenshot({ path: 'screenshots/9-tablet-spacing.png' });
    });
});
