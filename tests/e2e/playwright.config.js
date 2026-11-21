const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './specs',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: process.env.CI ? 1 : undefined,
    timeout: 120_000,
    globalTimeout: 30 * 60 * 1000,
    reporter: process.env.CI
        ? [['github'], ['html', { outputFolder: 'playwright-report', open: 'never' }]]
        : 'list',
    outputDir: 'test-results',
    projects: [
        {
            name: 'chromium',
            testIgnore: '**/product-checkbox-touch.spec.js',
            use: { browserName: 'chromium' },
        },
        {
            name: 'webkit-touch',
            testMatch: '**/product-checkbox-touch.spec.js',
            use: {
                browserName: 'webkit',
                hasTouch: true,
                viewport: { width: 834, height: 1194 },
            },
        },
    ],
    use: {
        baseURL: process.env.PLAYWRIGHT_TEST_BASE_URL || 'http://127.0.0.1:8000',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
});
