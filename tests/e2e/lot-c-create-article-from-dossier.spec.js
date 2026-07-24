import { test, expect } from '@playwright/test';
import { login } from '../../ai/playwright/helpers/auth.js';
import '../setup.js';

const DOSSIER_ID = '019f8363-136b-72b0-8b88-2e4b2efb63cb';
const ORG_SLUG = 'main';

test.describe('Lot C — create article from Dossier (atomic create+attach)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page, 'admin@bouclepro.test', 'password');
    });

    test('GREEN: create-and-attach returns 201 with post and redirect_url', async ({ page }) => {
        const title = `LotC-GREEN-${Date.now()}`;

        const resp = await page.request.post(
            `/org/${ORG_SLUG}/dossiers/${DOSSIER_ID}/articles/create-and-attach`,
            {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                data: {
                    title,
                },
            }
        );
        expect(resp.status()).toBe(201);
        const data = await resp.json();
        expect(data.post.id).toBeTruthy();
        expect(data.post.title).toBe(title);
        expect(data.redirect_url).toContain('/blog/');
        expect(data.redirect_url).toContain('/edit');
        expect(data.entry).toBeTruthy();
        expect(data.entry.position).toBeGreaterThanOrEqual(1);
    });

    test('GREEN: created article is attached (not in search results)', async ({ page }) => {
        const title = `LotC-GREEN2-${Date.now()}`;

        const createResp = await page.request.post(
            `/org/${ORG_SLUG}/dossiers/${DOSSIER_ID}/articles/create-and-attach`,
            {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                data: {
                    title,
                },
            }
        );
        expect(createResp.status()).toBe(201);
        const createData = await createResp.json();
        const postId = createData.post.id;

        const searchResp = await page.request.get(
            `/org/${ORG_SLUG}/dossiers/${DOSSIER_ID}/articles/search?q=${encodeURIComponent(title)}`
        );
        expect(searchResp.ok()).toBeTruthy();
        const searchData = await searchResp.json();
        const found = searchData.articles.find(a => a.id === postId);
        expect(found).toBeUndefined();
    });

    test('GREEN: created article appears in Contenus list immediately', async ({ page }) => {
        const title = `LotC-Contenus-${Date.now()}`;

        const createResp = await page.request.post(
            `/org/${ORG_SLUG}/dossiers/${DOSSIER_ID}/articles/create-and-attach`,
            {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                data: {
                    title,
                },
            }
        );
        expect(createResp.status()).toBe(201);

        await page.goto(`/org/${ORG_SLUG}/dossiers/${DOSSIER_ID}`);
        await page.waitForLoadState('networkidle');

        const articleRow = page.locator(`text="${title}"`).first();
        await expect(articleRow).toBeVisible({ timeout: 10000 });
    });

    test('GREEN: create-and-attach is atomic — title is required', async ({ page }) => {
        const resp = await page.request.post(
            `/org/${ORG_SLUG}/dossiers/${DOSSIER_ID}/articles/create-and-attach`,
            {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                data: {
                    title: '',
                },
            }
        );
        expect(resp.status()).toBe(422);
    });
});
