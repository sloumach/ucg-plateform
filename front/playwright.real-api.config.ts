import { defineConfig, devices } from '@playwright/test'

const api = process.env.UCG_BROWSER_API_URL
const front = process.env.UCG_BROWSER_FRONT_URL
const reportPrefix = process.env.UCG_BROWSER_ENGINE === 'pgsql' ? 'playwright-real-postgresql' : 'playwright-real'
if (!api || !front || !process.env.UCG_BROWSER_ROOT || !process.env.UCG_BROWSER_TOKEN) {
  throw new Error('Utiliser npm run test:e2e:real pour créer un environnement isolé.')
}

export default defineConfig({
  testDir: './tests/real-api',
  testMatch: '**/*.spec.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  forbidOnly: Boolean(process.env.CI),
  timeout: 60_000,
  outputDir: `reports/${reportPrefix}-results`,
  reporter: [['list'], ['junit', { outputFile: `reports/${reportPrefix}.xml` }], ['html', { open: 'never', outputFolder: `reports/${reportPrefix}-html` }]],
  use: {
    baseURL: front,
    channel: process.env.PLAYWRIGHT_BROWSER_CHANNEL || undefined,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    serviceWorkers: 'block',
  },
  projects: [{ name: 'real-api-chromium', use: { ...devices['Desktop Chrome'] } }],
})
