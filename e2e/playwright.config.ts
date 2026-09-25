import { defineConfig, devices } from '@playwright/test';
import { env } from './src/config/env';

const isCI = !!process.env.CI;

export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: isCI,
  // Retry hanya di CI. Test yang lulus setelah retry ditandai "flaky"
  // di report — itu sinyal untuk diperbaiki, bukan untuk disembunyikan.
  retries: isCI ? 1 : 0,
  workers: isCI ? 2 : undefined,
  timeout: 30_000,
  expect: { timeout: 5_000 },

  // Allure: hasil UI digabung dengan hasil API (Newman) menjadi satu dashboard.
  // Label epic "UI" membedakannya dari test API (epic "API").
  reporter: [
    ['list'],
    // Di CI tiap shard menulis blob report yang nanti digabung jadi satu HTML report.
    ...(isCI ? ([['blob'], ['github']] as const) : ([['html', { open: 'on-failure' }]] as const)),
    ['allure-playwright', { resultsDir: 'allure-results', globalLabels: { epic: 'UI' } }],
  ],

  use: {
    baseURL: env.baseURL,
    locale: 'id-ID',
    timezoneId: 'Asia/Jakarta',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    // Aktifkan jika ingin menjalankan cross-browser:
    // { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
  ],
});
