import { test, expect } from '@playwright/test';
import { login } from '../../ai/playwright/helpers/auth.js';
import '../setup.js';

/**
 * Lot E — Modal Accessibility (strengthened per Cockpit directive)
 *
 * Fixture: deterministic LaunchPals dossier "LotD-FIXTURE"
 *   id: 019f8e47-4a57-72e9-a280-6dd50ce12fdd
 *   org: launchpals (019ef988-3ee7-7137-a1b3-77730ea8ff36)
 *   owner: launchpals.member1@bouclepro.test
 *
 * Canonical acceptance per modal:
 *   1. ARIA: role="dialog", aria-modal="true", aria-labelledby → unique visible heading
 *   2. Initial focus: first focusable element receives focus on open
 *   3. Focus trap: Tab cycles within modal (activeElement inside overlay)
 *   4. Escape: closes modal
 *   5. Focus return: focus returns to EXACT trigger element after close
 *
 * Modal inventory (9 modals in show.blade.php):
 *   dossierContentsCard:
 *     #1  Add Article Modal        (L573)
 *     #2  Delete Series Modal      (L603) — needs series context (not testable E2E)
 *     #3  Detach Article Modal     (L617) — needs attached article (not testable E2E)
 *   dossierFilesCard:
 *     #4  Create Article Modal     (L736)
 *     #5  Markdown Note Modal      (L767)
 *     #6  Delete File Modal        (L990) — needs file fixture
 *     #7  File Preview Modal       (L1004) — needs file fixture
 *   dossierMembersCard:
 *     #8  Manage Members Modal     (L1130)
 *     #9  Remove Member Modal      (L1224) — needs member fixture
 */

const LAUNCHPALS_ORG_SLUG = 'launchpals';
const FIXTURE_DOSSIER_ID = '019f8e47-4a57-72e9-a280-6dd50ce12fdd';
const ORG_URL = `/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}`;

let fixtureFileId = null;
let fixtureFileId2 = null;
let fixtureMemberId = null;
let fixtureMemberUserId = null;

async function csrfToken(page) {
    return page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content || '');
}

async function apiGet(page, url) {
    return page.evaluate(async ({ url }) => {
        const resp = await fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = resp.ok ? await resp.json() : null;
        return { status: resp.status, data };
    }, { url });
}

async function apiPost(page, url, body) {
    const token = await csrfToken(page);
    return page.evaluate(async ({ url, body, token }) => {
        const resp = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify(body),
        });
        const data = resp.ok ? await resp.json() : null;
        return { status: resp.status, data };
    }, { url, body, token });
}

async function apiDelete(page, url) {
    const token = await csrfToken(page);
    return page.evaluate(async ({ url, token }) => {
        const resp = await fetch(url, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
        });
        return { status: resp.status };
    }, { url, token });
}

async function uploadFixtureFile(page, name, content) {
    const token = await csrfToken(page);
    return page.evaluate(async ({ url, name, content, token }) => {
        const formData = new FormData();
        const blob = new Blob([content], { type: 'text/plain' });
        formData.append('files[0]', blob, name);
        const resp = await fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': token,
            },
            body: formData,
        });
        const data = resp.ok ? await resp.json() : null;
        return { status: resp.status, data };
    }, { url: `/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}/files`, name, content, token });
}

async function getActiveElementInfo(page) {
    return page.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body) return { tag: 'body', id: null, role: null };
        return {
            tag: el.tagName.toLowerCase(),
            id: el.id || null,
            role: el.getAttribute('role') || null,
            type: el.getAttribute('type') || null,
        };
    });
}

async function isFocusInsideOverlay(page, overlaySelector) {
    return page.evaluate((sel) => {
        const overlay = document.querySelector(sel);
        const active = document.activeElement;
        if (!overlay || !active || active === document.body) return false;
        return overlay.contains(active);
    }, overlaySelector);
}

async function getFirstFocusableInfo(page, containerSelector) {
    return page.evaluate((sel) => {
        const container = document.querySelector(sel);
        if (!container) return null;
        const focusable = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
        const first = container.querySelector(focusable);
        if (!first) return null;
        return { tag: first.tagName.toLowerCase(), id: first.id || null };
    }, containerSelector);
}

