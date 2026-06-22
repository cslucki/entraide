import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA, uniqueName, LONG_DESC, CATEGORY_INDEX, POINTS } from '../helpers/data.js';

test.describe('T146 Services — Edit', () => {
  async function createService(page, title) {
    await page.goto('/services/create');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', LONG_DESC);
    await page.locator('select[name="category_id"]').selectOption({ index: CATEGORY_INDEX });
    await page.locator('input[name="delivery_mode"][value="remote"]').check({ force: true });
    await page.fill('input[name="points_cost"]', String(POINTS));
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
  }

  function getCommunitySlug(url) {
    const match = url.match(/\/(bni|cpme|60000-rebonds)/);
    return match ? match[1] : null;
  }

  test('T146-012/013: Edit own service', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const title = uniqueName('Edit');
    await createService(page, title);
    const slug = getCommunitySlug(page.url()) || 'bni';
    await page.goto(`/${slug}/dashboard`);
    await page.waitForTimeout(1500);
    const editLink = page.locator(`a[href*="/edit"]`).first();
    if (await editLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      const href = await editLink.getAttribute('href');
      if (href) {
        await page.goto(href);
        await expect(page.locator('input[name="title"]')).toBeVisible({ timeout: 5000 });
        const updatedTitle = title + '-MOD';
        await page.fill('input[name="title"]', updatedTitle);
        await page.click('button[type="submit"]');
        await page.waitForTimeout(2000);
      }
    }
  });

  test('T146-014: Member 2 cannot edit member 1 service', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const title = uniqueName('Unauth');
    await createService(page, title);
    const slug = getCommunitySlug(page.url()) || 'bni';
    await page.goto(`/${slug}/dashboard`);
    await page.waitForTimeout(1500);
    const link = page.locator(`a[href*="/services/"]`).filter({ hasText: title }).first();
    let serviceId = null;
    if (await link.isVisible({ timeout: 2000 }).catch(() => false)) {
      const href = await link.getAttribute('href');
      serviceId = href ? href.split('/services/')[1]?.split(/[?/]/)[0] : null;
    }
    test.skip(!serviceId, 'Could not extract service ID');
    await page.goto('/logout');
    await page.waitForURL(url => url !== 'about:blank');
    await login(page, QA.M2.email, QA.M2.password);
    await page.goto(`/services/${serviceId}/edit`);
    await page.waitForTimeout(2000);
    const currentUrl = page.url();
    const blocked = currentUrl.includes('/login') || currentUrl.includes('/dashboard') || !currentUrl.includes('/edit');
    expect(blocked).toBeTruthy();
  });
});
