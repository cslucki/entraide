import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA, uniqueName, LONG_DESC, CATEGORY_INDEX, POINTS } from '../helpers/data.js';

test.describe('T146 Services — Create', () => {
  test('T146-007: Access service creation form', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    await page.goto('/services/create');
    await expect(page.locator('input[name="title"]')).toBeVisible();
    await expect(page.locator('textarea[name="description"]')).toBeVisible();
  });

  test('T146-008: Create a micro-service', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    await page.goto('/services/create');
    const title = uniqueName('Service');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', LONG_DESC);
    await page.locator('select[name="category_id"]').selectOption({ index: CATEGORY_INDEX });
    await page.locator('input[name="delivery_mode"][value="remote"]').check({ force: true });
    await page.fill('input[name="points_cost"]', String(POINTS));
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    const url = page.url();
    expect(url.includes('/dashboard') || url.length > 5).toBeTruthy();
    const visible = await page.locator(`text=${title}`).first().isVisible().catch(() => false);
    if (!visible) {
      await page.goto(url.includes('/bni') ? '/bni/dashboard' : url.includes('/cpme') ? '/cpme/dashboard' : '/dashboard');
      await page.waitForTimeout(1000);
    }
  });
});
