import { defineConfig } from '@playwright/test';

// Screenshots of the documentation (documentation/fr/captures/ and documentation/en/captures/), taken on the real
// application with the demonstration data of tests/e2e/seed.php (four users per language, three years of history):
// "captures" job of the CI, then "pages" job.
const port = process.env.APP_PORT ?? '8765';

export default defineConfig({
    testDir: 'tests/e2e/captures',
    outputDir: 'test-results/captures',
    workers: 1,
    timeout: 180_000,
    expect: { timeout: 15_000 },
    forbidOnly: Boolean(process.env.CI),
    retries: 0,
    reporter: [['list']],
    use: {
        baseURL: `http://localhost:${port}`,
        locale: 'fr-FR',
        // Steady pages: no micro-animation caught halfway by a check or a picture (app.css, Motion).
        reducedMotion: 'reduce',
        launchOptions: process.env.CHROMIUM_PATH
            ? { executablePath: process.env.CHROMIUM_PATH, args: ['--no-sandbox'] }
            : {},
    },
    webServer: [
        {
            command: 'sh ci/start-app.sh',
            env: { SEED_USERS: '4', SEED_ENGLISH_USERS: '4', SEED_YEARS: '3' },
            url: `http://127.0.0.1:${port}/connexion`,
            timeout: 180_000,
            reuseExistingServer: !process.env.CI,
            stdout: 'pipe',
        },
    ],
});
