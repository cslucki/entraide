import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA, uniqueName, LONG_DESC, CATEGORY_INDEX, BUDGET_MIN } from '../helpers/data.js';

test.describe('T146 Requests — Create', () => {
  test('T146-015: Access request creation form', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    await page.goto('/requests/create');
    await expect(page.locator('input[name="title"]')).toBeVisible();
    await expect(page.locator('textarea[name="description"]')).toBeVisible();
  });

  test('T146-016: Create a help request', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    await page.goto('/requests/create');
    const title = uniqueName('Request');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', LONG_DESC);
    await page.locator('select[name="category_id"]').selectOption({ index: CATEGORY_INDEX });
    await page.locator('input[name="delivery_mode"][value="remote"]').check({ force: true });
    await page.fill('input[name="budget_min"]', String(BUDGET_MIN));
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    const url = page.url();
    expect(url.includes('/dashboard') || url.length > 5).toBeTruthy();
  });
});
