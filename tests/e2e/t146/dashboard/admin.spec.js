import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA } from '../helpers/data.js';

test.describe('T146 Dashboard — Admin', () => {
  test('T146-038: Admin dashboard loads', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    await page.goto('/admin/dashboard');
    await expect(page.locator('body')).toBeVisible();
    await page.waitForTimeout(1000);
    expect(page.url()).toContain('/admin');
  });

  test('T146-039: Admin users page', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    await page.goto('/admin/users');
    await expect(page.locator('body')).toBeVisible();
  });

  test('T146-040: Admin services page', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    await page.goto('/admin/services');
    await expect(page.locator('body')).toBeVisible();
  });

  test('T146-041: Admin requests page', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    await page.goto('/admin/requests');
    await expect(page.locator('body')).toBeVisible();
  });

  test('T146-042: Admin transactions page', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    await page.goto('/admin/transactions');
    await expect(page.locator('body')).toBeVisible();
  });

  test('T146-043: Admin blog page', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    await page.goto('/admin/blog');
    await expect(page.locator('body')).toBeVisible();
  });

  test('T146-044: Admin loops page', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    await page.goto('/admin/loops');
    await expect(page.locator('body')).toBeVisible();
  });

  test('T146-045: Admin settings page', async ({ page }) => {
    await login(page, QA.ADMIN.email, QA.ADMIN.password);
    await page.goto('/admin/settings');
    await expect(page.locator('body')).toBeVisible();
  });
});
