import { defineConfig } from '@playwright/test';

const port = process.env.APP_PORT ?? '8765';

export default defineConfig({
    testDir: 'tests/e2e',
    // Screenshots of the documentation: separate configuration (playwright.captures.config.mjs).
    testIgnore: ['**/captures/**'],
    outputDir: 'test-results',
    // A single browser at a time: each flow has its own users, and Argon2id is expensive.
    workers: 1,
    timeout: 120_000,
    expect: { timeout: 15_000 },
    forbidOnly: Boolean(process.env.CI),
    retries: 0,
    reporter: [
        ['list'],
        ['junit', { outputFile: 'test-results/junit.xml' }],
        ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ],
    use: {
        baseURL: `http://localhost:${port}`,
        locale: 'fr-FR',
        // Steady pages: no micro-animation caught halfway by a check or a picture (app.css, Motion).
        reducedMotion: 'reduce',
        // Mobile first: the project's reference width.
        viewport: { width: 390, height: 844 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        launchOptions: process.env.CHROMIUM_PATH
            ? { executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox'] }
            : {},
    },
    webServer: [
        {
            command: 'sh ci/start-app.sh',
            url: `http://127.0.0.1:${port}/connexion`,
            timeout: 180_000,
            reuseExistingServer: !process.env.CI,
            stdout: 'pipe',
        },
    ],
});
