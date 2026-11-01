// Exercise notification-center.js: notice grouping, <style>/<script> exclusion, inline feedback preservation and drawer focus.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/wp-core/notification-center.js'), 'utf8');

const html = `<!doctype html><html class="ak-nc-pending"><body class="wp-admin">
<div id="wpadminbar"><ul id="wp-admin-bar-top-secondary">
  <li id="wp-admin-bar-ak-notifications"><a class="ab-item" href="#notifications">Bell<span class="ak-nc-badge" hidden></span></a></li>
</ul></div>
<div id="wpbody"><div id="wpbody-content"><div class="wrap">
  <h1>Dashboard</h1>
  <div id="adminkit-nc-origin">
    <style>.plugin-banner { color: inherit; }</style>
    <script>window.__bannerInit = true;</script>
    <div class="notice notice-warning"><p>License expiring soon</p></div>
    <div class="akismet-alert"><p>Activate spam protection</p></div>
    <div class="notice notice-success"><p>Settings saved.</p></div>
    <div class="notice notice-error settings-error"><p>Invalid email address.</p></div>
  </div>
  <table class="form-table"><tbody><tr><td>
    <div class="notice notice-info"><p>Contextual field note</p></div>
  </td></tr></tbody></table>
</div></div></div></body></html>`;

const dom = new JSDOM(html, { url: 'http://localhost/wp-admin/', runScripts: 'outside-only' });
const w = dom.window;
w.AdminKitNotificationCenter = {
  nodeId: 'ak-notifications',
  pendingClass: 'ak-nc-pending',
  activeClass: 'ak-nc-active',
  title: 'Notifications',
  subtitle: 'Grouped alerts',
  openLabel: 'Notifications',
  openCountSingular: '%s notification',
  openCountPlural: '%s notifications',
  close: 'Close',
  empty: 'All clear',
  emptyDesc: 'No notifications',
  allow: [],
  deny: [],
};

(async () => {
  w.eval(source);
  await new Promise((resolve) => setTimeout(resolve, 10));

  assert.equal(w.document.documentElement.classList.contains('ak-nc-pending'), false);
  assert.equal(w.document.documentElement.classList.contains('ak-nc-active'), true);

  const badge = w.document.querySelector('#wp-admin-bar-ak-notifications .ak-nc-badge');
  const drawerList = w.document.querySelector('.ak-nc-list');
  assert.equal(badge.hidden, false);
  assert.equal(badge.textContent, '2', 'Only the 2 non-contextual/non-feedback alerts belong in the drawer');
  assert.equal(drawerList.children.length, 2);

  const origin = w.document.getElementById('adminkit-nc-origin');
  assert.ok(origin.querySelector('style'), '<style> tag must remain in #adminkit-nc-origin');
  assert.ok(origin.querySelector('script'), '<script> tag must remain in #adminkit-nc-origin');
  assert.ok(origin.querySelector('.notice-success'), '.notice-success must remain inline');
  assert.ok(origin.querySelector('.settings-error'), '.settings-error must remain inline');
  assert.ok(w.document.querySelector('.form-table .notice-info'), 'Contextual form-table notice must remain inline');

  const bell = w.document.querySelector('#wp-admin-bar-ak-notifications a');
  assert.equal(bell.getAttribute('aria-label'), '2 notifications');
  const wpbody = w.document.getElementById('wpbody');
  bell.focus();
  bell.click();
  assert.equal(bell.getAttribute('aria-expanded'), 'true');
  assert.equal(w.document.querySelector('.ak-nc-drawer').classList.contains('is-open'), true);
  assert.equal(wpbody.inert, true, 'Background #wpbody is marked inert while drawer is open');

  w.document.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
  assert.equal(bell.getAttribute('aria-expanded'), 'false');
  assert.equal(wpbody.inert, false, 'Background #wpbody inert is cleared on close');

  const missing = new JSDOM(html.replace(/<li id="wp-admin-bar-ak-notifications">.*?<\/li>/, ''), { url:'http://localhost/wp-admin/', runScripts:'outside-only' });
  missing.window.AdminKitNotificationCenter = w.AdminKitNotificationCenter;
  missing.window.eval(source);
  await new Promise(resolve => setTimeout(resolve, 10));
  const missingDoc = missing.window.document;
  assert.equal(missingDoc.querySelector('.ak-nc-drawer'), null, 'Do not create an unreachable drawer without a toolbar launcher');
  assert.equal(missingDoc.querySelectorAll('#adminkit-nc-origin .notice-warning').length, 1, 'Missing launcher must keep warnings inline');
  assert.equal(missingDoc.documentElement.classList.contains('ak-nc-pending'), false, 'Missing launcher must reveal native notices');
  assert.equal(missingDoc.documentElement.classList.contains('ak-nc-active'), false);
  missing.window.close();
  w.close();
  console.log('OK: notification-center groups alerts, skips <style>/<script>, preserves inline feedback and toggles cleanly.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
