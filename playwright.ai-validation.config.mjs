import { defineConfig } from '@playwright/test';

// TASK-1201 — configuration Playwright DÉDIÉE à l'environnement de
// validation IA. Fichier séparé de playwright.config.mjs (jamais modifié) :
// baseURL fixe sur 127.0.0.1:8010, jamais test.laravel, jamais de fallback.
//
// Usage : npx playwright test --config=playwright.ai-validation.config.mjs
//
// Prérequis (non réunis tant que Cyril n'a pas donné le GO DB) :
//   1. bouclepro_ai_validation créée + ensemencée (ai-validation:reset)
//   2. ./ai/scripts/ai-validation-serve.sh démarré sur 127.0.0.1:8010
export default defineConfig({
    testDir: './tests/e2e-ai-validation',
    timeout: 30000,
    retries: 0,
    use: {
        baseURL: 'http://127.0.0.1:8010',
        headless: true,
        viewport: { width: 1280, height: 720 },
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
});
