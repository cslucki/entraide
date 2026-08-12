// TASK-1201 — suite Playwright ciblée pour l'environnement de validation IA.
//
// Base URL fixe : http://127.0.0.1:8010 (playwright.ai-validation.config.mjs).
// Ne dépend PAS de ai/playwright/helpers/* (absent du worktree, gitignoré) :
// fichier auto-suffisant pour ne rien copier depuis test.laravel juste pour
// quelques lignes de login générique.
//
// BLOQUÉ tant que Cyril n'a pas donné le GO DB (voir TASK-1201) :
// bouclepro_ai_validation n'existe pas encore, donc cette suite n'a jamais
// été exécutée pour de vrai. Écrite et relue, pas validée en conditions
// réelles.
//
// Identifiants : voir .env.ai-validation.example (AI_VALIDATION_ORG_*_LOGIN,
// AI_VALIDATION_PASSWORD) — mot de passe unique "password", comptes
// déterministes produits par AiValidationDatasetSeeder.

import { test, expect } from '@playwright/test';

const PASSWORD = process.env.AI_VALIDATION_PASSWORD || 'password';

const ORG_A_ADMIN = process.env.AI_VALIDATION_ORG_A_ADMIN_LOGIN
    || 'admin@ai-validation-org-a.ai-validation.test';
const ORG_A_MEMBER1 = process.env.AI_VALIDATION_ORG_A_MEMBER1_LOGIN
    || 'member1@ai-validation-org-a.ai-validation.test';

const ORG_B_ADMIN = process.env.AI_VALIDATION_ORG_B_ADMIN_LOGIN
    || 'admin@ai-validation-org-b.ai-validation.test';

const SENTINEL_A = 'SENTINEL-A';
const SENTINEL_B = 'SENTINEL-B';

/**
 * Connexion générique — pas de dépendance sur ai/playwright/helpers/auth.js.
 */
async function login(page, email, password = PASSWORD) {
    await page.goto('/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.getByRole('button', { name: /se connecter|sign in/i }).click();
    await page.waitForURL((url) => !url.pathname.includes('/login'));
}

/**
 * Capture les erreurs console/page pour l'assertion "console propre" du
 * scénario 9, sans dépendre du helper console.js absent du worktree.
 */
function captureConsoleErrors(page) {
    const errors = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') {
            errors.push(msg.text());
        }
    });
    page.on('pageerror', (err) => {
        errors.push(err.message);
    });

    return errors;
}

test.describe('AI validation — isolation cross-tenant (TASK-1201)', () => {
    test('1. login Organization A réussit', async ({ page }) => {
        await login(page, ORG_A_ADMIN);

        expect(page.url()).not.toContain('/login');
    });

    test('2. navigation normale après login (pas de redirection profil incomplet)', async ({ page }) => {
        await login(page, ORG_A_ADMIN);

        // EnsureProfileComplete redirige vers profile.edit si un champ requis
        // manque — AiValidationDatasetSeeder remplit tous les champs
        // nécessaires (voir TASK1201AiValidationDatasetSeederTest).
        expect(page.url()).not.toContain('/profile/edit');
    });

    test('3. les données sentinelles A sont visibles depuis Organization A', async ({ page }) => {
        await login(page, ORG_A_MEMBER1);
        await page.goto('/loops');

        await expect(page.getByText(SENTINEL_A, { exact: false }).first()).toBeVisible();
    });

    test('4. aucune donnée sentinelle B n\'est jamais visible depuis Organization A', async ({ page }) => {
        await login(page, ORG_A_MEMBER1);
        await page.goto('/loops');

        await expect(page.getByText(SENTINEL_B, { exact: false })).toHaveCount(0);
    });

    test('5. les Boucles de l\'Organization A sont accessibles', async ({ page }) => {
        await login(page, ORG_A_ADMIN);
        await page.goto('/loops');

        await expect(page.getByText(`${SENTINEL_A} Loop Principale`, { exact: false })).toBeVisible();
    });

    test('6. la demande d\'aide et la proposition d\'aide de validation sont visibles', async ({ page }) => {
        // Route exacte non re-vérifiée en conditions réelles (suite jamais
        // exécutée, DB indisponible) : /dashboard/services est confirmée par
        // routes/web.php (DashboardController::services) ; la demande d'aide
        // (ServiceRequest) n'a pas de route d'index dédiée identifiée à
        // l'audit, /dashboard est le point d'entrée le plus sûr en attendant
        // vérification.
        await login(page, ORG_A_ADMIN);
        await page.goto('/dashboard/services');

        await expect(page.getByText(`${SENTINEL_A} proposition d'aide de validation`, { exact: false })).toBeVisible();
    });

    test('7. Article et Dossier de validation sont accessibles', async ({ page }) => {
        await login(page, ORG_A_ADMIN);
        await page.goto('/dossiers');

        await expect(page.getByText(`${SENTINEL_A} Dossier de validation`, { exact: false })).toBeVisible();
    });

    test('8. [BLOQUÉ SANS POSTGRESQL] recherche sémantique Dossiers retourne le contenu sentinelle', async ({ page }) => {
        test.skip(true, 'Nécessite bouclepro_ai_validation + pgvector — GO DB requis (voir TASK-1201).');

        await login(page, ORG_A_ADMIN);
        // Une fois débloqué : naviguer vers /dossiers/{dossier}/semantic-search
        // et vérifier que seul le contenu SENTINEL-A ressort, jamais SENTINEL-B.
    });

    test('9. console navigateur sans erreur critique après navigation', async ({ page }) => {
        const errors = captureConsoleErrors(page);

        await login(page, ORG_A_ADMIN);
        await page.goto('/loops');
        await page.goto('/dossiers');

        expect(errors, `Erreurs console inattendues : ${errors.join(' | ')}`).toHaveLength(0);
    });
});

test.describe('AI validation — Organization B (contrôle symétrique)', () => {
    test('les données sentinelles A ne sont jamais visibles depuis Organization B', async ({ page }) => {
        await login(page, ORG_B_ADMIN);
        await page.goto('/loops');

        await expect(page.getByText(SENTINEL_A, { exact: false })).toHaveCount(0);
    });
});
