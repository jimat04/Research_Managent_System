const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: '.',
  timeout: 45_000,
  expect: { timeout: 8_000 },
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  reporter: [
    ['list'],
    ['json', { outputFile: 'test-results/playwright-results.json' }],
  ],
  use: {
    baseURL: process.env.RMS_E2E_BASE_URL || 'http://localhost/rms/',
    headless: true,
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    // Keep screenshots and traces without requiring Playwright's optional
    // ffmpeg download in this isolated local setup.
    video: 'off',
    trace: 'off',
    launchOptions: {
      executablePath: process.env.RMS_CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe',
      args: ['--no-sandbox'],
    },
  },
});
