// @ts-check
const fs = require('fs');
const os = require('os');
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

const DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'kids-point-e2e-'));
const PORT = 8139;
const BASE_URL = `http://127.0.0.1:${PORT}/`;

// 他のプロセスからも参照できるように公開しておく（globalTeardown用）
process.env.KIDS_POINT_E2E_DATA_DIR = DATA_DIR;

module.exports = defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  globalTeardown: require.resolve('./tests/e2e/global-teardown.js'),
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          executablePath: '/opt/pw-browsers/chromium',
        },
      },
    },
  ],
  webServer: {
    command: 'bash tests/e2e/start-server.sh',
    url: BASE_URL,
    reuseExistingServer: false,
    timeout: 30000,
    env: {
      KIDS_POINT_DATA_DIR: DATA_DIR,
      PORT: String(PORT),
    },
  },
});
