import { test, expect } from '@playwright/test';

test.use({ baseURL: 'http://localhost:8000' });

test('referral flow: registration to transaction bonus', async ({ page }) => {
    // 1. User A (Referrer) logs in and gets their code
    await page.goto('/login');
    await page.fill('input[name="email"]', 'test@example.com');
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');

    await page.goto('/profile/referrals');
    // Code is in a <code> tag
    const referralCode = await page.locator('code').first().innerText();
    console.log('Referral Code:', referralCode);

    // Manual logout
    await page.context().clearCookies();

    // 2. User B (Referee) registers with User A's code
    await page.goto('/register');
    const refereeEmail = `referee_${Math.floor(Math.random() * 10000)}@example.com`;
    await page.fill('input[name="name"]', 'Filleul Playwright');
    await page.fill('input[name="email"]', refereeEmail);
    await page.fill('input[name="password"]', 'password');
    await page.fill('input[name="password_confirmation"]', 'password');
    await page.fill('input[name="referral_code"]', referralCode);
    await page.click('button[type="submit"]');

    // Verify User B has welcome points + referral bonus (100 + 50 = 150)
    await expect(page.locator('nav')).toContainText('150 pts');

    // 3. Admin Check
    await page.context().clearCookies();
    await page.goto('/login');
    await page.fill('input[name="email"]', 'test@example.com'); // Admin user
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');

    await page.goto('/admin/referrals');
    // Using a more specific locator to avoid strict mode violation (h1 in admin has 2 matches)
    await expect(page.locator('h1').nth(1)).toContainText('Supervision');
    await expect(page.locator('table')).toContainText('Filleul Playwright');

    // Screenshot for verification
    await page.screenshot({ path: 'referral-admin-dashboard.png', fullPage: true });

    // Also screenshot the referee's referral tracking page
    await page.context().clearCookies();
    await page.goto('/login');
    await page.fill('input[name="email"]', 'test@example.com'); // Back to parrain
    await page.fill('input[name="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.goto('/profile/referrals');
    await page.screenshot({ path: 'referral-user-tracking.png', fullPage: true });
});
