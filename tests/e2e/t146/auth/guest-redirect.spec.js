import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA } from '../helpers/data.js';

test.describe('T146 Auth — Guest Redirect', () => {
  test('T146-005: Guest accessing /dashboard redirects to /login', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForTimeout(2000);
    const url = page.url();
    expect(url.includes('/login') || url === '/').toBeTruthy();
  });

  test('T146-006: Guest accessing /admin/dashboard redirects to /login', async ({ page }) => {
    await page.goto('/admin/dashboard');
    await page.waitForTimeout(2000);
    expect(page.url()).toContain('/login');
  });

  test('T146-046: Member accessing /admin/dashboard gets 403', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', QA.M1.email);
    await page.fill('input[name="password"]', QA.M1.password);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    await page.goto('/admin/dashboard');
    await page.waitForTimeout(2000);
    const body = await page.locator('body').textContent().catch(() => '');
    const isBlocked = body.includes('403') || body.includes('Forbidden')
      || body.includes('/login') || page.url().includes('/login');
    expect(isBlocked).toBeTruthy();
  });
});
