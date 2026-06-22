import { test, expect } from '@playwright/test';
import '../../setup.js';
import { login } from '../../../ai/playwright/helpers/auth.js';

const M1 = { email: process.env.TEST_MEMBER1_LOGIN, password: process.env.TEST_MEMBER1_PASSWORD };
const M2 = { email: process.env.TEST_MEMBER2_LOGIN, password: process.env.TEST_MEMBER2_PASSWORD };

const uid = () => 'BUG001-' + Date.now();
const LONG_DESC = 'A'.repeat(150);

test.describe('BUG-001: cross-org Livewire 404 on /messages/{txId}', () => {
  test('BUG-001-fix: M1 (BNI) accesses /messages/{txId} without 404', async ({ page }) => {
    const title = uid();
    let serviceId = null;
    let txId = null;

    await test.step('M1 creates service in Default Org', async () => {
      await login(page, M1.email, M1.password);
      await page.goto('/services/create');
      await page.fill('input[name="title"]', title);
      await page.fill('textarea[name="description"]', LONG_DESC);
      await page.locator('select[name="category_id"]').selectOption({ index: 1 });
      await page.locator('input[name="delivery_mode"][value="remote"]').check({ force: true });
      await page.fill('input[name="points_cost"]', '50');
      await page.click('button[type="submit"]');
      await page.waitForURL(/\/dashboard/, { timeout: 10000 });
      const body = await page.locator('body').textContent();
      expect(body).toContain('Service publié avec succès.');
    });

    await test.step('M2 proposes transaction at root domain', async () => {
      await page.context().clearCookies();
      await page.goto('/login');
      await login(page, M2.email, M2.password);
      await page.goto('/explorer');
      const serviceLink = page.locator('a').filter({ hasText: title }).first();
      await expect(serviceLink).toBeVisible({ timeout: 8000 });
      const href = await serviceLink.getAttribute('href');
      serviceId = href.split('/services/')[1]?.split(/[?/]/)[0];
      expect(serviceId).toBeTruthy();

      await page.goto(`/services/${serviceId}`);
      await page.waitForTimeout(1000);
      await page.locator('button[type="submit"]').filter({ hasText: /Proposer/ }).click();
      await page.waitForTimeout(2000);

      const match = page.url().match(/\/messages\/([0-9a-f-]+)/i);
      expect(match).toBeTruthy();
      txId = match[1];
      await expect(page.locator('body')).toContainText('En attente');
    });

    await test.step('M1 accesses /messages/{txId} — BUG-001: Livewire must not 404', async () => {
      await page.context().clearCookies();
      await page.goto('/login');
      await page.waitForTimeout(500);
      await login(page, M1.email, M1.password);

      await page.goto(`/messages/${txId}`);
      await page.waitForTimeout(3000);

      const dialog = page.locator('dialog');
      const hasIframe = await dialog.locator('iframe').count();

      if (hasIframe > 0) {
        const iframeText = await dialog.locator('iframe').contentFrame().locator('body').textContent();
        test.fail(true, `BUG-001 NOT FIXED: Livewire 404 detected in dialog iframe. Text: ${iframeText}`);
      }

      const acceptBtn = page.getByRole('button', { name: 'Accepter' });

      await acceptBtn.waitFor({ state: 'visible', timeout: 10000 });

      await acceptBtn.click();
      await page.waitForTimeout(1500);
      await expect(page.locator('body')).toContainText('Accept');
      await expect(page.locator('body')).not.toContainText('Refus');
    });
  });
});
