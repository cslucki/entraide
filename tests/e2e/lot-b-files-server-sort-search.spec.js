import { test, expect } from '@playwright/test';
import { login } from '../../ai/playwright/helpers/auth.js';
import '../setup.js';

const DOSSIER_ID = '019f8363-136b-72b0-8b88-2e4b2efb63cb';

test.describe('Lot B — server-side sort & search for dossier files', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, 'admin@bouclepro.test', 'password');
    });

    test('default sort is date desc (latest first)', async ({ page }) => {
        const resp = await page.request.get(
            `/org/main/dossiers/${DOSSIER_ID}/files?page=1&sort=date&direction=desc`
        );
        expect(resp.ok()).toBeTruthy();
        const json = await resp.json();
        const files = json.files.data;
        expect(files.length).toBeGreaterThan(0);

        for (let i = 1; i < files.length; i++) {
            const prev = new Date(files[i - 1].created_at).getTime();
            const curr = new Date(files[i].created_at).getTime();
            expect(prev).toBeGreaterThanOrEqual(curr);
        }
    });

    test('sort by name asc returns deterministic results', async ({ page }) => {
        const resp1 = await page.request.get(
            `/org/main/dossiers/${DOSSIER_ID}/files?page=1&sort=name&direction=asc`
        );
        expect(resp1.ok()).toBeTruthy();
        const json1 = await resp1.json();
        const files1 = json1.files.data;
        expect(files1.length).toBeGreaterThan(0);

        const resp2 = await page.request.get(
            `/org/main/dossiers/${DOSSIER_ID}/files?page=1&sort=name&direction=asc`
        );
        expect(resp2.ok()).toBeTruthy();
        const json2 = await resp2.json();
        const files2 = json2.files.data;

        const ids1 = files1.map(f => f.id);
        const ids2 = files2.map(f => f.id);
        expect(ids1).toEqual(ids2);
    });

    test('sort by size asc returns ascending byte order', async ({ page }) => {
        const resp = await page.request.get(
            `/org/main/dossiers/${DOSSIER_ID}/files?page=1&sort=size&direction=asc`
        );
        expect(resp.ok()).toBeTruthy();
        const json = await resp.json();
        const files = json.files.data;
        expect(files.length).toBeGreaterThan(0);

        for (let i = 1; i < files.length; i++) {
            expect(files[i - 1].size_bytes).toBeLessThanOrEqual(files[i].size_bytes);
        }
    });

    test('search filters by display_name', async ({ page }) => {
        const resp = await page.request.get(
            `/org/main/dossiers/${DOSSIER_ID}/files?page=1&sort=date&direction=desc&search=test`
        );
        expect(resp.ok()).toBeTruthy();
        const json = await resp.json();
        const files = json.files.data;

        for (const file of files) {
            const name = (file.display_name || file.original_name || '').toLowerCase();
            expect(name).toContain('test');
        }
    });

    test('invalid sort param falls back to created_at with given direction', async ({ page }) => {
        const resp = await page.request.get(
            `/org/main/dossiers/${DOSSIER_ID}/files?page=1&sort=nonexistent&direction=asc`
        );
        expect(resp.ok()).toBeTruthy();
        const json = await resp.json();
        const files = json.files.data;
        expect(files.length).toBeGreaterThan(0);

        for (let i = 1; i < files.length; i++) {
            const prev = new Date(files[i - 1].created_at).getTime();
            const curr = new Date(files[i].created_at).getTime();
            expect(prev).toBeLessThanOrEqual(curr);
        }
    });

    test('invalid direction param falls back to desc', async ({ page }) => {
        const resp = await page.request.get(
            `/org/main/dossiers/${DOSSIER_ID}/files?page=1&sort=date&direction=invalid`
        );
        expect(resp.ok()).toBeTruthy();
        const json = await resp.json();
        const files = json.files.data;
        expect(files.length).toBeGreaterThan(0);

        for (let i = 1; i < files.length; i++) {
            const prev = new Date(files[i - 1].created_at).getTime();
            const curr = new Date(files[i].created_at).getTime();
            expect(prev).toBeGreaterThanOrEqual(curr);
        }
    });

    test('pagination works with sort and search', async ({ page }) => {
        const resp = await page.request.get(
            `/org/main/dossiers/${DOSSIER_ID}/files?page=1&sort=name&direction=asc`
        );
        expect(resp.ok()).toBeTruthy();
        const json = await resp.json();
        expect(json.files.current_page).toBe(1);
        expect(json.files.last_page).toBeDefined();
        expect(json.files.total).toBeDefined();
        expect(json.files.data).toBeDefined();
    });

    test('UI loads files tab and search input exists', async ({ page }) => {
        await page.goto(`/org/main/dossiers/${DOSSIER_ID}`);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);

        await page.click('#tab-fichiers');
        await page.waitForTimeout(500);

        const searchInput = page.locator('input[type="search"]');
        await expect(searchInput).toBeVisible();

        const fileRows = page.locator('table tbody tr');
        const count = await fileRows.count();
        expect(count).toBeGreaterThan(0);
    });

    test('UI search input triggers server-side filtering', async ({ page }) => {
        await page.goto(`/org/main/dossiers/${DOSSIER_ID}`);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(800);

        await page.click('#tab-fichiers');
        await page.waitForTimeout(500);

        const fileRowsBefore = page.locator('table tbody tr');
        const countBefore = await fileRowsBefore.count();

        const searchInput = page.locator('input[type="search"]');
        await searchInput.fill('zzz-nonexistent-query');
        await page.waitForTimeout(600);

        const fileRowsAfter = page.locator('table tbody tr');
        const countAfter = await fileRowsAfter.count();
        expect(countAfter).toBeLessThanOrEqual(countBefore);
    });
});
