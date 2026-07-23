import { test, expect } from '@playwright/test';
import { login } from '../../ai/playwright/helpers/auth.js';
import '../setup.js';

const DOSSIER_ID = '019f8363-136b-72b0-8b88-2e4b2efb63cb';

test.describe('Lot A — searchQuery reorder fix (GREEN)', () => {
    test('move buttons disabled and guidance shown during active search', async ({ page }) => {
        await login(page, 'admin@bouclepro.test', 'password');
        await page.goto(`/org/main/dossiers/${DOSSIER_ID}`);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);

        // 1. WITHOUT search: Art5's "Descendre" is enabled (index 1 out of 5, not last)
        const art5MoveDown = page.locator('[x-ref="ungroupedContainer"] [data-article-id]').nth(1)
            .locator('button[title*="Descendre"]').first();
        await expect(art5MoveDown).toBeEnabled();

        // 2. Type search query — filters ungrouped to just Art5 (1 item)
        const searchInput = page.getByRole('textbox', { name: 'Chercher un article dans ce' });
        await searchInput.fill('Article 5');
        await page.waitForTimeout(500);

        // 3. Guidance message visible
        const guidanceMsg = page.locator('text=Effacez la recherche pour réorganiser');
        await expect(guidanceMsg).toBeVisible();

        // 4. Only 1 ungrouped item visible (Art5), its buttons are disabled by isSearchActive
        const filteredItems = page.locator('[x-ref="ungroupedContainer"] [data-article-id]');
        await expect(filteredItems).toHaveCount(1);

        const onlyItemMoveUp = filteredItems.first().locator('button[title*="Monter"]').first();
        const onlyItemMoveDown = filteredItems.first().locator('button[title*="Descendre"]').first();
        await expect(onlyItemMoveUp).toBeDisabled();
        await expect(onlyItemMoveDown).toBeDisabled();

        // 5. Clear search — buttons re-enabled, message disappears
        await searchInput.fill('');
        await page.waitForTimeout(500);
        await expect(art5MoveDown).toBeEnabled();
        await expect(guidanceMsg).not.toBeVisible();
    });
});
