import { test } from '@playwright/test';
import { loginAsSecondMember } from './helpers/auth.js';
test('le menu avatar porte Notifications sur la homepage', async ({ page }) => {
    await page.setViewportSize({ width: 412, height: 915 });
    const err = []; page.on('pageerror', e => err.push(String(e)));
    await loginAsSecondMember(page);
    await page.goto('/org/main');
    await page.waitForLoadState('networkidle');
    await page.locator('.bpam-trigger').first().click();
    await page.waitForTimeout(250);
    const items = await page.locator('.bpam-panel:not([hidden]) .bpam-item').allInnerTexts();
    console.log('ENTREES', JSON.stringify(items));
    console.log('ERREURS', JSON.stringify(err));
    await page.screenshot({ path: 'ai/playwright/screenshots/t1745-menu-avatar.png' });
});
