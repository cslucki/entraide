import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA } from '../helpers/data.js';

test.describe('T146 Auth — Admin Login', () => {
  test('T146-001: Login as admin and verify authenticated', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    expect(page.url()).not.toContain('/login');
    expect(page.url()).not.toBe('about:blank');
  });

  test('T146-004: Logout admin', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    await page.goto('/logout');
    await page.waitForURL(url => url !== 'about:blank');
    expect(page.url()).not.toContain('/dashboard');
  });
});
