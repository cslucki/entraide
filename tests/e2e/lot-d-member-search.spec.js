import { test, expect } from '@playwright/test';
import { login } from '../../ai/playwright/helpers/auth.js';
import '../setup.js';

/**
 * Lot D — Member search in Dossier
 *
 * Fixture: deterministic LaunchPals dossier "LotD-FIXTURE"
 *   id: 019f8e47-4a57-72e9-a280-6dd50ce12fdd
 *   org: launchpals (019ef988-3ee7-7137-a1b3-77730ea8ff36)
 *   owner: launchpals.member1@bouclepro.test
 *
 * Users in LaunchPals org:
 *   - Cyril SLUCKI <cyril@teletravailleurs.com>
 *   - Roger MALINA <rxm116130@utdallas.edu>
 *   - Kiran Akshay Sundhararaajan <kiranakshay2598@gmail.com>
 *   - Demo LaunchPals Member 2 <launchpals.member2@bouclepro.test>
 *   - Cyril Demo LaunchPals Member 1 <launchpals.member1@bouclepro.test> (owner)
 *
 * RED note: Controller was already patched (LOWER, multi-word, trim) in a
 * previous session without documented RED proof. These tests assert the
 * expected behavior against the current codebase. See TASK file for
 * documented RED expectation vs GREEN reality.
 */

const LAUNCHPALS_ORG_SLUG = 'launchpals';
const FIXTURE_DOSSIER_ID = '019f8e47-4a57-72e9-a280-6dd50ce12fdd';
const MAIN_ORG_SLUG = 'main';

async function logoutViaFetch(page) {
    await page.evaluate(async () => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        await fetch('/logout', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
    });
}

async function searchMembers(page, orgSlug, dossierId, query) {
    return page.evaluate(async ({ orgSlug, dossierId, query }) => {
        const resp = await fetch(
            `/org/${orgSlug}/dossiers/${dossierId}/members/search?q=${encodeURIComponent(query)}`,
            { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
        );
        return { status: resp.status, data: resp.ok ? await resp.json() : null };
    }, { orgSlug, dossierId, query });
}

async function addMember(page, orgSlug, dossierId, userId, role) {
    return page.evaluate(async ({ orgSlug, dossierId, userId, role }) => {
        const resp = await fetch(`/org/${orgSlug}/dossiers/${dossierId}/members`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ user_id: userId, role }),
        });
        return { status: resp.status, data: resp.ok ? await resp.json() : null };
    }, { orgSlug, dossierId, userId, role });
}

async function removeMember(page, orgSlug, dossierId, memberId) {
    return page.evaluate(async ({ orgSlug, dossierId, memberId }) => {
        const resp = await fetch(`/org/${orgSlug}/dossiers/${dossierId}/members/${memberId}`, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
        });
        return { status: resp.status };
    }, { orgSlug, dossierId, memberId });
}

