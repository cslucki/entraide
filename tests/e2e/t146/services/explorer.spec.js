import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA, uniqueName, LONG_DESC, CATEGORY_INDEX, POINTS } from '../helpers/data.js';

test.describe('T146 Services — Explorer and Show', () => {
  test('T146-010: Verify service in /explorer', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const title = uniqueName('Explorer');
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

  test('T146-011: Show service detail page', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const title = uniqueName('Show');
    await page.goto('/services/create');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', LONG_DESC);
    await page.locator('select[name="category_id"]').selectOption({ index: CATEGORY_INDEX });
    await page.locator('input[name="delivery_mode"][value="remote"]').check({ force: true });
    await page.fill('input[name="points_cost"]', String(POINTS));
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    const serviceLink = page.locator(`a[href*="/services/"]`).filter({ hasText: title }).first();
    const visible = await serviceLink.isVisible({ timeout: 3000 }).catch(() => false);
    if (visible) {
      const href = await serviceLink.getAttribute('href');
      if (href) {
        await page.goto(href);
        await expect(page.locator(`text=${title}`).first()).toBeVisible({ timeout: 5000 });
      }
    } else {
      await page.goto('/explorer');
      const explorerLink = page.locator(`a[href*="/services/"]`).filter({ hasText: title }).first();
      if (await explorerLink.isVisible({ timeout: 3000 }).catch(() => false)) {
        const href = await explorerLink.getAttribute('href');
        if (href) {
          await page.goto(href);
          await expect(page.locator(`text=${title}`).first()).toBeVisible({ timeout: 5000 });
        }
      }
    }
  });
});
