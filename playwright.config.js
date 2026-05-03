import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    retries: 0,
    reporter: 'list',
    use: {
        baseURL: 'https://test.laravel',
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        video: 'off',
    },
    outputDir: 'test-results',
});
