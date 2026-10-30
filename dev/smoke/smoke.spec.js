// Run: AK_USER=<admin> AK_PASS=<password> npm test   (AK_URL defaults to http://localhost:10013)
// Credentials come from the environment only — never commit or log them.
const { test, expect } = require('@playwright/test');

const user = process.env.AK_USER;
const pass = process.env.AK_PASS;

function watch(page) {
  const problems = [];
  page.on('pageerror', (e) => problems.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') problems.push('console: ' + m.text()); });
  page.on('response', (r) => {
    const url = r.url();
    if (r.status() >= 400 && /\/wp-content\/plugins\/adminkit\//.test(url)) problems.push(r.status() + ' ' + url);
  });
  return problems;
}

test('login screen is branded and loads without AdminKit errors', async ({ page }) => {
  const problems = watch(page);
  await page.goto('/wp-login.php');
  await expect(page.locator('body.login')).toBeVisible();
  expect(problems).toEqual([]);
});

test.describe('wp-admin', () => {
  test.skip(!user || !pass, 'Set AK_USER and AK_PASS to run the authenticated checks.');

  test.beforeEach(async ({ page }) => {
    await page.goto('/wp-login.php');
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
  });

  test('dashboard carries the AdminKit skin and no asset errors', async ({ page }) => {
    const problems = watch(page);
    await page.goto('/wp-admin/');
    await expect(page.locator('body.adminkit')).toBeVisible();
    expect(problems).toEqual([]);
  });

  test('settings app boots, does not load wp.media, and edits the design system', async ({ page }) => {
    const problems = watch(page);
    await page.goto('/wp-admin/admin.php?page=adminkit');
    await expect(page.locator('#adminkit-app')).not.toHaveAttribute('aria-busy', 'true');
    expect(await page.evaluate(() => typeof window.wp !== 'undefined' && !!window.wp.media)).toBe(false);
    await page.getByRole('tab', { name: /typograph/i }).first().click();
    await expect(page.locator('#ak-type-scale')).toBeVisible();
    await page.getByRole('tab', { name: /spac|espac/i }).first().click();
    await expect(page.locator('#ak-space-scale')).toBeVisible();
    expect(problems).toEqual([]);
  });

  test('dark mode toggle flips the theme attribute', async ({ page }) => {
    await page.goto('/wp-admin/');
    const toggle = page.locator('#wp-admin-bar-ak-theme-toggle a');
    test.skip(!(await toggle.count()), 'Dark mode feature is off.');
    const before = await page.evaluate(() => document.documentElement.getAttribute('data-adminkit-theme'));
    await toggle.click();
    const after = await page.evaluate(() => document.documentElement.getAttribute('data-adminkit-theme'));
    expect(after).not.toBe(before);
  });
});
