// Offline harness: boots the real settings SPA in jsdom with a boot-data fixture and
// walks every tab / design section. Catches ReferenceErrors and broken wiring without
// WordPress or credentials. Run: node boot.test.js
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const root = path.resolve(__dirname, '../..');
const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/boot-main.json'), 'utf8'));
const scripts = ['design', 'features', 'plugins', 'navigation'].map((n) => 'assets/js/settings/' + n + '.js').concat(['assets/js/settings/main.js', 'assets/js/settings.js']);

const dom = new JSDOM('<!doctype html><html><body><div id="adminkit-app" class="adminkit-app" data-screen="main" aria-busy="true"></div></body></html>', {
  url: 'http://localhost/wp-admin/admin.php?page=adminkit',
  runScripts: 'outside-only',
  pretendToBeVisual: true,
});
const { window } = dom;
const errors = [];
window.addEventListener('error', (e) => errors.push(e.error || e.message));
window.matchMedia = window.matchMedia || (() => ({ matches: false, addEventListener() {}, removeEventListener() {} }));
window.MutationObserver = window.MutationObserver;
window.AdminKitData = fixture;
window.wp = { apiFetch: () => Promise.resolve({}) };
window.HTMLCanvasElement.prototype.getContext = () => ({ fillRect() {}, fillStyle: '', getImageData: () => ({ data: [0, 0, 0, 255] }) });

for (const file of scripts) {
  try { window.eval(fs.readFileSync(path.join(root, file), 'utf8')); } catch (e) { errors.push(file + ': ' + e.stack); }
}

const tick = () => new Promise((r) => setTimeout(r, 30));
(async () => {
const doc = window.document;
const assert = (cond, msg) => { if (!cond) { errors.push('ASSERT: ' + msg); } };
assert(!doc.getElementById('adminkit-app').hasAttribute('aria-busy'), 'app finished booting');
const tabs = [...doc.querySelectorAll('[role="tab"].ak-tabs button, .ak-tabs [role="tab"]')];
assert(tabs.length === 4, 'main tabs rendered (' + tabs.length + ')');
for (const tab of tabs) {
  try { tab.click(); await tick(); } catch (e) { errors.push('tab ' + tab.id + ': ' + e.stack); }
}
// Design system sections (Colors, Typography, Shapes & elevation, Plugins).
const designTab = doc.getElementById('ak-tab-brand');
if (designTab) { designTab.click(); await tick(); }
const sectionTabs = [...doc.querySelectorAll('.ak-foundations-nav [role="tab"]')];
assert(sectionTabs.length === 4, 'design sections rendered (' + sectionTabs.length + ')');
for (const tab of sectionTabs) {
  try { tab.click(); await tick(); } catch (e) { errors.push('section ' + tab.id + ': ' + e.stack); }
}
for (const id of ['ak-type-scale', 'ak-shape-radius']) {
  const input = doc.getElementById(id);
  assert(input, id + ' present');
  if (input) { input.value = '1.2'; input.dispatchEvent(new window.Event('input', { bubbles: true })); }
}
assert(doc.querySelectorAll('.ak-palette-theme input[type="color"]').length >= 22, 'palette shows 11 roles x 2 modes');
const save = doc.querySelector('.ak-savebar .ak-btn--primary');
assert(save && !save.disabled, 'edits mark the form dirty');

if (errors.length) {
  console.error('FAIL\n' + errors.map(String).join('\n'));
  process.exit(1);
}
console.log('OK: settings SPA boots in jsdom; ' + tabs.length + ' tabs, ' + sectionTabs.length + ' design sections, edits mark dirty.');

})();
