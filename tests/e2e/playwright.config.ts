import { defineConfig, devices } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const fixtureEnvPath = join(__dirname, 'fixtures', 'runtime-env.json');

function loadFixtureEnv(): Record<string, string> {
  if (!existsSync(fixtureEnvPath)) {
    return {};
  }
  return JSON.parse(readFileSync(fixtureEnvPath, 'utf8')) as Record<string, string>;
}

const fixtureEnv = loadFixtureEnv();

export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.ts',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  timeout: 120_000,
  expect: {
    timeout: 20_000,
  },
  use: {
    baseURL: process.env.MEL_E2E_BASE_URL ?? fixtureEnv.baseUrl ?? 'https://myeventlane.ddev.site',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  globalSetup: join(__dirname, 'global-setup.ts'),
  globalTeardown: join(__dirname, 'global-teardown.ts'),
});
