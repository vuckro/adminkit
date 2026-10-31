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
  // The main factory also imports settings directly; late edits must survive that request.
  const imported = new JSDOM('', { url: 'http://localhost/', runScripts: 'outside-only' });
  const iw = imported.window;
  const state = {}; let reader, resolveImport, importCalls = 0;
  iw.FileReader = class { constructor() { reader = this; } readAsText() {} };
  iw.AdminKitParts = { design: () => () => {}, features: () => () => {} };
  iw.eval(fs.readFileSync(path.resolve(__dirname, '../../assets/js/settings/main.js'), 'utf8'));
  const main = iw.AdminKitScreens.main({ D: { route: 'settings' }, I: {}, state,
    apiFetch: () => { importCalls++; return new Promise(resolve => { resolveImport = resolve; }); },
    el() {}, intro() {}, markDirty() {}, setStatus() {}, updateBar() {}
  });
  main.importSettings({});
  state.dirty = true; reader.result = '{"values":{}}'; reader.onload();
  assert.equal(importCalls, 0, 'edits while reading the import file prevent submission');
  state.dirty = false; main.importSettings({});
  reader.result = '{"values":{}}'; reader.onload();
  assert.equal(state.saving, true);
  state.dirty = true; resolveImport({}); await tick();
  assert.equal(state.saving, false);
  assert.equal(state.dirty, true, 'edits during import request remain pending without reload');
  imported.window.close();
  console.log('OK: hash endpoint, all drafts, partial failure/retry, immutable requests and edits during save.');
})().catch(error => { console.error(error); process.exitCode = 1; });
