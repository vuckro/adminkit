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
assert(doc.getElementById('ak-type-scale').value === '1.13', 'valid non-preset typography scale survives mounting');
assert(doc.getElementById('ak-shape-radius').value === '1.37', 'valid non-preset radius scale survives mounting');
const tabs = [...doc.querySelectorAll('[role="tab"].ak-tabs button, .ak-tabs [role="tab"]')];
assert(tabs.length === 5, 'main tabs rendered (' + tabs.length + ')');
for (const tab of tabs) {
  try { tab.click(); await tick(); } catch (e) { errors.push('tab ' + tab.id + ': ' + e.stack); }
}
// Navigation sub-tabs (Menu, Toolbar).
const navSectionTabs = [...doc.querySelectorAll('#ak-panel-navigation .ak-foundations-nav [role="tab"]')];
assert(navSectionTabs.length === 2, 'navigation sections rendered (' + navSectionTabs.length + ')');
for (const tab of navSectionTabs) {
  try { tab.click(); await tick(); } catch (e) { errors.push('nav section ' + tab.id + ': ' + e.stack); }
}
// Design system sections (Colors, Typography, Shapes & elevation, Plugins).
const designTab = doc.getElementById('ak-tab-brand');
if (designTab) { designTab.click(); await tick(); }
const sectionTabs = [...doc.querySelectorAll('#ak-panel-brand .ak-foundations-nav [role="tab"]')];
assert(sectionTabs.length === 4, 'design sections rendered (' + sectionTabs.length + ')');
const sectionPicker = doc.querySelector('#ak-panel-brand .ak-section-picker select');
assert(sectionPicker && sectionPicker.options.length === 4, 'native mobile picker exposes all foundation sections');
sectionPicker.value = sectionTabs[1].id; sectionPicker.dispatchEvent(new window.Event('change', {bubbles:true})); await tick();
assert(sectionTabs[1].getAttribute('aria-selected') === 'true' && sectionPicker.value === sectionTabs[1].id, 'mobile selection uses the original pane routing');
assert(doc.querySelector('.ak-savebar').hidden, 'section selection alone does not dirty settings');
for (const tab of sectionTabs) {
  try { tab.click(); await tick(); } catch (e) { errors.push('section ' + tab.id + ': ' + e.stack); }
}
for (const [id, val] of [['ak-type-scale', '1.1'], ['ak-shape-radius', '1.25']]) {
  const input = doc.getElementById(id);
  assert(input, id + ' present');
  if (input) { input.value = val; input.dispatchEvent(new window.Event('change', { bubbles: true })); }
}
// GDPR: Google Fonts <option>s stay disabled and no fonts.googleapis.com <link> is injected unless opted in.
const fontSel = doc.getElementById('ak-type-font');
const remoteToggle = doc.getElementById('ak-type-remote-fonts');
assert(fontSel && remoteToggle && !remoteToggle.checked, 'remote Google Fonts opt-in toggle defaults to off');
assert(fontSel.querySelector('option[value="inter"]').disabled === true, 'Google Font option disabled while opt-in is off');
assert(!doc.getElementById('ak-google-font-preview'), 'no Google Fonts stylesheet injected while opt-in is off');
remoteToggle.checked = true;
remoteToggle.dispatchEvent(new window.Event('change', { bubbles: true }));
assert(fontSel.querySelector('option[value="inter"]').disabled === false, 'Google Font option enabled after opt-in');
fontSel.value = 'inter';
fontSel.dispatchEvent(new window.Event('change', { bubbles: true }));
assert(!!doc.getElementById('ak-google-font-preview'), 'Google Fonts preview link injected only after explicit opt-in');
remoteToggle.checked = false;
remoteToggle.dispatchEvent(new window.Event('change', { bubbles: true }));
assert(!doc.getElementById('ak-google-font-preview') && fontSel.value === 'geist', 'disabling opt-in removes Google Fonts link and resets to Geist');
assert(doc.querySelectorAll('.ak-palette-theme input[type="color"]').length >= 22, 'palette shows 11 roles x 2 modes');
// Nested controls mount from saved values and use the actual main save payload.
doc.getElementById('ak-tab-features').click();
const clean = [...doc.querySelectorAll('.ak-features-item')].find(n => n.textContent.includes('Clean interface'));
clean.click();
const nested = doc.getElementById(clean.getAttribute('aria-controls')).querySelectorAll('.ak-feature-options input');
assert(nested.length === 2 && nested[0].checked && nested[1].checked, 'nested defaults mount without losing values');
nested[0].checked = false; nested[0].dispatchEvent(new window.Event('change', { bubbles:true }));
const save = doc.querySelector('.ak-savebar .ak-btn--primary');
assert(save && !save.disabled, 'edits mark the form dirty');
save.click(); await tick();
const posted = requests.find(r => r.method === 'POST' && r.data && r.data.values);
assert(posted && posted.data.values.clean_chrome_hide_footer === false && posted.data.values.clean_chrome_hide_help === true, 'actual main save includes independent nested choices');
assert(doc.getElementById(clean.getAttribute('aria-controls')).querySelector('.ak-feature-pending').hidden, 'actual host success clears the feature pending badge');

if (errors.length) {
  console.error('FAIL\n' + errors.map(String).join('\n'));
  process.exit(1);
}
dom.window.close();
console.log('OK: settings SPA boots in jsdom; ' + tabs.length + ' tabs, ' + sectionTabs.length + ' design sections, edits mark dirty.');

})();
