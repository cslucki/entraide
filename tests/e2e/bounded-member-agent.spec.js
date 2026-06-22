import { test, expect } from '@playwright/test';
import { loginAsMember } from '../../ai/playwright/helpers/auth.js';
import { setupConsoleLogging, getConsoleErrors, getPageErrors } from '../../ai/playwright/helpers/console.js';
import '../setup.js';

async function resetMemberProfile(page) {
    const csrfToken = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'));
    if (csrfToken) {
        await page.request.delete('/agent-ia/profile', { headers: { 'X-CSRF-TOKEN': csrfToken } });
    }
}

test.describe('Bounded Member Agent', () => {
    test.beforeEach(async ({ page }) => {
        setupConsoleLogging(page);
    });

    test.afterEach(async () => {
        const consoleErrors = getConsoleErrors();
        const pageErrors = getPageErrors();

        if (consoleErrors.length > 0 || pageErrors.length > 0) {
            console.log('Test completed with errors:');
            console.log('Console errors:', consoleErrors);
            console.log('Page errors:', pageErrors);
        }
    });

    test('shows fallback when member has no published profile', async ({ page }) => {
        await loginAsMember(page);
        await resetMemberProfile(page);

        // Get the current user's ID from the meta tag
        const userId = await page.evaluate(() => {
            const meta = document.querySelector('meta[name="user-id"]');
            return meta ? meta.getAttribute('content') : null;
        });

        // Use the current user's ID (they likely have no published profile yet)
        const agentUrl = userId ? `/agent-ia/member/${userId}` : '/agent-ia/member/1';
        await page.goto(agentUrl);

        await expect(page.getByText("Ce membre n'a pas encore publié son profil IA.")).toBeVisible();
        await expect(page.getByText('Profil non disponible')).toBeVisible();

        const errors = getConsoleErrors();
        expect(errors.filter(e => !e.message.includes('chrome-extension'))).toEqual([]);
    });

    test('shows profile data for member with published profile', async ({ page }) => {
        await loginAsMember(page);
        await resetMemberProfile(page);
        // Navigate to the wizard first to create a profile
        await page.goto('/agent-ia');
        await page.waitForSelector('#member_profile_summary', { timeout: 10000 });

        // Fill step 1
        await page.fill('#member_profile_summary', 'Consultant en marketing digital spécialisé SEO');
        await page.locator('button:has-text("entrepreneurs")').first().click();
        await page.locator('button:has-text("independants")').first().click();
        await page.fill('#problems_helped_raw', 'Stratégie de marque\nSEO technique');

        await page.locator('button:has-text("Continuer")').click();
        await expect(page.locator('text=Ce que vous apportez').first()).toBeVisible();

        // Fill step 2
        await page.fill('#service_scope', 'Accompagnement sur mesure');
        await page.fill('#skillsInput', 'SEO, Marketing digital, Rédaction');
        await page.fill('#experience_context', '5 ans en agence');
        await page.locator('button:has-text("avis_rapide")').first().click();
        await page.locator('button:has-text("repondre_question")').first().click();

        await page.locator('button:has-text("Continuer")').click();
        await expect(page.locator('text=Cadre et limites').first()).toBeVisible();

        // Fill step 3
        await page.locator('button:has-text("pas_urgence")').first().click();
        await page.locator('button:has-text("pas_travail_gratuit")').first().click();
        await page.locator('label:has(span:text("envoyer_demande_echange"))').first().click();
        await page.locator('label:has(input[value="chaleureux"])').first().click();

        await page.locator('button:has-text("Continuer")').click();
        await expect(page.locator('text=Exemples').first()).toBeVisible();

        // Fill step 4
        await page.fill('input[placeholder*="Exemple"]', 'Aide pour stratégie de contenu');
        await page.locator('button:has-text("Ajouter")').first().click();
        await page.locator('button:has-text("Ajouter")').first().click();

        await page.locator('button:has-text("Continuer")').click();
        await expect(page.locator('text=Votre profil est presque prêt').first()).toBeVisible();

        // Publish
        await page.locator('button:has-text("Publier")').click();
        await expect(page.getByText('Profil publié')).toBeVisible();

        // Now get the current user ID from the page
        const userId = await page.evaluate(() => {
            const meta = document.querySelector('meta[name="user-id"]');
            return meta ? meta.getAttribute('content') : null;
        });

        // Visit the bounded agent page
        const agentUrl = userId ? `/agent-ia/member/${userId}` : '/agent-ia/member/1';
        await page.goto(agentUrl);

        // Should see member profile info
        await expect(page.getByText('Agent IA de présentation')).toBeVisible();
        await expect(page.getByText('Compétences')).toBeVisible();

        const errors = getConsoleErrors();
        expect(errors.filter(e => !e.message.includes('chrome-extension'))).toEqual([]);
    });

    test('ask question and get response', async ({ page }) => {
        await loginAsMember(page);
        await resetMemberProfile(page);

        // First publish a profile
        await page.goto('/agent-ia');
        await page.waitForSelector('#member_profile_summary', { timeout: 10000 });

        await page.fill('#member_profile_summary', 'Consultant en marketing digital');
        await page.locator('button:has-text("entrepreneurs")').first().click();
        await page.fill('#problems_helped_raw', 'SEO, stratégie de marque');
        await page.locator('button:has-text("Continuer")').click();

        await page.fill('#service_scope', 'Accompagnement sur mesure');
        await page.fill('#skillsInput', 'SEO, Marketing, Rédaction');
        await page.fill('#experience_context', '5 ans en agence');
        await page.locator('button:has-text("avis_rapide")').first().click();
        await page.locator('button:has-text("Continuer")').click();

        await page.locator('button:has-text("pas_urgence")').first().click();
        await page.locator('button:has-text("pas_travail_gratuit")').first().click();
        await page.locator('label:has(span:text("envoyer_demande_echange"))').first().click();
        await page.locator('label:has(input[value="chaleureux"])').first().click();
        await page.locator('button:has-text("Continuer")').click();

        await page.fill('input[placeholder*="Exemple"]', 'Aide stratégie');
        await page.locator('button:has-text("Ajouter")').first().click();
        await page.locator('button:has-text("Continuer")').click();

        await page.locator('button:has-text("Publier")').click();
        await expect(page.getByText('Profil publié')).toBeVisible();

        const userId = await page.evaluate(() => {
            const meta = document.querySelector('meta[name="user-id"]');
            return meta ? meta.getAttribute('content') : null;
        });

        const agentUrl = userId ? `/agent-ia/member/${userId}` : '/agent-ia/member/1';
        await page.goto(agentUrl);

        await expect(page.getByText('Agent IA de présentation')).toBeVisible();

        // Ask a question
        const textarea = page.locator('textarea[placeholder*="Que souhaitez-vous savoir"]');
        await expect(textarea).toBeVisible();
        await textarea.fill('Quelles sont ses compétences ?');

        await page.locator('button:has-text("Posez votre question")').click();

        // Wait for response to appear
        await expect(page.getByText('Réponse')).toBeVisible({ timeout: 10000 });
        await expect(page.locator('.prose')).toContainText('SEO');

        const errors = getConsoleErrors();
        expect(errors.filter(e => !e.message.includes('chrome-extension'))).toEqual([]);
    });
});
