import { test, expect } from '@playwright/test';
import '../../../setup.js';
import { login } from '../../../../ai/playwright/helpers/auth.js';
import { QA, uniqueName, LONG_CONTENT } from '../helpers/data.js';

test.describe('T146 Blog — Public', () => {
  test('T146-029: Published article visible on /blog', async ({ page }) => {
    await login(page, QA.M1.email, QA.M1.password);
    const title = uniqueName('BlogPub');
    await page.goto('/blog/rediger/nouveau');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="content"]', LONG_CONTENT);
    await page.locator('input[name="status"][value="published"]').check({ force: true });
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    await page.goto('/logout');
    await page.waitForURL(url => url !== 'about:blank');
    await page.goto('/blog');
    await expect(page.locator(`text=${title}`).first()).toBeVisible({ timeout: 5000 });
  });
});