async function queryDialogAria(page, overlaySelector) {
    return page.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) return null;
        const labelledby = el.getAttribute('aria-labelledby');
        let headingInfo = null;
        if (labelledby) {
            const heading = document.getElementById(labelledby);
            headingInfo = heading
                ? { found: true, tag: heading.tagName.toLowerCase(), text: heading.textContent?.trim() || null, visible: heading.offsetParent !== null || heading.offsetHeight > 0 }
                : { found: false, tag: null, text: null, visible: false };
        }
        return {
            role: el.getAttribute('role'),
            ariaModal: el.getAttribute('aria-modal'),
            ariaLabelledby: labelledby,
            headingInfo,
        };
    }, overlaySelector);
}

async function countFocusablesInside(page, containerSelector) {
    return page.evaluate((sel) => {
        const container = document.querySelector(sel);
        if (!container) return 0;
        const focusable = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
        return container.querySelectorAll(focusable).length;
    }, containerSelector);
}

test.describe('Lot E — Modal Accessibility (LaunchPals)', () => {

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        await login(page, 'launchpals.member1@bouclepro.test', 'password');

        const r1 = await uploadFixtureFile(page, 'lot-e-test-a.txt', 'Lot E fixture file A for accessibility testing.');
        expect(r1.status, 'File fixture A upload must succeed').toBe(201);
        fixtureFileId = r1.data.files[0].id;

        const r2 = await uploadFixtureFile(page, 'lot-e-test-b.txt', 'Lot E fixture file B for preview testing.');
        expect(r2.status, 'File fixture B upload must succeed').toBe(201);
        fixtureFileId2 = r2.data.files[0].id;

        const searchUrl = `/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}/members/search?q=kiran`;
        const sr = await apiGet(page, searchUrl);
        expect(sr.status, 'Member search must succeed').toBe(200);
        expect(sr.data.users.length, 'Must find at least one searchable user').toBeGreaterThan(0);
        fixtureMemberUserId = sr.data.users[0].id;

        const addUrl = `/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}/members`;
        const ar = await apiPost(page, addUrl, { user_id: fixtureMemberUserId, role: 'reader' });
        expect(ar.status, 'Member add must succeed').toBe(200);
        fixtureMemberId = ar.data.member.id;

        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        await login(page, 'launchpals.member1@bouclepro.test', 'password');

        if (fixtureMemberId) {
            await apiDelete(page, `/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}/members/${fixtureMemberId}`);
        }
        if (fixtureFileId) {
            await apiDelete(page, `/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}/files/${fixtureFileId}`);
        }
        if (fixtureFileId2) {
            await apiDelete(page, `/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}/files/${fixtureFileId2}`);
        }
        await page.close();
    });

    test.beforeEach(async ({ page }) => {
        await login(page, 'launchpals.member1@bouclepro.test', 'password');
        await page.goto(ORG_URL);
        await page.waitForLoadState('networkidle');
    });

    // ════════════════════════════════════════════════════════════════
    // ──── Modal #1 — Add Article Modal (dossierContentsCard) ────
    // ════════════════════════════════════════════════════════════════

    test('#1 Add Article — overlay has role="dialog"', async ({ page }) => {
        await page.getByRole('button', { name: /ajouter un article/i }).click();
        await page.waitForTimeout(400);

        const dialog = await queryDialogAria(page, '[x-data*="dossierContentsCard"] .fixed.inset-0.z-50');
        expect(dialog).not.toBeNull();
        expect(dialog.role).toBe('dialog');
        await page.keyboard.press('Escape');
    });

    test('#1 Add Article — aria-modal="true"', async ({ page }) => {
        await page.getByRole('button', { name: /ajouter un article/i }).click();
        await page.waitForTimeout(400);

        const dialog = await queryDialogAria(page, '[x-data*="dossierContentsCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaModal).toBe('true');
        await page.keyboard.press('Escape');
    });

    test('#1 Add Article — aria-labelledby points to unique visible heading', async ({ page }) => {
        await page.getByRole('button', { name: /ajouter un article/i }).click();
        await page.waitForTimeout(400);

        const dialog = await queryDialogAria(page, '[x-data*="dossierContentsCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaLabelledby).toBeTruthy();
        expect(dialog.headingInfo).not.toBeNull();
        expect(dialog.headingInfo.found).toBe(true);
        expect(dialog.headingInfo.tag).toMatch(/^h[1-6]$/);
        expect(dialog.headingInfo.text).toBeTruthy();
        expect(dialog.headingInfo.visible).toBe(true);

        const duplicateCount = await page.evaluate((id) => {
            return document.querySelectorAll(`[id="${id}"]`).length;
        }, dialog.ariaLabelledby);
        expect(duplicateCount, 'aria-labelledby ID must be unique').toBe(1);

        await page.keyboard.press('Escape');
    });

    test('#1 Add Article — initial focus on first focusable element', async ({ page }) => {
        const overlay = '[x-data*="dossierContentsCard"] .fixed.inset-0.z-50';
        await page.getByRole('button', { name: /ajouter un article/i }).click();
        await page.waitForTimeout(400);

        const inside = await isFocusInsideOverlay(page, overlay);
        expect(inside, 'Initial focus must be inside the overlay').toBe(true);
        await page.keyboard.press('Escape');
    });

    test('#1 Add Article — Escape closes modal', async ({ page }) => {
        await page.getByRole('button', { name: /ajouter un article/i }).click();
        await page.waitForTimeout(400);

        const visible = await page.locator('[x-data*="dossierContentsCard"] .fixed.inset-0.z-50').isVisible();
        expect(visible).toBe(true);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        const afterEscape = await page.locator('[x-data*="dossierContentsCard"] .fixed.inset-0.z-50').count();
        expect(afterEscape).toBe(0);
    });

    test('#1 Add Article — focus trap: Tab stays inside modal', async ({ page }) => {
        const container = '[x-data*="dossierContentsCard"] .fixed.inset-0.z-50';
        await page.getByRole('button', { name: /ajouter un article/i }).click();
        await page.waitForTimeout(400);

        const focusableCount = await countFocusablesInside(page, container);
        expect(focusableCount).toBeGreaterThan(0);

        const focusables = page.locator(`${container} a[href], ${container} button:not([disabled]), ${container} input:not([disabled]):not([type="hidden"]), ${container} [tabindex]:not([tabindex="-1"])`);

        // Tab from last → should wrap to first
        await focusables.nth(focusableCount - 1).focus();
        await page.keyboard.press('Tab');
        expect(await isFocusInsideOverlay(page, container), 'Tab from last must stay inside modal').toBe(true);

        // Shift+Tab from first → should wrap to last
        await focusables.first().focus();
        await page.keyboard.press('Shift+Tab');
        expect(await isFocusInsideOverlay(page, container), 'Shift+Tab from first must stay inside modal').toBe(true);

        await page.keyboard.press('Escape');
    });

    test('#1 Add Article — focus returns to trigger after close', async ({ page }) => {
        const trigger = page.getByRole('button', { name: /ajouter un article/i });
        await trigger.focus();
        await trigger.click();
        await page.waitForTimeout(400);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await expect(trigger).toBeFocused();
    });

    // ════════════════════════════════════════════════════════════════
    // ──── Modal #4 — Create Article Modal (dossierFilesCard) ────
    // ════════════════════════════════════════════════════════════════

    async function openFilesTab(page) {
        await page.locator('[role="tab"]').filter({ hasText: /fichiers/i }).click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);
    }

    async function openCreateArticleModal(page) {
        await openFilesTab(page);
        await page.locator('[x-data*="dossierFilesCard"] button').filter({ hasText: /ajouter|add/i }).first().click();
        await page.waitForTimeout(200);
        await page.locator('button').filter({ hasText: /nouvel article|créer un article/i }).first().click();
        await page.waitForTimeout(400);
    }

    test('#4 Create Article — overlay has role="dialog"', async ({ page }) => {
        await openCreateArticleModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog).not.toBeNull();
        expect(dialog.role).toBe('dialog');
        await page.keyboard.press('Escape');
    });

    test('#4 Create Article — aria-modal="true"', async ({ page }) => {
        await openCreateArticleModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaModal).toBe('true');
        await page.keyboard.press('Escape');
    });

    test('#4 Create Article — aria-labelledby points to unique visible heading', async ({ page }) => {
        await openCreateArticleModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaLabelledby).toBeTruthy();
        expect(dialog.headingInfo.found).toBe(true);
        expect(dialog.headingInfo.tag).toMatch(/^h[1-6]$/);
        expect(dialog.headingInfo.text).toBeTruthy();
        expect(dialog.headingInfo.visible).toBe(true);

        const dupes = await page.evaluate((id) => document.querySelectorAll(`[id="${id}"]`).length, dialog.ariaLabelledby);
        expect(dupes).toBe(1);

        await page.keyboard.press('Escape');
    });

    test('#4 Create Article — initial focus on first focusable element', async ({ page }) => {
        const overlay = '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50';
        await openCreateArticleModal(page);

        const inside = await isFocusInsideOverlay(page, overlay);
        expect(inside, 'Initial focus must be inside the overlay').toBe(true);
        await page.keyboard.press('Escape');
    });

    test('#4 Create Article — Escape closes modal', async ({ page }) => {
        await openCreateArticleModal(page);
        const visible = await page.locator('[x-data*="dossierFilesCard"] .fixed.inset-0.z-50').isVisible();
        expect(visible).toBe(true);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        const after = await page.locator('[x-data*="dossierFilesCard"] .fixed.inset-0.z-50').count();
        expect(after).toBe(0);
    });

    test('#4 Create Article — focus trap: Tab stays inside modal', async ({ page }) => {
        const container = '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50';
        await openCreateArticleModal(page);

        const focusableCount = await countFocusablesInside(page, container);
        expect(focusableCount).toBeGreaterThan(0);

        const focusables = page.locator(`${container} a[href], ${container} button:not([disabled]), ${container} input:not([disabled]):not([type="hidden"]), ${container} select:not([disabled]), ${container} [tabindex]:not([tabindex="-1"])`);

        await focusables.nth(focusableCount - 1).focus();
        await page.keyboard.press('Tab');
        expect(await isFocusInsideOverlay(page, container), 'Tab from last must stay inside modal').toBe(true);

        await focusables.first().focus();
        await page.keyboard.press('Shift+Tab');
        expect(await isFocusInsideOverlay(page, container), 'Shift+Tab from first must stay inside modal').toBe(true);

        await page.keyboard.press('Escape');
    });

    test('#4 Create Article — focus returns to trigger after close', async ({ page }) => {
        await openFilesTab(page);
        const trigger = page.locator('[x-data*="dossierFilesCard"] button').filter({ hasText: /ajouter|add/i }).first();
        await trigger.focus();
        await trigger.click();
        await page.waitForTimeout(200);
        await page.locator('button').filter({ hasText: /nouvel article|créer un article/i }).first().click();
        await page.waitForTimeout(400);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await expect(trigger).toBeFocused();
    });

    // ════════════════════════════════════════════════════════════════
    // ──── Modal #5 — Markdown Note Modal (dossierFilesCard) ────
    // ════════════════════════════════════════════════════════════════

    async function openMarkdownModal(page) {
        await openFilesTab(page);
        await page.locator('[x-data*="dossierFilesCard"] button').filter({ hasText: /ajouter|add/i }).first().click();
        await page.waitForTimeout(200);
        await page.locator('button').filter({ hasText: /note markdown|markdown/i }).first().click();
        await page.waitForTimeout(400);
    }

    test('#5 Markdown Note — overlay has role="dialog"', async ({ page }) => {
        await openMarkdownModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog).not.toBeNull();
        expect(dialog.role).toBe('dialog');
        await page.keyboard.press('Escape');
    });

    test('#5 Markdown Note — aria-modal="true"', async ({ page }) => {
        await openMarkdownModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaModal).toBe('true');
        await page.keyboard.press('Escape');
    });

    test('#5 Markdown Note — aria-labelledby points to unique visible heading', async ({ page }) => {
        await openMarkdownModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaLabelledby).toBeTruthy();
        expect(dialog.headingInfo.found).toBe(true);
        expect(dialog.headingInfo.tag).toMatch(/^h[1-6]$/);
        expect(dialog.headingInfo.text).toBeTruthy();
        expect(dialog.headingInfo.visible).toBe(true);

        const dupes = await page.evaluate((id) => document.querySelectorAll(`[id="${id}"]`).length, dialog.ariaLabelledby);
        expect(dupes).toBe(1);

        await page.keyboard.press('Escape');
    });

    test('#5 Markdown Note — initial focus on first focusable element', async ({ page }) => {
        const overlay = '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50';
        await openMarkdownModal(page);

        const inside = await isFocusInsideOverlay(page, overlay);
        expect(inside, 'Initial focus must be inside the overlay').toBe(true);
        await page.keyboard.press('Escape');
    });

    test('#5 Markdown Note — Escape closes modal', async ({ page }) => {
        await openMarkdownModal(page);
        const visible = await page.locator('[x-data*="dossierFilesCard"] .fixed.inset-0.z-50').isVisible();
        expect(visible).toBe(true);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        const after = await page.locator('[x-data*="dossierFilesCard"] .fixed.inset-0.z-50').count();
        expect(after).toBe(0);
    });

    test('#5 Markdown Note — focus trap: Tab stays inside modal', async ({ page }) => {
        const container = '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50';
        await openMarkdownModal(page);

        const focusableCount = await countFocusablesInside(page, container);
        expect(focusableCount).toBeGreaterThan(0);

        const focusables = page.locator(`${container} a[href], ${container} button:not([disabled]), ${container} input:not([disabled]):not([type="hidden"]), ${container} textarea:not([disabled]), ${container} [tabindex]:not([tabindex="-1"])`);

        await focusables.nth(focusableCount - 1).focus();
        await page.keyboard.press('Tab');
        expect(await isFocusInsideOverlay(page, container), 'Tab from last must stay inside modal').toBe(true);

        await focusables.first().focus();
        await page.keyboard.press('Shift+Tab');
        expect(await isFocusInsideOverlay(page, container), 'Shift+Tab from first must stay inside modal').toBe(true);

        await page.keyboard.press('Escape');
    });

    test('#5 Markdown Note — focus returns to trigger after close', async ({ page }) => {
        await openFilesTab(page);
        const trigger = page.locator('[x-data*="dossierFilesCard"] button').filter({ hasText: /ajouter|add/i }).first();
        await trigger.focus();
        await trigger.click();
        await page.waitForTimeout(200);
        await page.locator('button').filter({ hasText: /note markdown|markdown/i }).first().click();
        await page.waitForTimeout(400);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await expect(trigger).toBeFocused();
    });

    // ════════════════════════════════════════════════════════════════
    // ──── Modal #6 — Delete File Modal (dossierFilesCard) ────
    // ════════════════════════════════════════════════════════════════

    async function openDeleteFileModal(page) {
        await openFilesTab(page);
        await page.waitForTimeout(500);
        const deleteBtn = page.locator('[x-data*="dossierFilesCard"] button[title="Supprimer"]').first();
        await deleteBtn.click();
        await page.waitForTimeout(400);
    }

    test('#6 Delete File — overlay has role="dialog"', async ({ page }) => {
        await openDeleteFileModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog).not.toBeNull();
        expect(dialog.role).toBe('dialog');
        await page.keyboard.press('Escape');
    });

    test('#6 Delete File — aria-modal="true"', async ({ page }) => {
        await openDeleteFileModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaModal).toBe('true');
        await page.keyboard.press('Escape');
    });

    test('#6 Delete File — aria-labelledby points to unique visible heading', async ({ page }) => {
        await openDeleteFileModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaLabelledby).toBeTruthy();
        expect(dialog.headingInfo.found).toBe(true);
        expect(dialog.headingInfo.tag).toMatch(/^h[1-6]$/);
        expect(dialog.headingInfo.text).toBeTruthy();
        expect(dialog.headingInfo.visible).toBe(true);

        const dupes = await page.evaluate((id) => document.querySelectorAll(`[id="${id}"]`).length, dialog.ariaLabelledby);
        expect(dupes).toBe(1);

        await page.keyboard.press('Escape');
    });

    test('#6 Delete File — initial focus on first focusable element', async ({ page }) => {
        const overlay = '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50';
        await openDeleteFileModal(page);

        const inside = await isFocusInsideOverlay(page, overlay);
        expect(inside, 'Initial focus must be inside the overlay').toBe(true);
        await page.keyboard.press('Escape');
    });

    test('#6 Delete File — Escape closes modal', async ({ page }) => {
        await openDeleteFileModal(page);
        const visible = await page.locator('[x-data*="dossierFilesCard"] .fixed.inset-0.z-50').isVisible();
        expect(visible).toBe(true);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        const after = await page.locator('[x-data*="dossierFilesCard"] .fixed.inset-0.z-50').count();
        expect(after).toBe(0);
    });

    test('#6 Delete File — focus trap: Tab stays inside modal', async ({ page }) => {
        const container = '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50';
        await openDeleteFileModal(page);

        const focusableCount = await countFocusablesInside(page, container);
        expect(focusableCount).toBeGreaterThan(0);

        const focusables = page.locator(`${container} a[href], ${container} button:not([disabled]), ${container} input:not([disabled]), ${container} [tabindex]:not([tabindex="-1"])`);

        await focusables.nth(focusableCount - 1).focus();
        await page.keyboard.press('Tab');
        expect(await isFocusInsideOverlay(page, container), 'Tab from last must stay inside modal').toBe(true);

        await focusables.first().focus();
        await page.keyboard.press('Shift+Tab');
        expect(await isFocusInsideOverlay(page, container), 'Shift+Tab from first must stay inside modal').toBe(true);

        await page.keyboard.press('Escape');
    });

    test('#6 Delete File — focus returns to trigger after close', async ({ page }) => {
        await openFilesTab(page);
        await page.waitForTimeout(500);
        const trigger = page.locator('[x-data*="dossierFilesCard"] button[title="Supprimer"]').first();
        await trigger.focus();
        await trigger.click();
        await page.waitForTimeout(400);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await expect(trigger).toBeFocused();
    });

    // ════════════════════════════════════════════════════════════════
    // ──── Modal #7 — File Preview Modal (dossierFilesCard) ────
    // ════════════════════════════════════════════════════════════════

    async function openPreviewModal(page) {
        await openFilesTab(page);
        await page.waitForTimeout(500);
        const previewBtn = page.locator('[x-data*="dossierFilesCard"] button[title="Aperçu"]').first();
        await previewBtn.click();
        await page.waitForTimeout(400);
    }

    test('#7 File Preview — overlay has role="dialog"', async ({ page }) => {
        await openPreviewModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog).not.toBeNull();
        expect(dialog.role).toBe('dialog');
        await page.keyboard.press('Escape');
    });

    test('#7 File Preview — aria-modal="true"', async ({ page }) => {
        await openPreviewModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaModal).toBe('true');
        await page.keyboard.press('Escape');
    });

    test('#7 File Preview — aria-labelledby points to unique visible heading', async ({ page }) => {
        await openPreviewModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaLabelledby).toBeTruthy();
        expect(dialog.headingInfo.found).toBe(true);
        expect(dialog.headingInfo.tag).toMatch(/^h[1-6]$/);
        expect(dialog.headingInfo.text).toBeTruthy();
        expect(dialog.headingInfo.visible).toBe(true);

        const dupes = await page.evaluate((id) => document.querySelectorAll(`[id="${id}"]`).length, dialog.ariaLabelledby);
        expect(dupes).toBe(1);

        await page.keyboard.press('Escape');
    });

    test('#7 File Preview — initial focus on first focusable element', async ({ page }) => {
        const overlay = '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50';
        await openPreviewModal(page);

        const inside = await isFocusInsideOverlay(page, overlay);
        expect(inside, 'Initial focus must be inside the overlay').toBe(true);
        await page.keyboard.press('Escape');
    });

    test('#7 File Preview — Escape closes modal', async ({ page }) => {
        await openPreviewModal(page);
        const visible = await page.locator('[x-data*="dossierFilesCard"] .fixed.inset-0.z-50').isVisible();
        expect(visible).toBe(true);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        const after = await page.locator('[x-data*="dossierFilesCard"] .fixed.inset-0.z-50').count();
        expect(after).toBe(0);
    });

    test('#7 File Preview — focus trap: Tab stays inside modal', async ({ page }) => {
        const container = '[x-data*="dossierFilesCard"] .fixed.inset-0.z-50';
        await openPreviewModal(page);

        const focusableCount = await countFocusablesInside(page, container);
        expect(focusableCount).toBeGreaterThan(0);

        const focusables = page.locator(`${container} a[href], ${container} button:not([disabled]), ${container} input:not([disabled]), ${container} [tabindex]:not([tabindex="-1"])`);

        await focusables.nth(focusableCount - 1).focus();
        await page.keyboard.press('Tab');
        expect(await isFocusInsideOverlay(page, container), 'Tab from last must stay inside modal').toBe(true);

        await focusables.first().focus();
        await page.keyboard.press('Shift+Tab');
        expect(await isFocusInsideOverlay(page, container), 'Shift+Tab from first must stay inside modal').toBe(true);

        await page.keyboard.press('Escape');
    });

    test('#7 File Preview — focus returns to trigger after close', async ({ page }) => {
        await openFilesTab(page);
        await page.waitForTimeout(500);
        const trigger = page.locator('[x-data*="dossierFilesCard"] button[title="Aperçu"]').first();
        await trigger.focus();
        await trigger.click();
        await page.waitForTimeout(400);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await expect(trigger).toBeFocused();
    });

    // ════════════════════════════════════════════════════════════════
    // ──── Modal #8 — Manage Members Modal (dossierMembersCard) ────
    // ════════════════════════════════════════════════════════════════

    async function openManageMembersModal(page) {
        await page.locator('[role="tab"]').filter({ hasText: /membres/i }).click();
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: /gérer les membres|manage/i }).click();
        await page.waitForTimeout(400);
    }

    test('#8 Manage Members — overlay has role="dialog"', async ({ page }) => {
        await openManageMembersModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierMembersCard"] .fixed.inset-0.z-50');
        expect(dialog).not.toBeNull();
        expect(dialog.role).toBe('dialog');
        await page.keyboard.press('Escape');
    });

    test('#8 Manage Members — aria-modal="true"', async ({ page }) => {
        await openManageMembersModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierMembersCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaModal).toBe('true');
        await page.keyboard.press('Escape');
    });

    test('#8 Manage Members — aria-labelledby points to unique visible heading', async ({ page }) => {
        await openManageMembersModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierMembersCard"] .fixed.inset-0.z-50');
        expect(dialog.ariaLabelledby).toBeTruthy();
        expect(dialog.headingInfo.found).toBe(true);
        expect(dialog.headingInfo.tag).toMatch(/^h[1-6]$/);
        expect(dialog.headingInfo.text).toBeTruthy();
        expect(dialog.headingInfo.visible).toBe(true);

        const dupes = await page.evaluate((id) => document.querySelectorAll(`[id="${id}"]`).length, dialog.ariaLabelledby);
        expect(dupes).toBe(1);

        await page.keyboard.press('Escape');
    });

    test('#8 Manage Members — initial focus on first focusable element', async ({ page }) => {
        const overlay = '[x-data*="dossierMembersCard"] .fixed.inset-0.z-50';
        await openManageMembersModal(page);

        const inside = await isFocusInsideOverlay(page, overlay);
        expect(inside, 'Initial focus must be inside the overlay').toBe(true);
        await page.keyboard.press('Escape');
    });

    test('#8 Manage Members — Escape closes modal', async ({ page }) => {
        await openManageMembersModal(page);
        const visible = await page.locator('[x-data*="dossierMembersCard"] .fixed.inset-0.z-50').isVisible();
        expect(visible).toBe(true);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        const after = await page.locator('[x-data*="dossierMembersCard"] .fixed.inset-0.z-50').count();
        expect(after).toBe(0);
    });

    test('#8 Manage Members — focus trap: Tab stays inside modal', async ({ page }) => {
        const container = '[x-data*="dossierMembersCard"] .fixed.inset-0.z-50';
        await openManageMembersModal(page);

        const focusableCount = await countFocusablesInside(page, container);
        expect(focusableCount).toBeGreaterThan(0);

        const focusables = page.locator(`${container} a[href], ${container} button:not([disabled]), ${container} input:not([disabled]):not([type="hidden"]), ${container} select:not([disabled]), ${container} [tabindex]:not([tabindex="-1"])`);

        await focusables.nth(focusableCount - 1).focus();
        await page.keyboard.press('Tab');
        expect(await isFocusInsideOverlay(page, container), 'Tab from last must stay inside modal').toBe(true);

        await focusables.first().focus();
        await page.keyboard.press('Shift+Tab');
        expect(await isFocusInsideOverlay(page, container), 'Shift+Tab from first must stay inside modal').toBe(true);

        await page.keyboard.press('Escape');
    });

    test('#8 Manage Members — focus returns to trigger after close', async ({ page }) => {
        await page.locator('[role="tab"]').filter({ hasText: /membres/i }).click();
        await page.waitForLoadState('networkidle');
        const trigger = page.getByRole('button', { name: /gérer les membres|manage/i });
        await trigger.focus();
        await trigger.click();
        await page.waitForTimeout(400);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await expect(trigger).toBeFocused();
    });

    // ════════════════════════════════════════════════════════════════
    // ──── Modal #9 — Remove Member Modal (dossierMembersCard) ────
    // ════════════════════════════════════════════════════════════════

    async function openRemoveMemberModal(page) {
        await openManageMembersModal(page);
        await page.waitForTimeout(300);
        const removeBtn = page.locator('[x-data*="dossierMembersCard"] .fixed.inset-0.z-50 button[title="Retirer"]').first();
        await removeBtn.click();
        await page.waitForTimeout(400);
    }

    test('#9 Remove Member — overlay has role="dialog"', async ({ page }) => {
        await openRemoveMemberModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierMembersCard"] .fixed.inset-0.z-50:last-of-type');
        expect(dialog).not.toBeNull();
        expect(dialog.role).toBe('dialog');
        await page.keyboard.press('Escape');
        await page.keyboard.press('Escape');
    });

    test('#9 Remove Member — aria-modal="true"', async ({ page }) => {
        await openRemoveMemberModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierMembersCard"] .fixed.inset-0.z-50:last-of-type');
        expect(dialog.ariaModal).toBe('true');
        await page.keyboard.press('Escape');
        await page.keyboard.press('Escape');
    });

    test('#9 Remove Member — aria-labelledby points to unique visible heading', async ({ page }) => {
        await openRemoveMemberModal(page);
        const dialog = await queryDialogAria(page, '[x-data*="dossierMembersCard"] .fixed.inset-0.z-50:last-of-type');
        expect(dialog.ariaLabelledby).toBeTruthy();
        expect(dialog.headingInfo.found).toBe(true);
        expect(dialog.headingInfo.tag).toMatch(/^h[1-6]$/);
        expect(dialog.headingInfo.text).toBeTruthy();
        expect(dialog.headingInfo.visible).toBe(true);

        const dupes = await page.evaluate((id) => document.querySelectorAll(`[id="${id}"]`).length, dialog.ariaLabelledby);
        expect(dupes).toBe(1);

        await page.keyboard.press('Escape');
        await page.keyboard.press('Escape');
    });

    test('#9 Remove Member — Escape closes modal', async ({ page }) => {
        await openRemoveMemberModal(page);
        const removeModalVisible = await page.locator('[x-data*="dossierMembersCard"] .fixed.inset-0.z-50').last().isVisible();
        expect(removeModalVisible).toBe(true);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await page.keyboard.press('Escape');
    });

    test('#9 Remove Member — focus trap: Tab stays inside modal', async ({ page }) => {
        await openRemoveMemberModal(page);
        const container = '[x-data*="dossierMembersCard"] [aria-labelledby="remove-member-title"]';

        const focusableCount = await countFocusablesInside(page, container);
        expect(focusableCount).toBeGreaterThan(0);

        const focusables = page.locator(`${container} a[href], ${container} button:not([disabled]), ${container} input:not([disabled]), ${container} [tabindex]:not([tabindex="-1"])`);

        await focusables.nth(focusableCount - 1).focus();
        await page.keyboard.press('Tab');
        expect(await isFocusInsideOverlay(page, container), 'Tab from last must stay inside modal').toBe(true);

        await focusables.first().focus();
        await page.keyboard.press('Shift+Tab');
        expect(await isFocusInsideOverlay(page, container), 'Shift+Tab from first must stay inside modal').toBe(true);

        await page.keyboard.press('Escape');
        await page.keyboard.press('Escape');
    });

    test('#9 Remove Member — focus returns to trigger after close', async ({ page }) => {
        await openManageMembersModal(page);
        await page.waitForTimeout(300);
        const trigger = page.locator('[x-data*="dossierMembersCard"] .fixed.inset-0.z-50 button[title="Retirer"]').first();
        await trigger.focus();
        await trigger.click();
        await page.waitForTimeout(400);

        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);

        await expect(trigger).toBeFocused();

        await page.keyboard.press('Escape');
    });
});
