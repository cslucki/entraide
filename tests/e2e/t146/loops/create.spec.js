import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA, uniqueName } from '../helpers/data.js';

async function getSlug(page) {
  await page.waitForTimeout(500);
  const match = page.url().match(/\/(bni|cpme|60000-rebonds)/);
  return match ? match[1] : 'bni';
}

test.describe('T146 Loops — Create', () => {
  test('T146-033: Access loop creation form via community', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const slug = await getSlug(page) || 'bni';
    await page.goto(`/${slug}/loops/create`);
    await expect(page.locator('input[name="name"]')).toBeVisible({ timeout: 5000 });
  });

  test('T146-034: Create a loop', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const slug = await getSlug(page) || 'bni';
    await page.goto(`/${slug}/loops/create`);
    const name = uniqueName('Loop');
    await page.fill('input[name="name"]', name);
    await page.fill('textarea[name="description"]', 'Description de test pour la boucle T146');
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    expect(page.url()).not.toContain('/create');
  });
});
