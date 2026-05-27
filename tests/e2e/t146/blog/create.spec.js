import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA, uniqueName, LONG_CONTENT } from '../helpers/data.js';

test.describe('T146 Blog — Create', () => {
  test('T146-027: Access blog creation form', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    await page.goto('/blog/rediger/nouveau');
    await expect(page.locator('input[name="title"]')).toBeVisible();
    await expect(page.locator('textarea[name="content"]')).toBeVisible();
  });

  test('T146-028: Create a published blog article', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    await page.goto('/blog/rediger/nouveau');
    const title = uniqueName('Article');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="content"]', LONG_CONTENT);
    await page.locator('input[name="status"][value="published"]').check({ force: true });
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    const url = page.url();
    expect(url.includes('/blog/') && !url.includes('/rediger/')).toBeTruthy();
  });
});