test.describe('Lot D — member search in Dossier (LaunchPals)', () => {

    test.beforeEach(async ({ page }) => {
        await login(page, 'launchpals.member1@bouclepro.test', 'password');
        await page.goto(`/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}`);
        await page.waitForLoadState('networkidle');
    });

    test('RED→GREEN: owner can search org users, non-empty results with expected fields', async ({ page }) => {
        const { status, data } = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'demo');
        expect(status).toBe(200);
        expect(data.users).toBeDefined();
        expect(Array.isArray(data.users)).toBeTruthy();
        expect(data.users.length).toBeGreaterThan(0);

        for (const user of data.users) {
            expect(user.id).toBeTruthy();
            expect(user.name).toBeTruthy();
            expect(user.email).toBeTruthy();
        }
    });

    test('RED→GREEN: owner is excluded from search results', async ({ page }) => {
        const { data } = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'cyril');
        expect(data.users.length).toBeGreaterThan(0);

        const ownerEmails = ['launchpals.member1@bouclepro.test'];
        for (const user of data.users) {
            expect(ownerEmails).not.toContain(user.email);
        }
    });

    test('RED→GREEN: existing members are excluded from search results', async ({ page }) => {
        const { data: beforeData } = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'demo');
        const member2 = beforeData.users.find(u => u.email === 'launchpals.member2@bouclepro.test');
        expect(member2).toBeTruthy();

        const addResult = await addMember(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, member2.id, 'reader');
        expect(addResult.status).toBe(200);

        const { data: afterData } = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'demo');
        const stillVisible = afterData.users.some(u => u.email === 'launchpals.member2@bouclepro.test');
        expect(stillVisible).toBe(false);

        await removeMember(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, member2.id);
    });

    test('RED→GREEN: case insensitive search returns same IDs', async ({ page }) => {
        const lower = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'kiran');
        const upper = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'KIRAN');
        const mixed = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'KiRaN');

        expect(lower.data.users.length).toBeGreaterThan(0);
        expect(lower.data.users.length).toBe(upper.data.users.length);
        expect(lower.data.users.length).toBe(mixed.data.users.length);

        const lowerIds = lower.data.users.map(u => u.id).sort();
        const upperIds = upper.data.users.map(u => u.id).sort();
        const mixedIds = mixed.data.users.map(u => u.id).sort();
        expect(lowerIds).toEqual(upperIds);
        expect(lowerIds).toEqual(mixedIds);
    });

    test('RED→GREEN: full name search (first_name + last_name)', async ({ page }) => {
        const { data } = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'Kiran Akshay');
        expect(data.users.length).toBe(1);
        expect(data.users[0].email).toBe('kiranakshay2598@gmail.com');
    });

    test('RED→GREEN: full name search reversed (last_name + first_name)', async ({ page }) => {
        const { data } = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'MALINA Roger');
        expect(data.users.length).toBe(1);
        expect(data.users[0].email).toBe('rxm116130@utdallas.edu');
    });

    test('RED→GREEN: multi-space and trim are normalized', async ({ page }) => {
        const spaces = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, '  kiran  akshay  ');
        const normal = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'kiran akshay');

        expect(spaces.data.users.length).toBeGreaterThan(0);
        expect(spaces.data.users.length).toBe(normal.data.users.length);

        const spaceIds = spaces.data.users.map(u => u.id).sort();
        const normalIds = normal.data.users.map(u => u.id).sort();
        expect(spaceIds).toEqual(normalIds);
    });

    test('RED→GREEN: reader cannot search members (403)', async ({ page }) => {
        const { data: searchResult } = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'demo');
        const member2 = searchResult.users.find(u => u.email === 'launchpals.member2@bouclepro.test');
        expect(member2).toBeTruthy();

        const addResult = await addMember(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, member2.id, 'reader');
        expect(addResult.status).toBe(200);

        await logoutViaFetch(page);
        await login(page, 'launchpals.member2@bouclepro.test', 'password');
        await page.goto(`/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}`);
        await page.waitForLoadState('networkidle');

        const { status } = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'demo');
        expect(status).toBe(403);

        await logoutViaFetch(page);
        await login(page, 'launchpals.member1@bouclepro.test', 'password');
        await page.goto(`/org/${LAUNCHPALS_ORG_SLUG}/dossiers/${FIXTURE_DOSSIER_ID}`);
        await page.waitForLoadState('networkidle');
        await removeMember(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, member2.id);
    });

    test('RED→GREEN: cross-organization user (Main) cannot search LaunchPals members (403)', async ({ page }) => {
        await logoutViaFetch(page);
        await login(page, 'admin@bouclepro.test', 'password');
        await page.goto(`/org/${MAIN_ORG_SLUG}`);
        await page.waitForLoadState('networkidle');

        const { status } = await searchMembers(page, LAUNCHPALS_ORG_SLUG, FIXTURE_DOSSIER_ID, 'demo');
        expect(status).toBe(403);
    });
});
