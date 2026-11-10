import { defineConfig } from '@playwright/test';
import os from 'node:os';
import path from 'node:path';

const root = process.env.ATICE_TEST_ROOT || path.join(os.tmpdir(), 'atice-browser-test');
process.env.ATICE_TEST_ROOT = root;

export default defineConfig({
  testDir: './tests/browser',
  timeout: 90_000,
  expect: { timeout: 20_000 },
  workers: 1,
  forbidOnly: !!process.env.CI,
  outputDir: path.join(root, 'results'),
  reporter: [['list'], ['html', { outputFolder: path.join(root, 'report'), open: 'never' }]],
  use: {
    baseURL: 'http://127.0.0.1:15173',
    channel: process.env.PLAYWRIGHT_CHANNEL || undefined,
    headless: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: [
    { command: 'php -S 127.0.0.1:18080 tests/Support/browser_server.php', cwd: '../at-ice-backend', url: 'http://127.0.0.1:18080/up', reuseExistingServer: false, timeout: 120_000, stderr: 'ignore' },
    { command: 'node node_modules/vite/bin/vite.js --config tests/browser/vite.config.mjs --configLoader runner', url: 'http://127.0.0.1:15173', reuseExistingServer: false, timeout: 120_000 },
  ],
});
