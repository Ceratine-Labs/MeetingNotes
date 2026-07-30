// @ts-check
const { defineConfig, devices } = require('@playwright/test');
const path = require('path');

const APP_ROOT = path.resolve(__dirname, '..');

/**
 * The suite gets its own database so it can never eat a developer's dev
 * data. The webServer command prepares it (fresh migrate, seed master,
 * demo workspace with the fake LLM driver) before serving, so tests only
 * start against a fully seeded app; the health-check URL is a static
 * file so readiness never depends on the database.
 */
const appEnv = {
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: path.join(APP_ROOT, 'database', 'e2e.sqlite'),
    QUEUE_CONNECTION: 'sync', // Generation completes within the request.
    MAIL_MAILER: 'log',
    APP_ENV: 'local',
};

module.exports = defineConfig({
    testDir: './tests',
    // One worker: specs share the seeded database and some mutate it
    // (ticking action items). Determinism beats a few saved seconds.
    workers: 1,
    fullyParallel: false,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8199',
        trace: 'retain-on-failure',
        // Reuse a machine-provided Chromium instead of a downloaded one.
        ...(process.env.CHROMIUM_PATH
            ? { launchOptions: { executablePath: process.env.CHROMIUM_PATH } }
            : {}),
    },
    projects: [
        // Logs in once and saves the session; both real projects reuse it so
        // the login throttle (a production behaviour worth keeping) is never
        // tripped by the suite itself.
        {
            name: 'setup',
            testMatch: /auth\.setup\.js/,
        },
        {
            name: 'desktop',
            use: { ...devices['Desktop Chrome'], storageState: '.auth/demo.json' },
            dependencies: ['setup'],
            testIgnore: /mobile\.spec\.js|auth\.setup\.js/,
        },
        {
            name: 'mobile',
            use: { ...devices['Pixel 7'], storageState: '.auth/demo.json' },
            dependencies: ['setup'],
            testMatch: /mobile\.spec\.js/,
        },
    ],
    webServer: {
        command: [
            'php -r \'touch(getenv("DB_DATABASE"));\'',
            'php artisan migrate:fresh --force',
            'php artisan seed:master',
            'php artisan demo:seed --fake-llm',
            'php artisan serve --host=127.0.0.1 --port=8199',
        ].join(' && '),
        cwd: APP_ROOT,
        url: 'http://127.0.0.1:8199/offline.html',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        env: { ...process.env, ...appEnv },
    },
});
