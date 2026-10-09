import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: process.env.CI
    ? [
        ['line'],
        ['junit', { outputFile: 'reports/playwright.xml' }],
        ['html', { open: 'never', outputFolder: 'reports/playwright-html' }],
      ]
    : [['list'], ['html', { open: 'never', outputFolder: 'reports/playwright-html' }]],
  outputDir: 'reports/playwright-results',
  use: {
    baseURL: 'http://localhost:5173',
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  webServer: [
    {
      command: 'php artisan serve --host=127.0.0.1 --port=8000',
      cwd: '../back',
      env: {
        ...process.env,
        APP_ENV: 'testing',
        CACHE_STORE: 'array',
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: ':memory:',
        SESSION_DRIVER: 'array',
      },
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
      url: 'http://localhost:8000/api/v1/system/status',
    },
    {
      command: 'npm run dev -- --host 127.0.0.1 --port 5173 --strictPort',
      cwd: '.',
      reuseExistingServer: !process.env.CI,
      timeout: 120_000,
      url: 'http://localhost:5173',
    },
  ],
})
