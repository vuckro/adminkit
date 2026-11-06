// Real shared adapter: keyboard activation and the host's offscreen notice panel.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const source = fs.readFileSync(path.resolve(__dirname, '../../inc/integrations/plugins/funnel-builder/js/shared.js'), 'utf8');
const tick = () => new Promise(resolve => setTimeout(resolve, 60));
function setup(enabled = true) {
  const dom = new JSDOM(`<body class="adminkit ${enabled ? 'adminkit-funnelkit' : ''}">
    <button class="ak-nc-close" aria-label="Fermer les notifications"></button>
    <div class="bwf-announcements__icon"><span>15</span></div>
    <div class="bwf-announcements"><div class="wffn-side-panel">
      <div class="bwf-announcements__header-title">Notifications</div>
      <div class="bwf-announcements__header-close"><svg></svg></div>
      <a href="https://example.invalid/">Release notes</a>
    </div></div>
    <div class="bwf-funnel-modal"><div class="components-modal__header"><button id="host-close"><svg></svg></button><button id="header-save">Save</button><button class="has-icon" hidden aria-label="Fermer cette fenêtre"></button></div></div>
    <div class="bwf-dashboard-banner"><span class="bwf-link">Documentation</span></div>
  </body>`, {url:'https://test.invalid/wp-admin/', runScripts:'outside-only', pretendToBeVisual:true});
  const w = dom.window, d = w.document;
  const observers = [], Observer = w.MutationObserver;
  w.MutationObserver = class extends Observer { constructor(cb) { super(cb); observers.push(this); } };
  const dispose = () => { observers.forEach(observer => observer.disconnect()); dom.window.close(); };
  const opener = d.querySelector('.bwf-announcements__icon');
  const panel = d.querySelector('.wffn-side-panel');
  const close = d.querySelector('.bwf-announcements__header-close');
  const docs = d.querySelector('.bwf-link');
  const calls = {open:0, close:0, docs:0};
  opener.onclick = () => { calls.open++; panel.classList.add('is-open'); };
  close.onclick = () => { calls.close++; panel.classList.remove('is-open'); };
  docs.onclick = () => { calls.docs++; };
  const key = (el, value) => el.dispatchEvent(new w.KeyboardEvent('keydown', {key:value, bubbles:true, cancelable:true}));
  w.eval(source);
  return {dom, dispose, w, d, opener, panel, close, docs, calls, key};
}
(async () => {
  const t = setup();
  assert.equal(t.d.getElementById('host-close').getAttribute('aria-label'), 'Fermer cette fenêtre', 'The visible custom close reuses the hidden native close label');
  assert.equal(t.d.getElementById('header-save').hasAttribute('aria-label'), false, 'Text actions keep their own name');
  assert.equal(t.panel.inert, true, 'Closed host panel cannot receive keyboard focus');
  assert.equal(t.panel.getAttribute('aria-hidden'), 'true');
  assert.equal(t.opener.getAttribute('aria-label'), 'Notifications', 'Keep the host title');
  assert.equal(t.close.getAttribute('aria-label'), 'Fermer les notifications', 'Reuse localized close copy');
  t.opener.focus(); t.key(t.opener, 'Enter'); await tick();
  assert.equal(t.calls.open, 1, 'Keyboard delegates once to the native click handler');
  assert.equal(t.panel.inert, false);
  assert.equal(t.opener.getAttribute('aria-expanded'), 'true');
  assert.equal(t.d.activeElement, t.close);
  t.key(t.close, 'Escape'); await tick();
  assert.equal(t.calls.close, 1);
  assert.equal(t.panel.inert, true);
  assert.equal(t.d.activeElement, t.opener, 'Focus returns to the opener');
  t.docs.focus(); t.key(t.docs, ' ');
  assert.equal(t.calls.docs, 1, 'Space invokes the host documentation action once');
  await tick(); t.dispose();
  const off = setup(false);
  assert.equal(off.opener.hasAttribute('role'), false, 'No enhancement on unrelated admin pages');
  off.key(off.docs, 'Enter'); assert.equal(off.calls.docs, 0);
  off.dispose();
  console.log('OK: FunnelKit native keyboard actions and closed-panel focus boundaries.');
})().catch(error => { console.error(error); process.exit(1); });
