const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: '.',
  testMatch: '*.spec.js',
  timeout: 60000,
  retries: 0,
  reporter: 'list',
  use: { baseURL: process.env.AK_URL || 'http://localhost:10013', headless: true, ignoreHTTPSErrors: true },
});
