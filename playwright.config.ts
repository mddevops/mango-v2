import { defineConfig, devices } from '@playwright/test';

/**
 * Acceptance scenario against a running app (see docs/IMPLEMENTATION_STATUS.md).
 * PLAYWRIGHT_CHROMIUM lets OSPanel machines reuse an already installed Chromium.
 */
const executablePath = process.env.PLAYWRIGHT_CHROMIUM || undefined;

export default defineConfig({
    testDir: 'tests/e2e',
    workers: 1,
    fullyParallel: false,
    retries: 0,
    reporter: 'list',
    outputDir: 'storage/e2e/results',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8123',
        testIdAttribute: 'data-test',
        locale: 'ru-RU',
        trace: 'off',
        video: 'off',
        screenshot: 'only-on-failure',
        launchOptions: { executablePath },
    },
    projects: [
        {
            name: 'desktop',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
            },
        },
        {
            name: 'tablet',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 820, height: 1180 },
                hasTouch: true,
            },
        },
    ],
});
