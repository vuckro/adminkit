// Regression tests for the real settings host: independent drafts, failures and in-flight edits.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/settings.js'), 'utf8');
const tick = () => new Promise(resolve => setTimeout(resolve, 15));
function boot(hash = '', legacy = false) {
  const dom = new JSDOM('<div id="adminkit-app"></div>', { url: 'http://localhost/' + hash, runScripts: 'outside-only' });
  const w = dom.window;
  const calls = [], timers = [], values = { settings: 0, menu: 0, toolbar: 0 };
  const nativeSetTimeout = w.setTimeout.bind(w), nativeClearTimeout = w.clearTimeout.bind(w);
  w.setTimeout = (callback, delay) => { if (delay !== 600) return nativeSetTimeout(callback, delay); timers.push(callback); return -timers.length; };
  w.clearTimeout = id => { if (id < 0) timers[-id - 1] = null; else nativeClearTimeout(id); };
  w.AdminKitData = { screen: 'main', i18n: { save: 'Save', unsaved: 'Unsaved', saved: 'Saved', error: 'Error' } };
  w.wp = { apiFetch: request => new Promise((resolve, reject) => calls.push({ request, resolve, reject })) };
  w.AdminKitScreens = { main: context => {
    const surface = {
      tabs: Object.keys(values).map(scope => ({ id: scope, label: scope, build() {
        return context.el('section', { role: 'tabpanel' }, [context.el('button', { id: 'edit-' + scope, text: scope, onclick() { values[scope]++; context.markDirty(scope); } })]);
      } })),
      request(id) { return { path: id, data: { value: values[id] } }; }
    };
    if (!legacy) surface.requests = () => Object.fromEntries(Object.keys(values).map(scope => [scope, { path: scope, data: { value: values[scope] } }]));
    return surface;
  } };
  w.eval(source);
  const click = id => { assert.ok(w.document.getElementById(id), 'missing ' + id); w.document.getElementById(id).click(); };
  const save = () => w.document.querySelector('.ak-savebar button').click();
  const edit = async scope => { click('ak-tab-' + scope); await tick(); click('edit-' + scope); };
  return { dom, w, calls, timers, click, save, edit };
}
(async () => {
  const a = boot('#menu', true);
  a.click('edit-menu'); a.save(); await tick();
  assert.equal(a.calls[0].request.path, '/menu', 'initial hash must choose its own endpoint');
  a.calls[0].resolve({}); await tick(); a.dom.window.close();

  const b = boot();
  await b.edit('settings'); await b.edit('menu'); await b.edit('toolbar');
  b.save(); await tick();
  assert.equal(b.calls[0].request.path, '/menu');
  b.calls[0].resolve({}); await tick();
  assert.equal(b.calls[1].request.path, '/toolbar');
  b.calls[1].reject(new Error('database unavailable')); await tick();
  assert.equal(b.calls.length, 2, 'stop on failure');
  assert.equal(b.timers.filter(Boolean).length, 0, 'never reload and discard failed drafts');
  b.save(); await tick();
  assert.equal(b.calls[2].request.path, '/toolbar', 'successful drafts are not submitted again');
  b.calls[2].resolve({}); await tick();
  assert.equal(b.calls[3].request.path, '/settings', 'settings save last so disabling features cannot remove pending routes');
  b.calls[3].resolve({}); await tick();
  assert.equal(b.timers.filter(Boolean).length, 1, 'reload only after every draft saves');
  await b.edit('settings');
  assert.equal(b.timers.filter(Boolean).length, 0, 'new edit cancels scheduled reload');
  b.dom.window.close();

  const c = boot();
  await c.edit('settings'); c.save(); await tick();
  await c.edit('settings'); c.calls[0].resolve({}); await tick();
  assert.equal(c.calls[0].request.data.value, 1, 'request preserves original snapshot');
  assert.equal(c.timers.filter(Boolean).length, 0, 'in-flight edits stay unsaved');
  c.save(); await tick();
  assert.equal(c.calls[1].request.data.value, 2, 'next save includes new edit');
  c.calls[1].resolve({}); await tick(); c.dom.window.close();
  // Boot the actual main factory and host: imports lock all editing surfaces until reload.
  const imported = new JSDOM('<div id="adminkit-app"></div>', { url: 'http://localhost/', runScripts: 'outside-only' });
  const iw = imported.window;
  let reader, importCall, main, state;
  const importCalls = [];
  iw.FileReader = class { constructor() { reader = this; } readAsText() {} };
  iw.AdminKitData = { screen: 'main', route: 'settings', brandAccent: '#111111', appearance: { ui_font: 'geist' }, i18n: { error: 'Error' } };
  iw.AdminKitParts = {
    design: context => () => context.el('section', {}, [context.el('input', { id: 'edit-design' })]),
    features: context => () => context.el('section')
  };
  iw.wp = { apiFetch: request => new Promise((resolve, reject) => { importCall = { request, resolve, reject }; importCalls.push(importCall); }) };
  iw.eval(fs.readFileSync(path.resolve(__dirname, '../../assets/js/settings/main.js'), 'utf8'));
  const factory = iw.AdminKitScreens.main;
  iw.AdminKitScreens.main = context => { state = context.state; main = factory(context); return main; };
  iw.eval(source);
  const regions = () => [...iw.document.querySelectorAll('.ak-panels, .ak-tabs, .ak-actions')];
  main.importSettings({});
  state.dirty = true; reader.result = '{"_adminkit":"settings","values":{}}'; reader.onload();
  assert.equal(importCalls.length, 0, 'edits while reading the import file prevent submission');
  state.dirty = false; main.importSettings({});
  reader.result = '{"_adminkit":"settings","values":{"brand_accent":"#222222","ui_font":"inter"}}'; reader.onload();
  assert.equal(state.importing, true);
  assert.equal(state.saving, true);
  assert.equal(regions().length, 3);
  assert.ok(regions().every(region => region.inert), 'all editing surfaces lock during import');
  assert.equal(iw.document.querySelector('.ak-savebar button').disabled, true);
  assert.equal(iw.document.querySelector('.ak-savebar').inert, undefined, 'import status remains accessible');
  assert.equal(importCall.request.data.values.brand_accent, '#222222');
  importCall.reject(new Error('database unavailable')); await tick();
  assert.equal(state.importing, false);
  assert.equal(state.saving, false);
  assert.ok(regions().every(region => !region.inert), 'failed imports restore editing');
  assert.equal(main.requests().settings.data.values.brand_accent, '#111111', 'failure preserves the original draft');
  assert.equal(iw.document.querySelector('.ak-status').textContent, 'Error');
  main.importSettings({}); reader.result = '{"_adminkit":"settings","values":{"brand_accent":"#222222"}}'; reader.onload();
  // jsdom cannot navigate; a successful import must remain locked while awaiting reload.
  importCall.resolve({}); await tick();
  assert.equal(state.importing, true);
  assert.equal(state.saving, true);
  assert.equal(state.dirty, false);
  assert.ok(regions().every(region => region.inert), 'success cannot reopen stale controls before reload');
  imported.window.close();
  console.log('OK: hash endpoint, all drafts, partial failure/retry, immutable requests and edits during save.');
})().catch(error => { console.error(error); process.exitCode = 1; });
