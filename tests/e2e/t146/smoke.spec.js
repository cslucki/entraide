import { test, expect } from '@playwright/test';
import '../../setup.js';

test.describe('T146 Smoke — Public Pages', () => {
  test('T146-053: Homepage loads', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('body')).toBeVisible();
  });

  test('T146-054: Explorer page loads', async ({ page }) => {
    await page.goto('/explorer');
    await expect(page.locator('body')).toBeVisible();
    await page.waitForTimeout(1000);
    expect(page.url()).toContain('/explorer');
  });

  test('T146-055: Members page loads', async ({ page }) => {
    await page.goto('/membres');
    await expect(page.locator('body')).toBeVisible();
  });

  test('T146-056: Register page loads', async ({ page }) => {
    await page.goto('/register');
    await expect(page.locator('input[name="name"]')).toBeVisible();
  });

  test('T146-057: Forgot password page loads', async ({ page }) => {
    await page.goto('/forgot-password');
    await expect(page.locator('input[type="email"]')).toBeVisible();
  });

  test('T146-060: No console errors on public pages', async ({ page }) => {
    const errors = [];
    page.on('console', msg => { if (msg.type() === 'error') errors.push(msg.text()); });
    await page.goto('/');
    await page.goto('/explorer');
    await page.goto('/membres');
    await page.goto('/blog');
    expect(errors.length).toBe(0);
  });
});
