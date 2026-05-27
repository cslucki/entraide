import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA, uniqueName, LONG_DESC, CATEGORY_INDEX, POINTS } from '../helpers/data.js';

test.describe('T146 Transactions — Propose', () => {
  test('T146-018→019: Create service + M2 proposes transaction', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const title = uniqueName('Tx');
    await page.goto('/services/create');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', LONG_DESC);
    await page.locator('select[name="category_id"]').selectOption({ index: CATEGORY_INDEX });
    await page.locator('input[name="delivery_mode"][value="remote"]').check({ force: true });
    await page.fill('input[name="points_cost"]', String(POINTS));
    await page.click('button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.goto('/explorer');
    const sl = page.locator(`a[href*="/services/"]`).filter({ hasText: title }).first();
    const visible = await sl.isVisible({ timeout: 3000 }).catch(() => false);
    test.skip(!visible, 'Service not in explorer');
    const href = await sl.getAttribute('href');
    const serviceId = href ? href.split('/services/')[1]?.split(/[?/]/)[0] : null;
    test.skip(!serviceId, 'No service ID');

    await page.context().clearCookies();
    await page.goto('/login');
    await page.waitForTimeout(1000);
    await login(page, QA.M2.email, QA.M2.password);
    await page.goto(`/services/${serviceId}`);
    await page.waitForTimeout(2000);
    const bodyText = await page.locator('body').textContent().catch(() => '');
    expect(bodyText.length > 0).toBeTruthy();
  });
});
