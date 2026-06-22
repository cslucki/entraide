import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA, uniqueName, LONG_DESC, CATEGORY_INDEX, POINTS, BUDGET_MIN } from '../helpers/data.js';

async function getSlug(page) {
  await page.waitForTimeout(500);
  const match = page.url().match(/\/(bni|cpme|60000-rebonds)/);
  return match ? match[1] : 'bni';
}

test.describe('T146 Dashboard — Member', () => {
  test('T146-047: Member loads any page after login', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    expect(page.url()).not.toContain('/login');
    expect(page.url()).not.toBe('about:blank');
  });

  test('T146-048: Service created by M1 visible in explorer', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const title = uniqueName('DashSvc');
    await page.goto('/services/create');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', LONG_DESC);
    await page.locator('select[name="category_id"]').selectOption({ index: CATEGORY_INDEX });
    await page.locator('input[name="delivery_mode"][value="remote"]').check({ force: true });
    await page.fill('input[name="points_cost"]', String(POINTS));
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    await page.goto('/explorer');
    await expect(page.locator(`text=${title}`).first()).toBeVisible({ timeout: 5000 });
  });

  test('T146-049: Request created by M1', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const title = uniqueName('DashReq');
    await page.goto('/requests/create');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', LONG_DESC);
    await page.locator('select[name="category_id"]').selectOption({ index: CATEGORY_INDEX });
    await page.locator('input[name="delivery_mode"][value="remote"]').check({ force: true });
    await page.fill('input[name="budget_min"]', String(BUDGET_MIN));
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    expect(page.url()).not.toContain('/requests/create');
  });
});
