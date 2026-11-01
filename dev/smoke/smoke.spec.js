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

test('public front end emits zero AdminKit markup for anonymous visitors', async ({ page }) => {
  const problems = watch(page);
  await page.goto('/');
  const html = await page.content();
  expect(html).not.toContain('adminkit');
  expect(problems).toEqual([]);
});

test('login screen loads without AdminKit errors', async ({ page }) => {
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

  test('dashboard carries the AdminKit skin, dark mode toggle and mobile 390px layout', async ({ page }) => {
    const problems = watch(page);
    await page.goto('/wp-admin/');
    await expect(page.locator('body.adminkit')).toBeVisible();

    const toggle = page.locator('#wp-admin-bar-ak-theme-toggle a');
    if (await toggle.count()) {
      const before = await page.evaluate(() => document.documentElement.getAttribute('data-adminkit-theme'));
      await toggle.click();
      const after = await page.evaluate(() => document.documentElement.getAttribute('data-adminkit-theme'));
      expect(after).not.toBe(before);
    }

    await page.setViewportSize({ width: 390, height: 844 });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    expect(problems).toEqual([]);
  });

  test('all 5 native AdminKit submenu pages boot cleanly without asset or console errors', async ({ page }) => {
    const problems = watch(page);
    const pages = [
      'adminkit',
      'adminkit-features',
      'adminkit-design',
      'adminkit-navigation',
      'adminkit-configuration',
    ];
    for (const slug of pages) {
      await page.goto('/wp-admin/admin.php?page=' + slug);
      await expect(page.locator('#adminkit-app')).toBeVisible();
      await expect(page.locator('#adminkit-app')).not.toHaveAttribute('aria-busy', 'true');
    }
    expect(problems).toEqual([]);
  });

  test('design page omits wp.media, exposes typography/shape tabs, and persists REST saves across reload', async ({ page }) => {
    const problems = watch(page);
    await page.goto('/wp-admin/admin.php?page=adminkit-design');
    await expect(page.locator('#adminkit-app')).not.toHaveAttribute('aria-busy', 'true');
    expect(await page.evaluate(() => typeof window.wp !== 'undefined' && !!window.wp.media)).toBe(false);

    await page.getByRole('tab', { name: /typograph/i }).first().click();
    await expect(page.locator('#ak-type-font')).toBeVisible();
    await expect(page.locator('#ak-type-scale')).toBeVisible();

    await page.getByRole('tab', { name: /shape|forme/i }).first().click();
    const radiusSelect = page.locator('#ak-shape-radius');
    await expect(radiusSelect).toBeVisible();

    const originalRadius = await radiusSelect.inputValue();
    const nextRadius = originalRadius === 'sharp' ? 'rounded' : 'sharp';
    await radiusSelect.selectOption(nextRadius);
    const saveBtn = page.locator('.ak-save-bar button.ak-btn--primary, .ak-actions button.ak-btn--primary').first();
    if (await saveBtn.isVisible()) {
      await Promise.all([
        page.waitForResponse((r) => r.url().includes('/adminkit/v1/settings') && r.request().method() === 'POST'),
        saveBtn.click(),
      ]);
      await page.reload();
      await page.getByRole('tab', { name: /shape|forme/i }).first().click();
      await expect(page.locator('#ak-shape-radius')).toHaveValue(nextRadius);

      // Restore original setting cleanly.
      await page.locator('#ak-shape-radius').selectOption(originalRadius);
      await Promise.all([
        page.waitForResponse((r) => r.url().includes('/adminkit/v1/settings') && r.request().method() === 'POST'),
        saveBtn.click(),
      ]);
    }
    expect(problems).toEqual([]);
  });

  test('RTL dir="rtl" renders without horizontal overflow on AdminKit pages', async ({ page }) => {
    const problems = watch(page);
    await page.goto('/wp-admin/admin.php?page=adminkit-features');
    await page.evaluate(() => { document.documentElement.setAttribute('dir', 'rtl'); document.body.classList.add('rtl'); });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    expect(problems).toEqual([]);
  });
});
