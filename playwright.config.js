import { defineConfig, devices } from '@playwright/test';
import dotenv from 'dotenv';                                                       
import path from 'path';    
export default defineConfig({
    testDir: './tests/e2e',

    timeout: 60_000,

    retries: 1,

    reporter: [
        ['list'],
        ['html', { outputFolder: 'ai/playwright/reports/html', open: 'never' }],
        ['json', { outputFile: 'ai/playwright/reports/results.json' }],
    ],

    outputDir: 'ai/playwright/test-results',

    use: {
        baseURL: 'https://test.laravel',

        ignoreHTTPSErrors: true,

        headless: true,

        // Enable media generation for both success and failure                   
        // This validates screenshots, videos, and traces are generated  

        screenshot: 'on',
        trace: 'on',
        video: 'on',

        actionTimeout: 10_000,
        navigationTimeout: 30_000,
    },

    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'firefox',
            use: { ...devices['Desktop Firefox'] },
        },
        {
            name: 'webkit',
            use: { ...devices['Desktop Safari'] },
        },
        {
            name: 'mobile-chrome',
            use: { ...devices['Pixel 5'] },
        },
    ],
});
