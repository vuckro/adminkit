// Offline harness: boots the real settings SPA in jsdom with a boot-data fixture and
// walks every tab / design section. Catches ReferenceErrors and broken wiring without
// WordPress or credentials. Run: node boot.test.js
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const root = path.resolve(__dirname, '../..');
const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/boot-main.json'), 'utf8'));
const scripts = ['design-colors', 'design', 'features', 'plugins', 'navigation-controls', 'navigation-menu-model', 'navigation-menu', 'navigation-toolbar', 'navigation'].map((n) => 'assets/js/settings/' + n + '.js').concat(['assets/js/settings/main.js', 'assets/js/settings.js']);

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
fixture.designSystem = Object.assign({}, fixture.designSystem, { type_scale: 1.13, radius_scale: 1.37 });
fixture.features.push({key:'admin_theme_enabled',label:'Administration theme',group:'Appearance',value:true,default:true,bulk:false,options:[]});
window.AdminKitData = fixture;
const requests = [];
window.wp = { apiFetch: request => { requests.push(request); return Promise.resolve({}); } };
window.HTMLCanvasElement.prototype.getContext = () => ({ fillRect() {}, fillStyle: '', getImageData: () => ({ data: [0, 0, 0, 255] }) });

for (const file of scripts) {
  try { window.eval(fs.readFileSync(path.join(root, file), 'utf8')); } catch (e) { errors.push(file + ': ' + e.stack); }
}

const tick = () => new Promise((r) => setTimeout(r, 30));
(async () => {
const doc = window.document;
const assert = (cond, msg) => { if (!cond) { errors.push('ASSERT: ' + msg); } };
assert(!doc.getElementById('adminkit-app').hasAttribute('aria-busy'), 'app finished booting');
doc.getElementById('ak-tab-brand').click(); await tick();
assert(!doc.getElementById('ak-type-scale'), 'typography editor is absent');
assert(!doc.getElementById('ak-shape-radius'), 'shape editor is absent');
const tabs = [...doc.querySelectorAll('[role="tab"].ak-tabs button, .ak-tabs [role="tab"]')];
assert(tabs.length === 5, 'main tabs rendered (' + tabs.length + ')');
let sectionScrolls = 0; doc.getElementById('adminkit-app').scrollIntoView = () => { sectionScrolls++; };
const pagePicker = doc.getElementById('ak-page-select');
assert(pagePicker && pagePicker.options.length === 5, 'mobile page picker exposes all SPA sections');
pagePicker.value = 'configuration'; pagePicker.dispatchEvent(new window.Event('change', { bubbles: true })); await tick();
assert(doc.getElementById('ak-tab-configuration').getAttribute('aria-selected') === 'true', 'mobile page selection uses shared routing');
window.location.hash = 'features'; await tick();
assert(pagePicker.value === 'features', 'hash navigation synchronizes the mobile picker');
assert(doc.querySelector('.ak-savebar').hidden, 'SPA navigation keeps the draft clean');
doc.querySelector('.ak-tabs').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'End', bubbles: true })); await tick();
assert(pagePicker.value === 'configuration', 'End selects the last section and synchronizes mobile navigation');
doc.querySelector('.ak-tabs').dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Home', bubbles: true })); await tick();
assert(pagePicker.value === 'overview', 'Home returns to overview');
assert(sectionScrolls >= 4, 'section changes restore the workspace reading position');


for (const tab of tabs) {
  try { tab.click(); await tick(); } catch (e) { errors.push('tab ' + tab.id + ': ' + e.stack); }
}
// Navigation sub-tabs (Menu, Toolbar).
const navSectionTabs = [...doc.querySelectorAll('#ak-panel-navigation .ak-foundations-nav [role="tab"]')];
assert(navSectionTabs.length === 2, 'navigation sections rendered (' + navSectionTabs.length + ')');
for (const tab of navSectionTabs) {
  try { tab.click(); await tick(); } catch (e) { errors.push('nav section ' + tab.id + ': ' + e.stack); }
}
// Extensions are directly accessible, without foundation sub-tabs.
doc.getElementById('ak-tab-brand').click(); await tick();
assert(!doc.querySelector('#ak-panel-brand .ak-foundations-nav'), 'extension controls have no foundation navigation');
assert(!doc.getElementById('ak-type-font') && !doc.getElementById('ak-google-font-preview'), 'advanced appearance controls and remote previews are absent');
doc.getElementById('ak-tab-features').click(); await tick();
const theme = [...doc.querySelectorAll('.ak-features-item')].find(n => n.textContent.includes('Administration theme'));
theme.click();
assert(doc.querySelectorAll('.ak-theme-colors .ak-theme-palette input[type="color"]').length === 4, 'only accent and text on accent are editable for both modes');
doc.querySelector('.ak-feature-dialog-head button').click();
// Nested controls mount from saved values and use the actual main save payload.
doc.getElementById('ak-tab-features').click();
const clean = [...doc.querySelectorAll('.ak-features-item')].find(n => n.textContent.includes('Clean interface'));
clean.click();
const nested = doc.getElementById(clean.getAttribute('aria-controls')).querySelectorAll('.ak-feature-options input');
assert(nested.length === 2 && nested[0].checked && nested[1].checked, 'nested defaults mount without losing values');
nested[0].checked = false; nested[0].dispatchEvent(new window.Event('change', { bubbles:true }));
const save = doc.querySelector('.ak-savebar .ak-btn--primary');
assert(save && !save.disabled, 'edits mark the form dirty');
pagePicker.value = 'configuration'; pagePicker.dispatchEvent(new window.Event('change', { bubbles: true })); await tick();
assert(!save.disabled && !doc.querySelector('.ak-savebar').hidden, 'switching sections preserves the pending draft and save action');
save.click(); await tick();
const posted = requests.find(r => r.method === 'POST' && r.data && r.data.values);
assert(posted && posted.data.values.clean_chrome_hide_footer === false && posted.data.values.clean_chrome_hide_help === true, 'actual main save includes independent nested choices');
assert(doc.getElementById(clean.getAttribute('aria-controls')).querySelector('.ak-feature-pending').hidden, 'actual host success clears the feature pending badge');

if (errors.length) {
  console.error('FAIL\n' + errors.map(String).join('\n'));
  process.exit(1);
}
dom.window.close();
console.log('OK: settings SPA boots in jsdom; ' + tabs.length + ' tabs, internal foundations preserved, edits mark dirty.');

})();
