// Run: AK_USER=<admin> AK_PASS=<password> npm test   (AK_URL defaults to http://localhost:10013)
// Credentials come from the environment only — never commit or log them.
const { test, expect } = require('@playwright/test');
const path = require('node:path');

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

test('notification center works with only native toolbar and its own component assets', async ({ page }) => {
  await page.goto('/wp-login.php');
  await page.setContent(`<html><body class="wp-admin">
    <div id="wpadminbar"><div id="wp-toolbar"><ul id="wp-admin-bar-top-secondary" class="ab-top-menu ab-top-secondary">
      <li id="wp-admin-bar-ak-notifications"><a class="ab-item" href="#notifications"><span class="ak-nc-bell"><svg class="ak-theme-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/></svg><span class="ak-nc-badge" hidden></span></span></a></li>
      <li id="wp-admin-bar-my-account"><a class="ab-item" href="#account">Account</a></li>
    </ul></div></div>
    <div id="wpbody"><div id="wpbody-content"><div class="wrap"><div id="adminkit-nc-origin">
      <div id="fixture-warning" class="notice notice-warning"><p>Warning to group</p></div>
      <div id="fixture-success" class="notice notice-success"><p>Saved successfully</p></div>
    </div></div></div></div>
  </body></html>`);
  await page.addStyleTag({ url: new URL('/wp-includes/css/admin-bar.min.css', page.url()).href });
  const root = path.resolve(__dirname, '../..');
  for (const file of ['assets/css/component-tokens.css', 'assets/css/wp-core/notification-center.css']) {
    await page.addStyleTag({ path: path.join(root, file) });
  }
  await page.evaluate(() => {
    window.AdminKitNotificationCenter = {
      nodeId:'ak-notifications', pendingClass:'ak-nc-pending', activeClass:'ak-nc-active',
      title:'Notifications', subtitle:'Grouped notices', close:'Close', openLabel:'Notifications',
      empty:'All clear', emptyDesc:'Nothing to review', openCountSingular:'%s notification', openCountPlural:'%s notifications', allow:[], deny:[]
    };
  });
  await page.addScriptTag({ path: path.join(root, 'assets/js/wp-core/notification-center.js') });
  const bell = page.locator('#wp-admin-bar-ak-notifications > .ab-item');
  const icon = bell.locator('svg');
  const bounds = await icon.boundingBox();
  expect(bounds.width).toBe(18);
  expect(bounds.height).toBe(18);
  await expect(page.locator('.ak-nc-list #fixture-warning')).toHaveCount(1);
  await expect(page.locator('#adminkit-nc-origin #fixture-success')).toHaveCount(1);
  await bell.hover();
  const bellHover = await bell.evaluate(n => getComputedStyle(n).backgroundColor);
  const account = page.locator('#wp-admin-bar-my-account > .ab-item');
  await account.hover();
  expect(bellHover).toBe(await account.evaluate(n => getComputedStyle(n).backgroundColor));
  await bell.click();
  await expect(page.getByRole('dialog', { name:'Notifications' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(bell).toBeFocused();
  await page.setViewportSize({ width:390, height:844 });
  const mobileBounds = await icon.boundingBox();
  expect(mobileBounds.width).toBe(22);
  expect(mobileBounds.height).toBe(22);
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
    const nextRadius = originalRadius === '0' ? '1' : '0';
    await radiusSelect.selectOption(nextRadius);
    const saveBtn = page.locator('.ak-savebar button.ak-btn--primary, .ak-save-bar button.ak-btn--primary, .ak-actions button.ak-btn--primary').first();
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

  test('RTL dir="rtl" and keyboard accessibility work without layout overflow or focus traps', async ({ page }) => {
    const problems = watch(page);
    await page.goto('/wp-admin/admin.php?page=adminkit-features');
    await page.evaluate(() => {
      document.documentElement.setAttribute('lang', 'ar');
      document.documentElement.setAttribute('dir', 'rtl');
      document.body.classList.add('rtl');
    });
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(2);
    const cornerMirrored = await page.evaluate(() => {
      const bg = window.getComputedStyle(document.getElementById('wpwrap'), '::after').backgroundImage;
      return bg.includes('0% 100%') || bg.includes('bottom left') || bg.includes('left bottom');
    });
    expect(cornerMirrored).toBe(true);

    await page.goto('/wp-admin/profile.php');
    let confirmTriggered = false;
    page.on('dialog', (d) => {
      confirmTriggered = true;
      d.dismiss();
    });
    const loginInput = page.locator('#user_login');
    if (await loginInput.count()) {
      await loginInput.focus();
      expect(confirmTriggered).toBe(false);
    }
    expect(problems).toEqual([]);
  });
});


// Explicit opt-in: these fixtures intentionally change site settings and accounts.
test('isolated feature dependency matrix', async ({ page }) => {
  test.skip(process.env.AK_DISPOSABLE_AUDIT !== '1' || !process.env.AK_AUDIT_URL || !user || !pass, 'Requires AK_DISPOSABLE_AUDIT=1, AK_AUDIT_URL and disposable credentials.');
  test.setTimeout(480000);
  const target = new URL(process.env.AK_AUDIT_URL);
  expect(['localhost', '127.0.0.1', '[::1]']).toContain(target.hostname);
  expect(target.port).not.toBe('10013');
  const audit = await require('./dependency-audit')(page, {baseURL:target.origin, username:user, password:pass});
  expect(audit.errors).toEqual([]);
  expect(audit.results).toHaveLength(16);
});
